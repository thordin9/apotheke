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
| `lifecycle_jobs` | One row per lifecycle job: kind, installation, `idempotency_key`, state (`paused`, `queued`, `running`, `done`, `failed`), current step, error code, who created and confirmed it (see [Lifecycle jobs](#lifecycle-jobs)) |

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

The key and the hosts are read only when the container starts: a change to `installation_routes` applies to a
running installation at its next start.

Rotating the Worker's `APP_KEY` secret changes `primary`'s key and every derived per-installation key at once. Nothing
re-encrypts the secrets other installations have already stored under their old key, so a rotation has to be planned
per installation first. Until the first-admin claim (C1) replaces it, all installations get the same Worker
`APP_PASSWORD`.

## Lifecycle jobs

Provision, suspend, resume, export, soft delete and purge are jobs in `lifecycle_jobs` (migration `0003`), run step by
step by `LifecycleWorkflow` (`edge/src/lifecycle-workflow.ts`) over the step functions in `edge/src/lifecycle.ts`.

- **Created paused.** A job has an `idempotency_key` of `<kind>:<installation_id>:<nonce>` (the nonce comes from the
  console form), so the same request twice returns the same job. It starts in state `paused` and runs only after an
  operator confirms it (`queued`); a purge is confirmed only with one of the installation's hosts typed back. Nothing
  creates, confirms or runs jobs on a schedule: no Cron, no automatic suspend and no automatic purge.
- **Idempotent steps.** Each step checks the state it would create before acting, so a retry or a second run changes
  nothing more. Status changes are plain `UPDATE`s on `installations` with one `operator_audit` row per job and action
  (installation id and action only).
- **Never `primary`.** Every function refuses the installation id `primary` and the DO name `primary`, whatever the
  status, and so do the `purge()` and `stopContainer()` methods on the Durable Objects.

| Job | Steps |
|---|---|
| provision | The installation row and at least one route exist (status `provisioning`); refuse if anything is stored under its R2 prefix; start the container with `BOOTSTRAP=1` and no `APP_PASSWORD`; create the first-admin claim; status `active` |
| suspend | Status `suspended`, drop cached routes, stop and destroy the container. No data is touched |
| resume | Status `active`, drop cached routes. The next request starts the container |
| export | The app's own backup, once per job, written under the installation's prefix; its key is kept on the job |
| delete | Soft delete: status `deleting` (routing answers 410), stop and destroy the container, export. The job needs a retention period in days (30 is planned; the final value is the owner's decision) and has no default |
| purge | Only after a soft delete. Abort unfinished multipart uploads that have a marker and delete their markers, then delete everything under `installations/<do_name>/` in pages of at most 1,000 keys (one page per step, so a retry carries on), then delete the `Installation` and container objects' storage, then status `deleted`. The row stays as a tombstone, so the DO names are never reused |

Not wired yet, and failing closed until they are: the `BOOTSTRAP=1` start and the claim (C1), the backup call into
the container, and the download link for an export (the console API, E6). Routes are cached only inside each
`apotheke-api` isolate for at most 30 seconds, so there is no shared cache to purge yet. The Workflow is deployed with
the operator Worker (D1), not with `apotheke-api`, whose configuration is unchanged.
