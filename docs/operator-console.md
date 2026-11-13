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

## Limits

The Worker enforces four plan limits for installations other than `primary` (`edge/src/limits.ts`). They come only
from `plan_limits` rows of the installation's subscription plan, which the console edits; no tier is built in. A key
without a row, or with no `hard_value`, is no limit, and an installation without a subscription has none. When
`soft_value` is empty the soft value is 80% of the hard one. The limits are read with the route in the same query and
cached with it (30 seconds per isolate). There is no `limit_overrides` table yet; temporary raises come with the
console API.

| Limit (`limit_key`) | Where | Soft | Hard |
|---|---|---|---|
| Requests per month (`requests_month`) | Counter in the container object | `Apotheke-Limit-Warning: requests_month` on responses | 429 "This Apotheke reached its monthly request limit. The owner can upgrade the plan." |
| Container active time per month (`container_hours_month`, hours) | Container object, start to stop | `Apotheke-Limit-Warning: container_hours_month` | 402 "This month's active time is used up", without waking the container |
| Request body (`import_file_bytes`) | Worker, before the body is read: `Content-Length`, or a streaming cap when there is none | none | 413 "File is larger than your plan allows (N MB)" |
| Stored bytes (`storage_bytes`) | `r2.internal` on a write and a multipart complete, against a counter in the `Installation` object | none yet | 507 to PHP, `code` `storage_full` |

- **Off switch.** The Worker var `LIMITS_ENABLED` (`"0"` in `edge/wrangler.jsonc`) turns everything off: each request
  is forwarded exactly as before, with no limits lookup and no counting. `"1"` turns enforcement on. The var is read
  by the Worker only and is not passed to the container.
- **`primary` is never limited**, whatever its plan or counters, in the Worker, the container object and `r2.internal`.
- **Months** are calendar months in UTC (`YYYY-MM`, as `usage_counters.period`). A new month starts from zero.
- **Active time** is measured as the inactivity timeout defines it: a run starts with a request that finds the
  container asleep and ends `SLEEP_AFTER_MS` after the last request. Once the month is used up, every request gets the
  402 page and the container sleeps after its timeout.
- **Stored bytes** count from when metering begins: files already stored before then are not counted until a later
  reconciliation. An overwrite counts the difference and a delete gives its bytes back. A multipart upload is refused
  at completion only when the store is already full, so one upload can go past the hard value by its own size.
  Concurrent writes near the limit can pass it by the bytes in flight.
- **Counters** are kept in memory in the owning object and written to its storage by an alarm at most 60 seconds after
  a change. `ApothekeContainer.usage()` returns the month's `requests` and
  `container_seconds` for the Cron that will copy them into `usage_counters` (not scheduled yet).
- **Not in the Worker:** item, photo and user ceilings and the `/limits` bridge are in the app (C1); the autofill
  budget is in the autofill service; an email to the operator at the soft active-time value comes later.

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
