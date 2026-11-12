# Operator console: control-plane data

Design note for the hosted service's operator console (managing installations, plans and limits, subscriptions,
usage and an audit log). This first step adds only the schema; nothing reads it yet, and routing still goes to the
one installation, `primary`.

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
| `installations` | One row per installation: `id`, `do_name` (lower-case letters, digits and `-`), `r2_prefix` (always `installations/<do_name>/`; neither ever changes, a trigger refuses it), lifecycle `status` (`provisioning`, `active`, `suspended`, `deleting`, `deleted`), owner contact, notes |
| `installation_routes` | Host (lower case, no port, no trailing dot) → installation; several hosts may point at one installation |
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

`edge/src/route.ts` looks up the request host (lower case, no port) in `installation_routes` and forwards to the
container named by the installation's `do_name`. A hit or a miss is cached in the Worker isolate for 30 seconds.

- No row, no `DB` binding, or a failed lookup: the request goes to `primary`, as before routing existed. The app's
  own `ALLOWED_HOSTS` check still applies.
- Status `provisioning` answers 503, `suspended` 402, `deleting` and `deleted` 410, without starting a container.
  This applies only to installations other than `primary`: `primary` is served as before whatever its row says.
  Suspending `primary` will be a separate, explicit operator flow later.
- Until per-installation storage is in place, an active route to any name other than `primary` answers 503, so no
  second installation can start on `primary`'s storage.

Limits are not read here. They come only from `plan_limits` rows, which the console edits; no tier is built in.
