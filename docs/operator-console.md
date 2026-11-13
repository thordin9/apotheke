# Operator console: control-plane data

Design note for the hosted service's operator console (managing installations, plans and limits, subscriptions,
usage and an audit log). The Worker routes each request by host through this schema (see [Routing](#routing)); the
console itself is not built yet.

## Source of truth

The D1 database `apotheke-api` (EU jurisdiction, bound as `DB`; see [ADR-002](adr/002-edge-storage.md)) is the
source of truth for installations. There is no other installations database and no sync adapter.

Subscriptions are set by hand by the operator: a plan, a status (`trial`, `active`, `past_due`, `cancelled`), and
optional trial and period end dates. There is no billing provider. If one is added later it gets its own columns or
side table and calls the same plan and status changes the console uses.

## Schema

Migrations live in [`edge/migrations/control/`](../edge/migrations/control/), numbered for
`wrangler d1 migrations apply` (`0001_…sql`, `0002_…sql`). They are additive only: a later migration adds tables or
columns and never drops or renames, so the previous Worker version keeps working. Applying them is part of the deploy
job, not of this repository's tests.

| Table | Purpose |
|---|---|
| `installations` | One row per installation: `id`, `do_name` (1-63 lower-case letters and digits in `-`-separated groups), `r2_prefix` (always `installations/<do_name>/`; neither ever changes: triggers refuse an update and a `REPLACE` over an existing installation), lifecycle `status` (`provisioning`, `active`, `suspended`, `deleting`, `deleted`), owner contact, notes |
| `installation_routes` | Host (lower case, no port, no empty label or label starting or ending with `-`) → installation; several hosts may point at one installation |
| `plans`, `plan_limits` | Plans and their soft and hard limits per key (`items`, `storage_bytes`, `photos`, `users`, `autofill_calls_month`, `requests_month`, `container_hours_month`, `sleep_after_ms`, `import_file_bytes`). No row for a key means no limit |
| `subscriptions` | One row per installation: plan, manual status, `trial_end`, `period_end`, notes |
| `usage_counters` | Per installation, month (`YYYY-MM`) and metric |
| `operator_audit` | Every operator change, control-plane fields only. Append-only: triggers refuse updates, deletes and inserts over an existing id (`INSERT OR REPLACE`) |

Three choices differ from the first design and were accepted for this schema: routes are keyed by host (not by
installation), `do_name` and `r2_prefix` live on `installations` (not on the route), and `subscriptions` replaces a
separate `installation_ext` table, since there is no other installations database to extend.

## `primary`

Migration `0002` registers the existing installation with the names it already has, so the same Durable Objects
and R2 objects are used and no data moves:

- id and `do_name` `primary` (`INSTALLATION_NAME` in `edge/src/installation.ts`, also the routing fallback in
  `edge/src/route.ts`);
- `r2_prefix` `installations/primary/` (`PREFIX` in `edge/src/r2.ts`);
- host `api.services.getapotheke.com` (`ALLOWED_HOSTS` in `edge/wrangler.jsonc`);
- plan `internal` (no limits), installation status `active`, subscription status `active`, no period end.

The schema holds no secrets: `primary`'s `APP_KEY` and other Worker secrets are unchanged.
`edge/tests/control-migrations.test.mjs` applies the migrations to an empty SQLite in file order and checks these
values against the Worker source.

## Routing

`edge/src/route.ts` looks up the request host (lower case, no port, trailing dots removed) in
`installation_routes` and forwards to the container named by the installation's `do_name`. Only the request URL's
host is used; `X-Forwarded-Host` is ignored. A hit or a miss is cached in the Worker isolate for 30 seconds (at most
1,000 hosts: expired entries are dropped first, then the oldest), so a status or route change can take up to 30
seconds to show.

- No row, no `DB` binding, or a failed lookup: the request goes to `primary`, as before routing existed. The app's
  own `ALLOWED_HOSTS` check still applies. A failed lookup (for example before the control migrations are applied)
  logs one line with the D1 error and is retried for that host after 5 seconds, not on every request.
- Status `provisioning` answers 503, `suspended` 402, `deleting` and `deleted` 410, without starting a container.
  This applies only to installations other than `primary`: `primary` is served as before whatever its row says.
  Suspending `primary` will be a separate, explicit operator flow later.
- An active installation is served by the container object named `do_name`.

## Per-installation storage

Each container object takes its installation from its own name (`ctx.id.name`, set by `getByName(do_name)`), never
from PHP. It passes `{ doName, prefix }` as props to the `d1.internal` and `r2.internal` handlers: the database is
the `Installation` object made by `installationStub(ns, doName)` from the EU-jurisdiction namespace, and every R2 key,
multipart marker and sweep stays under `installations/<do_name>/`. Missing or inconsistent props refuse storage, and
a container object without a name never starts in storage mode. For `primary` the names, the prefix, the marker keys
and the Worker secrets are the same as before. `edge/tests/installation-isolation.test.mjs` runs two installations
side by side and checks that no file, marker, sweep or database row crosses between them.

Limits are not read here. They come only from `plan_limits` rows, which the console edits; no tier is built in.

## Per-installation APP_KEY and ALLOWED_HOSTS

When a container starts, its object sets two values for its own installation:

- `APP_KEY`: `primary` gets the Worker's `APP_KEY` secret exactly as before, with no derivation, so its stored
  secrets keep opening. Any other installation gets HKDF-SHA256 over the secret's 32 key bytes (fixed salt
  `apotheke-installation-app-key-v1`, the installation's `do_name` as info), written as `base64:` and 44 base64
  characters, the form the app accepts. If the secret is not a 32-byte key, the other installation does not start.
- `ALLOWED_HOSTS`: `primary` keeps the Worker's `ALLOWED_HOSTS`. Any other installation gets its own hosts from
  `installation_routes`, comma-separated. With no hosts, or no readable control plane, it does not start.

No new Worker variables or secrets. `edge/tests/installation-secrets.test.mjs` pins `primary`'s values and checks the
derivation against an independent HKDF with fake keys.

## Hosted installations

`APOTHEKE_HOSTED=1` in the container's environment marks a hosted installation. Without it the app behaves exactly as
when self-hosted: no plan limits are read, nothing below is active, and `/claim` answers 404.

**Bridge reads.** The `Installation` object answers three read-only requests on `d1.internal`, next to the SQL bridge
and only in storage mode (`STORAGE`, `DB` and `INSTALLATION` bound). They follow the edge's storage mode, not
`APOTHEKE_HOSTED`, which is set in the container's environment and is not visible to the Worker:

- `GET /stats`: counts of items, photos, user accounts, and accounts signed in during the last 30 days.
- `GET /users`: id, name, role, status, created and last sign-in for each account. No password hashes or sessions.
- `GET /limits`: the plan and its `plan_limits` rows (`{key: {soft, hard}}`) for the object's own installation,
  looked up by its `do_name` through its subscription. 404 when the installation has no subscription, 503 when the
  control plane is not bound.

**Plan limits.** With `APOTHEKE_HOSTED`, the app reads `/limits` once per request when it needs them. The hard
values of `items`, `photos` and `users` cap adding items, photos and accounts: the count is checked inside the unit
of work that adds the rows, and the unit also takes a `plan-limit:<key>` key, so two adds at once cannot pass a
limit together. Edits, deletes and replacing a cover are not adds. `import_file_bytes` caps the Control Panel's
upload size settings (`max_upload_mb`, `attach_max_mb`); the autofill URL is set by the hosting side and cannot be
changed there. If the limits cannot be read, adds that need them are refused with 503. Limit values come only from
`plan_limits`; the app has no built-in tier numbers. Restoring a backup, undoing a delete and the photos copied by
Duplicate are not counted.

**First-admin claim.** A new installation starts with no account. `php spark accounts:claim-create` (run through the
bridge) prints a one-time token and stores only its SHA-256 and expiry (`HOSTED_CLAIM_HOURS`, default 72) as a
settings row outside the Control Panel's keys, so it is never exported or backed up. A new claim replaces the old one.
The link is `https://<host>/claim#<token>`: the page reads the token from the fragment, and the owner picks a user
name and password and becomes an active `admin`. The claim row is deleted in the same unit as the account insert, so
a link works once. The claim works only while the installation has no active `admin`: that is checked in the same
unit, which takes the `active-admins` key like every unit that adds an active admin. Otherwise the link is refused
(410) and the claim row is removed. With `APP_PASSWORD` set, the app still creates the account `admin` from it when
there is no account, as when self-hosted, so a claim on that installation is refused. Registration stays closed until
the first account exists, and sign-in cannot be turned off.
