# Operator console: control-plane data

Design note for the hosted service's operator console (managing installations, plans and limits, subscriptions,
usage and an audit log). The Worker routes each request by host through this schema (see [Routing](#routing)). The
console's Worker, `apotheke-ops`, is in `edge/ops/` (see [Console API](#console-api)); most of its API is not built
yet.

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
job, not of this repository's tests. The one exception is `0004`: it rebuilds `plan_limits` with the same columns and
rows so its key `CHECK` also allows `photos_per_item` (SQLite cannot change a `CHECK` in place). It is safe to apply
again, and the tests pin it by sha256 like the others. The tests also fail when a migration drops a `plan_limits` key
or gives a foreign key an `ON DELETE` or `ON UPDATE` action.

Applied migrations are never edited, comments included, so two header comments stay as written. `0001`'s "Additive
only: … never drop or rename" predates the `0004` exception above. `0004`'s "the app's max_photos setting is capped by
it" describes a later app change: nothing caps `max_photos` with `photos_per_item` yet.

| Table | Purpose |
|---|---|
| `installations` | One row per installation: `id`, `do_name` (1-63 lower-case letters and digits in `-`-separated groups), `r2_prefix` (always `installations/<do_name>/`; neither ever changes: triggers refuse an update and a `REPLACE` over an existing installation), lifecycle `status` (`provisioning`, `active`, `suspended`, `deleting`, `deleted`), owner contact, notes |
| `installation_routes` | Host (lower case, no port, no empty label or label starting or ending with `-`) → installation; several hosts may point at one installation |
| `plans`, `plan_limits` | Plans and their soft and hard limits per key (`items`, `storage_bytes`, `photos`, `users`, `autofill_calls_month`, `requests_month`, `container_hours_month`, `sleep_after_ms`, `import_file_bytes`, `photos_per_item`). No row for a key means no limit. `photos` is the total across all items; `photos_per_item` is the most photos one item may have (no plan has a value yet; the Worker does not enforce it) |
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

## Limits

The Worker enforces four plan limits for installations other than `primary` (`edge/src/limits.ts`). They come only
from `plan_limits` rows of the installation's subscription plan, which the console edits; no tier is built in. A key
without a row, or with no `hard_value`, is no limit, and an installation without a subscription has none. When
`soft_value` is empty the soft value is 80% of the hard one. The limits are read with the route in the same query and
cached with it (30 seconds per isolate). A live `limit_overrides` row for the same installation and key
(`expires_at` empty or a well-formed timestamp in the future) overrides (raises or lowers) that key's hard value; the soft value is then 80% of the new hard.
A non-integer `hard_value` is ignored so the plan cap stays. A malformed `expires_at` is treated as expired; NULL never expires. Expired rows are ignored and do not add a key the plan does not already cap. `primary` is not given overrides, and
when limits are off the route lookup does not read them. The console API that grants or withdraws a row is separate.

| Limit (`limit_key`) | Where | Soft | Hard |
|---|---|---|---|
| Requests per month (`requests_month`) | Counter in the container object | `Apotheke-Limit-Warning: requests_month` on responses | 429 "This Apotheke reached its monthly request limit. The owner can upgrade the plan." |
| Container active time per month (`container_hours_month`, hours) | Container object, start to stop | `Apotheke-Limit-Warning: container_hours_month` | 402 "This month's active time is used up", without waking the container |
| Request body (`import_file_bytes`) | Worker: a valid `Content-Length` over the hard value before the body is read, and a streaming cap on every body | none | 413 "File is larger than your plan allows (N MB)" |
| Stored bytes (`storage_bytes`) | `r2.internal` on a write and a multipart complete, against a counter in the `Installation` object, recounted from R2 | none yet | 507 to PHP, `code` `storage_full` |

- **Off switch.** The Worker var `LIMITS_ENABLED` (`"0"` in `edge/wrangler.jsonc`) turns everything off: each request
  is forwarded exactly as before, with no limits lookup and no counting. `"1"` turns enforcement on. The var is read
  by the Worker only and is not passed to the container.
- **`primary` is never limited**, whatever its plan or counters, in the Worker, the container object and `r2.internal`.
- **Months** are calendar months in UTC (`YYYY-MM`, as `usage_counters.period`). A new month starts from zero.
- **Active time** is measured as the inactivity timeout defines it: a run starts with a request that finds the
  container asleep and ends `SLEEP_AFTER_MS` after the last request. Once the month is used up, every request gets the
  402 page and the container sleeps after its timeout.
- **Stored bytes** are counted on each write: an overwrite counts the difference and a delete gives its bytes back. A
  multipart upload is refused at completion only when the store is already full, so one upload can go past the hard
  value by its own size. Concurrent writes near the limit can pass it by the bytes in flight.
- **Recount.** Concurrent writes to one key can count the same bytes twice, and files stored before metering began are
  not counted on write. The `Installation` object therefore resets the counter to the total listed under the
  installation's R2 prefix (paged, upload markers not counted): in its alarm when the last recount is over a day old,
  and before a 507 when it is over an hour old. The alarm is set only by a change, so an idle installation is not
  listed. Writes during a listing can leave the counter off by their own size until the next recount. A failed
  listing leaves the counter as it was and counts as a recount for those waits, so a listing that keeps failing is
  retried at most once an hour before a 507 and once a day in the alarm. `primary` is never listed.
- **Headers.** `X-Apotheke-Limits` and `Apotheke-Limit-Warning` sent by a client are removed before a request reaches
  the app, for every installation and whatever `LIMITS_ENABLED` is. The only `Apotheke-Limit-Warning` on a response
  is the one the Worker sets. `X-Apotheke-Limits` is only for the Worker and the container object: the container
  object removes the Worker's own copy too, so the app never sees it and cannot read limits from it.
- **Preflights.** Every request counts toward `requests_month`, `OPTIONS` included. A real CORS preflight (`OPTIONS`
  under `/api` with `Access-Control-Request-Method` and an `Origin` in `CORS_ALLOWED_ORIGINS`) is never refused by the
  request or active-time limits, so a browser can read the refusal of the request that follows. In a used-up month the
  container object answers it 204 with the CORS headers, without waking the container and without counting it;
  otherwise it is counted and forwarded like any other request. Any other `OPTIONS` is counted and refused like a
  `GET`.
- **`/api` paths.** The Worker reads the path as the app does: the URL's path with each `%XX` decoded once
  (`rawurldecode()`), so `/%61pi/…` and `/api%2F…` are under `/api` and `/%2561pi/…` is not. Two rare spellings
  differ, because PHP's `parse_url()` reads them otherwise: a path with a `:` and up to five digits as a segment (such
  as `/api/a:1`) is under `/api` in the Worker but `/` in the app, and a path starting with `//` is never under `/api`
  in the Worker. Both are still counted; only a real preflight's exemption and the CORS headers on a refusal depend on
  the path.
- **Content-Length.** Only digits count as a valid `Content-Length`; anything else is treated as missing. A body
  longer or shorter than a valid one gets 400 "Request body does not match Content-Length" instead of the app's
  answer. The app may already have started on the part of the body it was sent, and an answer it sends before the
  mismatch is seen is passed on as it is.
- **CORS.** The 400, 413, 429 and 402 answers and the 204 preflight under `/api` carry the same CORS headers the app
  sends for an origin in `CORS_ALLOWED_ORIGINS` (exact https origins, `Vary: Origin`), so the app on another origin can
  read them. As in the app, only `/api` paths get them (not `/apix` or `/index.php/api`), and each listed origin is
  trimmed of the same whitespace PHP's `trim()` removes. The app's
  `Access-Control-Expose-Headers` includes `Apotheke-Limit-Warning`.
- **Counters** are kept in memory in the owning object and written to its storage by an alarm at most 60 seconds after
  a change. `ApothekeContainer.usage()` returns the month's `requests` and
  `container_seconds` for the Cron that will copy them into `usage_counters` (not scheduled yet).
- **Not in the Worker:** item, photo and user ceilings and the `/limits` bridge are in the app (C1); the autofill
  budget is counted in the autofill service (see [Autofill](#autofill)); an email to the operator at the soft
  active-time value comes later.

### Autofill

With `LIMITS_ENABLED` "1", the container object of an installation other than primary binds `http://autofill.internal`
to `AutofillOutbound` (`edge/src/autofill.ts`). When `APOTHEKE_HOSTED` is set and `autofill_url` is not, the installation uses
`http://autofill.internal`. An explicit `autofill_url` is kept. The Worker forwards `GET /v1/info` and `POST /v1/suggest` (anything else is 404) to the
autofill service's `HostedAutofill` entrypoint through the `AUTOFILL` service binding, so no bearer token is used and
none is given to the container, stored in D1 or set as a var. Only the Worker sets the request headers the service
reads:

- `X-Apotheke-Installation`: the container object's own name, never anything from PHP.
- `X-Apotheke-Autofill-Limit` and `X-Apotheke-Autofill-Soft`: the hard and soft `autofill_calls_month` from
  `plan_limits` (loaded with the route), or neither when the plan has no such row. Hard is rounded down and soft up
  (80% of a hard 7 is sent as 6); a negative value is sent as 0.

Every other header from the container (`Authorization`, `Cookie`, any installation or limit header) is dropped; only
`Content-Type`, `Content-Length` and `Accept` are kept. The answer is 503 without calling the service when limits are
off, for primary, or when the `AUTOFILL` binding is not on the Worker. Primary is not wired. `edge/wrangler.jsonc`
declares the binding (`"services": [{ "binding": "AUTOFILL", "service": "apotheke-autofill", "entrypoint":
"HostedAutofill" }]`, that is `AUTOFILL` -> `apotheke-autofill#HostedAutofill`). `LIMITS_ENABLED` stays "0" on prod, so
until it is "1" every hosted autofill call still answers 503 without calling the service.

`HostedAutofill` has no route: only a Worker on the same Cloudflare account can bind to it, so the account is the
trust boundary for the installation and limit headers it reads.

Turning it on, in this order:

1. Deploy the autofill service with `HostedAutofill` (apotheke-autofill-service#42).
2. Deploy this Worker with `AutofillOutbound` ([#327](https://github.com/thordin9/apotheke/pull/327)).
3. Deploy this Worker with the `AUTOFILL` service binding above ([#329](https://github.com/thordin9/apotheke/pull/329)).
4. A hosted installation with no `autofill_url` uses `http://autofill.internal`; an explicit value is kept.
5. Set `LIMITS_ENABLED` to "1".

## Per-installation APP_KEY and ALLOWED_HOSTS

When a container starts, its object sets two values for its own installation:

- `APP_KEY`: `primary` gets the Worker's `APP_KEY` secret exactly as before, with no derivation, so its stored
  secrets keep opening. Any other installation gets HKDF-SHA256 over the secret's 32 key bytes (fixed salt
  `apotheke-installation-app-key-v1`, the installation's `do_name` as info), written as `base64:` and 44 base64
  characters, the form the app accepts. If the secret is not a 32-byte key, the other installation does not start.
- `ALLOWED_HOSTS`: `primary` keeps the Worker's `ALLOWED_HOSTS`. Any other installation gets its own hosts from
  `installation_routes`, comma-separated. With no hosts, or no readable control plane, it does not start. It also
  does not start unless its status, read when the container starts, is `provisioning` or `active` (`primary` is
  never gated). The one exception is a lifecycle job's backup (see [Lifecycle jobs](#lifecycle-jobs)), which may also
  start a `suspended` or `deleting` installation. A public request to a container started that way is forwarded only
  after the status is read again and is `provisioning` or `active`; otherwise it gets 503. If the container object
  restarts while the container is still running, a public request for any installation other than `primary` goes
  through that check again.

No new Worker variables or secrets. `edge/tests/installation-secrets.test.mjs` pins `primary`'s values and checks the
derivation against an independent HKDF with fake keys.

The key and the hosts are read only when the container starts: a change to `installation_routes` applies to a
running installation at its next start.

Rotating the Worker's `APP_KEY` secret changes `primary`'s key and every derived per-installation key at once. Nothing
re-encrypts the secrets other installations have already stored under their old key, so a rotation has to be planned
per installation first.

## Hosted installation env

`primary` gets the Worker's `APP_PASSWORD` and the same container env as before, with no new variables. Any other
installation gets no `APP_PASSWORD`; it gets `APOTHEKE_HOSTED=1` and `APOTHEKE_INSTALLATION` set to its own `do_name`,
so the app starts without a shared password and its first admin comes from the first-admin claim (C1). Both values are
set only from the container object's own name, never from a request. `APOTHEKE_INSTALLATION` alone does not mark an
installation as hosted. With `APOTHEKE_HOSTED`, `APP_PASSWORD` is optional
(see [Hosted installations](#hosted-installations)), so a hosted container can start without one. Non-primary
installations started before this change hold an admin seeded from the shared `APP_PASSWORD`, and a running
container keeps its old env until it restarts, so restart it and then run `php spark accounts:hosted-reset-admins`
before a tenant gets access. That command removes existing admin accounts, their session records and any first-admin
claim; non-admin accounts are left as they are.

The production edge container app (`edge/wrangler.jsonc`) has `max_instances` 1, which only fits `primary`. Before
onboarding a real hosted tenant, raise that value (a production change; it needs the owner's approval). This
document does not change the setting.

## Lifecycle jobs

Provision, suspend, resume, export, soft delete and purge are jobs in `lifecycle_jobs` (migration `0003`), run step by
step by `LifecycleWorkflow` (`edge/src/lifecycle-workflow.ts`) over the step functions in `edge/src/lifecycle.ts`.

- **Created paused.** A job has an `idempotency_key` of `<kind>:<installation_id>:<nonce>` (the nonce comes from the
  console form), so the same request twice returns the same job. It starts in state `paused` and runs only after an
  operator confirms it (`queued`); a purge is confirmed only with one of the installation's hosts typed back exactly.
  Nothing creates, confirms or runs jobs on a schedule: no Cron, no automatic suspend and no automatic purge.
- **Idempotent steps.** Each step checks the state it would create before acting, so a retry or a second run changes
  nothing more. Status changes are plain `UPDATE`s on `installations` with one `operator_audit` row per job and action
  (installation id and action only). A refusal (wrong status, `primary`, retention not over, not wired yet) is a
  `NonRetryableError`, so the Workflow fails the job at once; an R2 call that did not finish is retried.
- **Never `primary`.** Every function refuses the installation id `primary` and the DO name `primary`, whatever the
  status, and so do the `purge()` and `stopContainer()` methods on the Durable Objects.

| Job | Steps |
|---|---|
| provision | The installation row and at least one route exist (status `provisioning`); refuse if anything is stored under its R2 prefix; start the container with `BOOTSTRAP=1` and no `APP_PASSWORD`; create the first-admin claim; status `active` |
| suspend | Status `suspended`, drop cached routes, stop and destroy the container. No data is touched. Routing can serve the cached `active` route for up to 30 seconds, but the container no longer starts (see [Per-installation APP_KEY and ALLOWED_HOSTS](#per-installation-app_key-and-allowed_hosts)) |
| resume | Status `active`, drop cached routes. The next request starts the container |
| export | The app's own backup, once per job, written by the app under the installation's prefix (`installations/<do_name>/`, in its backups folder); its key is kept on the job. The container object asks its container for it (`ApothekeContainer.opsBackup`, see [Backup for a lifecycle job](#backup-for-a-lifecycle-job)), starting it if needed, also while `suspended`. Then, unless the installation is `active`, the container is stopped again, including when the backup fails the job |
| delete | Soft delete: status `deleting` (routing answers 410 once the cached route expires, within 30 seconds; the container no longer starts), stop and destroy the container, export (which starts the container for the backup only), stop the container again, including when the backup fails the job. The job needs a retention period in days (30 is planned; the final value is the owner's decision) and has no default. The export stays under the prefix until a purge, which cannot run before the retention is over |
| purge | Only after a soft delete that finished (its export made) and whose retention is over: now is at least the time that delete job finished plus its retention days. Before then, or after a delete that failed, creating, confirming and every step of a purge are refused and change nothing. There is no immediate purge. Then: abort unfinished multipart uploads that have a marker and delete their markers, then delete everything under `installations/<do_name>/` in pages of at most 1,000 keys (one page per step, so a retry carries on), then delete the `Installation` and container objects' storage, then status `deleted`. The row stays as a tombstone, so the DO names are never reused |

Not wired yet, and failing closed until they are: the `BOOTSTRAP=1` start and the claim (C1), and the download link
for an export (the console API, E6).

**Backup step.** The Workflow calls `opsBackup(jobId)` on the installation's container object, which signs the request
with the installation's `APP_KEY` (`opsBackupRequest`) and sends it to the container's port, as the readiness probe
does, never through the public forward. `APP_KEY` stays in `apotheke-api`; the Workflow's environment has none. The
recorded key is `installations/<do_name>/` plus the answer's relative key, refused (`bad_export`) unless that key is
exactly `backups/<id>/manifest.json` (the id is the stored backup's, `YYYYMMDD-HHMMSS` and six hex digits) and is for
the same job. A future download link must use that key as stored and must not decode it. A refused installation, one
that cannot start (including `deleted`), or a missing `APP_KEY` fails the job at once. 409, 5xx other than 503, and a
call that did not finish are retried; 400, 401, 404, 410 and 503 fail the job as `backup_<status>`. The two export
steps (`export.backup`, `delete.export`) have a 20-minute timeout and up to 10 retries 3 minutes apart, so a retry that
gets 409 while the first call still runs (up to 900 seconds) keeps waiting. If the backup fails the job, including
after the last of those retries, `export.stop` or `delete.export.stop` still runs, so a container started for a
suspended or deleting installation is not left running. The job records the backup's error code even after the
Workflow step has turned the error into a plain Error. The backup endpoint answers only with `APOTHEKE_HOSTED`,
which the container object sets for installations other than `primary` (see
[Hosted installation env](#hosted-installation-env)). Routes are cached only
inside each `apotheke-api` isolate for at most 30 seconds, so there is no shared cache to purge yet. The Workflow is
deployed with the operator Worker (D1), not with `apotheke-api`, whose configuration is unchanged.

A purge takes one Workflow step per page of up to 1,000 keys (plus one per 100 unfinished uploads). The Workflow's
step limit per instance therefore caps one purge at about 10 million objects (about 1 million on the Free plan's
1,024 steps). Past the limit the instance fails without reaching its last step, and the job is left in `running`.

## Hosted installations

`APOTHEKE_HOSTED=1` in the container's environment marks a hosted installation. Without it the app behaves exactly as
when self-hosted: no plan limits are read, nothing below is active, and `/claim` answers 404.

**Bridge reads.** The `Installation` object answers three read-only requests on `d1.internal`, next to the SQL bridge
and only in storage mode (`STORAGE`, `DB` and `INSTALLATION` bound). They follow the edge's storage mode, not
`APOTHEKE_HOSTED`, which is set in the container's environment and is not visible to the Worker:

- `GET /stats`: counts of items, photos, user accounts, and accounts signed in during the last 30 days.
- `GET /users`: id, name, role, status, created and last sign-in for each account. No password hashes or sessions.
- `GET /limits`: the plan and its limits (`{key: {soft, hard}}`) for the object's own installation, looked up by its
  `do_name` through its subscription and read as the Worker reads them (`edge/src/limits.ts`, see [Limits](#limits)):
  only keys with a hard value, soft 80% of hard when it has none. The app reads a fractional soft value as the next
  whole number. A value that is negative, not finite or beyond the largest whole number makes the answer unreadable,
  like any other bad value. 404 when the installation has no subscription, 503 when the control plane is not bound.

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
While an active `admin` exists, `accounts:claim-create` makes no claim and leaves any claim row as it is: it prints
`An active admin already exists, so no claim was made.` and exits 1. It checks this in a unit with the `active-admins`
key, like the claim itself. `php spark accounts:hosted-reset-admins` removes existing admin accounts (pending and
active), their session records and any first-admin claim, in a unit with the same key, so the claim can be used;
non-admin accounts are left as they are.
The link is `https://<host>/claim#<token>`: the page reads the token from the fragment, and the owner picks a user
name and password and becomes an active `admin`. The claim row is deleted in the same unit as the account insert, so
a link works once. The claim works only while the installation has no active `admin`: that is checked in the same
unit, which takes the `active-admins` key like every unit that adds an active admin. Once an active `admin` exists,
every claim request gets the same 410 answer, whatever its token, and the claim row is removed. Registration stays
closed until the first account exists, and sign-in cannot be turned off.

**`APP_PASSWORD` is optional.** With `APOTHEKE_HOSTED` and no `APP_PASSWORD`, the container starts (the start-up
check `auth status` answers on once the database answers), the first migration turns sign-in on and creates no
account, and the first-admin claim makes the first admin. With `APOTHEKE_HOSTED` and `APP_PASSWORD` set, the first
start creates the account `admin` from it exactly as on a self-hosted install, so the claim is not the way to the first
admin there, and `accounts:claim-create` refuses. `APOTHEKE_INSTALLATION` alone changes nothing. Without `APOTHEKE_HOSTED`, a missing `APP_PASSWORD` on a new
install still stops the start as before ([Deployment and operations](deployment-and-operations.md)).

## Claim and admin reset from the operator console

The apotheke-api Worker exports a named entrypoint `Ops` (`edge/src/index.ts`, `edge/src/ops-accounts.ts`) for the
operator Worker's service binding. It has no route, and this Worker declares no binding to it; `apotheke-ops`
(`edge/ops/wrangler.jsonc`) binds it as `OPS` (see [Console API](#console-api)).

- **`claimCreate(installation)`** and **`resetAdmins(installation)`** take the installation's id or `do_name`. The
  installation is read from `installations` in `DB`. `primary` is refused before the lookup and again by its row;
  an unknown name, a name that is one installation's id and another's `do_name`, and an installation that is not
  `provisioning` or `active` are refused before its container object is called.
- The container object (`ApothekeContainer.opsAccounts`) refuses `primary` and an unnamed object before any network
  call, starts the container only as a public request would, checks the status again when it is already running, and
  sends `POST /internal/accounts/claim` or `/internal/accounts/reset-admins` to `ops.internal` on the container's port,
  body `{}`, signed like the backup request below with the installation's own `APP_KEY`.
- **Results:** `{ok:true, token, expires_at}` for a claim, `{ok:true}` for a reset, otherwise `{ok:false, code,
  status?}`. Codes: `active_admin_exists` (409 on a claim), `unauthorized` (401), `not_hosted` (404),
  `accounts_failed` (500), `not_configured` (503 or no `DB`), `http_<status>` for any other status, `bad_answer` for a
  200 of the wrong shape, `bad_action` (an action other than `claim` or `reset-admins`), `refused`,
  `unknown_installation`, `ambiguous_installation`, `not_startable`, `bad_app_key`, `unavailable` (the container did
  not start) and `unreachable` (the request did not finish). The claim token is only in the result: it is not logged
  and not put in an error.

## Backup for a lifecycle job

Export and soft delete lifecycle jobs (operator console E4) need the app's own backup, once per job. With
`APOTHEKE_HOSTED`, the container answers `POST /internal/backup` for that, on the host `ops.internal` only. The
Worker never routes that host, so the request comes only from the installation's container object through the
container's port (`edge/src/ops-signature.ts`, `opsBackupRequest`). A request with the Worker's forwarding headers
(`X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Forwarded-For`, `X-Apotheke-Client-IP`), another host or another path is
not this endpoint and gets the usual 404. Self-hosted, it doesn't exist.

- **Request:** body `{"job_id":"<id>"}` (letters, digits, `_` and `-`, at most 64), header
  `X-Apotheke-Ops-Signature: t=<unix seconds>,v1=<hex>`. The key is HMAC-SHA256 of `apotheke-ops-v1` under the
  installation's `APP_KEY` bytes; `v1` is HMAC-SHA256 under that key of `POST`, the path, `t` and the body's SHA-256
  (hex), joined by newlines. It is accepted within 300 seconds of the container's clock.
- **Answer:** 200
  `{"ok":true,"created":…,"backup":{"job_id","id","key","prefix","size","sha256","parts","files","created_at"}}`.
  `key` is the backup's manifest, `backups/<id>/manifest.json`, relative to the installation's storage; the Worker
  adds `installations/<do_name>/`. A future download link must use that key as stored and must not decode it. Every
  object of the backup is under `prefix`. `files` lists the parts in order
  (`{"key":"part-00001","size":…,"sha256":…}`, keys relative to `prefix`), as the manifest does; the parts joined in
  order are the zip with `size` and `sha256`. Errors are `{"ok":false,"code":…}`: 400 `bad_request`,
  401 `unauthorized`, 405, 409 `backup_in_progress` (retry later), 410 `backup_gone`, 500 `backup_failed`,
  503 `not_configured`.
- **Once per job:** the same job id returns the same backup with `created: false`. The job is recorded in the
  settings row `ops_backup_job:<id>` (outside the Control Panel's keys), claimed in a unit of work keyed by that row,
  so two requests for one job never make two backups. A claim left by a request that stopped is taken over after
  two hours. A backup still running stops before its next part or its manifest once its claim is 1 h 50 min old, or
  as soon as another request has taken the claim, and keeps nothing; a backup stored after its claim was taken is
  deleted again. So a takeover never overlaps a backup that is still writing. The backup is a stored backup of kind
  `export`; `backup.keep` doesn't prune exports.
- **One snapshot:** the catalog is dumped inside one read transaction (one `REPEATABLE READ, READ ONLY` snapshot on
  PostgreSQL, one read transaction on SQLite), so every table comes from the same moment. The hosted database reads
  in pages without one.
- **Left-over parts:** parts under `backups/<id>/` with no manifest a day after the backup's id time (a backup that
  stopped) are deleted when the next backup is stored. A folder with a manifest is never touched by this.

## Console API

`apotheke-ops` (`edge/ops/`) serves the console (`ops/console`, built to `ops/console/dist` and served as Workers
static assets) and `/api/ops/v1` on its own host, behind Cloudflare Access. It has no routes, no `workers.dev` and no
preview URLs in this repository: the host and the Access application are set up outside it, and the deploy is off
until it is switched on (see [Deploying the console](#deploying-the-console)). Its bindings are the control-plane D1
`DB` (the same database as apotheke-api's), `OPS`, apotheke-api's `Ops` entrypoint (see
[Claim and admin reset from the operator console](#claim-and-admin-reset-from-the-operator-console)), `AUTOFILL`, the
autofill Worker's `HostedAutofill` entrypoint (for the lifecycle Workflow's `purge.autofill` step), and `ASSETS`; it
has no R2 binding. Calls are same-origin. The credential is the Access cookie. There is no app session. The console's API base
defaults to same-origin `/api/ops/v1` (`<meta name="ops-api-base">` can set another). Responses are JSON unless a
download says otherwise.

Every request, the console's files included, goes through the Worker's script (`edge/ops/src/index.ts`) in this order:

1. While `ACCESS_TEAM_DOMAIN` (`<team>.cloudflareaccess.com`), `ACCESS_AUD` (the Access application's audience) or
   `OPS_ALLOWED_EMAILS` (comma-separated emails, any case) is empty, every request is 503 `not_configured`. All three
   are empty in `edge/ops/wrangler.jsonc`; the deploy sets them.
2. `OPTIONS` is 405. No answer carries CORS headers.
3. The Access token (the `Cf-Access-Jwt-Assertion` header, or the `CF_Authorization` cookie when the header is
   missing or empty) must be RS256-signed by one of the team's keys (read from
   `https://<team>/cdn-cgi/access/certs` and kept for five minutes; a token with an unknown key reads them again, at
   most every 30 seconds), with issuer `https://<team>`, the configured audience, and an `exp` in the future;
   `nbf` and `iat`, when present, may be up to 60 seconds in the future (clock skew); `exp` has no skew. Otherwise 401
   `unauthorized`. The operator is the token's `email` (lower case); a token without one (a service token), or with
   an email that is not on `OPS_ALLOWED_EMAILS`, is 403 `forbidden`. If the keys cannot be read (an answer other than 200, or no full answer within 5 seconds): 503
   `access_unavailable`, and for the next 10 seconds every request gets that answer without fetching the keys
   again.
4. While `OPS_WRITES_ENABLED` is not exactly `"1"`, every write (`POST`, `PUT`, `PATCH`, `DELETE`) is 403
   `writes_disabled`. It is `"0"` in `edge/ops/wrangler.jsonc`: the console ships read-only, and writes are turned on
   only once they have passed QA.
5. A write needs `X-CSRF: 1` and an `Origin` equal to the request's own origin
   (scheme and host); otherwise 403 `csrf`. There is no CSRF token. In the console, `csrfHeaders()` is the only place
   that header is set, so a real token can replace the fixed value later.
6. `/api/ops/v1/…` goes to the API (`edge/ops/src/api.ts`); any other `/api/` path is 404 `not_found`; everything
   else is the console's files. An unknown API path is 404 and a known one with another method 405, before any body
   is read.
7. Only then is a write's body read: at most 4 KiB. A larger `Content-Length` is not read at all; without one,
   reading stops as soon as more than 4 KiB have arrived. Either is 422 `invalid_body`.

Every answer is `Cache-Control: no-store` and carries `X-Request-Id`, which is also the `request_id` of any audit row
it writes. Errors are `{"ok":false,"code":…}`, sometimes with a `detail`. The console types these failures: 401 (the
Access session ended; the screen says to reload and sign in), 403 (CSRF, Origin, a disallowed identity, or
`primary`), 404, 409, 410 (the installation has been deleted), 422, 503 (not configured, or the container cannot be
reached), and a network failure. A known path with another method is 405 `method_not_allowed` with `Allow`.

apotheke-api never answers `/api/ops/`: on its hosts that path is routed to the installation like any other.

### Audit

Every console action writes its D1 change and its `operator_audit` row (operator, action, installation,
`before_json`, `after_json`, `request_id`) in one D1 batch, so neither is kept without the other
(`edge/ops/src/audit.ts`). An action with no D1 change (a claim, an admin reset) writes the audit row alone, after
the action succeeded. A claim token or link is never written to D1, an audit row or a log; the helper refuses a
`token` or `link` key.

### Implemented

- `GET /config` returns `{"ok":true,"provisioning_enabled":…,"limits_enabled":…,"writes_enabled":…}`:
  `OPS_PROVISIONING`, `LIMITS_ENABLED` and `OPS_WRITES_ENABLED` of `apotheke-ops` equal to `"1"`. All three are `"0"`
  in `edge/ops/wrangler.jsonc` and on prod. The console reads it once at startup.
- `POST /installations/:id/claim` with `{"confirm_host":…}` returns `{"ok":true,"link":…,"expires_at":…}` once. The
  link is `https://<confirm_host>/claim#<token>`, built by `apotheke-ops` from the token `claimCreate` returned. The
  console shows the link once and does not store it. Audit action `installation.claim`, `after_json`
  `{"host","expires_at"}`.
- `POST /installations/:id/admin-reset` with `{"confirm_host":…}` returns `{"ok":true}`. Audit action
  `installation.admin_reset`, `after_json` `{"host"}`.

Both actions take the installation's `id` and check, in this order: `primary` 403 `primary` (before any lookup and
again by its row); unknown 404 `not_found`; `deleted` 410 `gone`; any status other than `provisioning` or `active` 409
`not_startable`; no host in `installation_routes` 409 `no_host`; `confirm_host` (trimmed, lower case) not one of the
installation's hosts 422 `confirm_host`. A body that is not a JSON object of at most 4 KiB is 422 `invalid_body`. Then
`apotheke-api`'s `Ops` entrypoint is called; its refusals are mapped: `active_admin_exists` 409
`active_admin_exists`, `refused` 403 `primary`, `unknown_installation` 404 `not_found`, `ambiguous_installation` 409
`ambiguous_installation`, `not_startable` 409 `not_startable`, and every other code (the container did not start or
answer, is not hosted, gave a bad answer) 503 `unavailable` with that code as `detail`. Nothing is audited for a
refusal. If the audit row cannot be written after a successful claim, the answer is 503 `audit_failed` and the link
is withheld (the next claim voids it); after a reset, also 503 `audit_failed`.

#### Reads

All are `GET` (or `HEAD`); another method is 405 with `Allow: GET, HEAD`. Answers are `{"ok":true,…}` with the
control-plane rows as stored (the column names of `edge/migrations/control`). None has a token or a secret: the
control plane stores none, and audit rows refuse them. A query value that is not valid is 422 `invalid_query` with
the parameter's name as `detail`; an empty value is no filter, and unknown parameters are ignored. A list takes
`limit` (1 to 100, default 50) and `cursor`, and answers `next_cursor`: pass it back as `cursor` for the next page;
it is null on the last one. Installations are in `id` order; jobs and audit rows newest first.

- `GET /overview` returns `installations_by_status` (`[{status,count}]`, every status), `installations_by_plan`
  (`[{plan_id,plan_name,count}]`, every plan, counted by subscription), `subscriptions_by_status` (every status),
  `open_jobs_by_state` (`paused`, `queued`, `running`), `usage_totals` (`[{metric,value}]`, the sum of
  `usage_counters` for `usage_period`, the current UTC month), `revenue` and `as_of`. `revenue` is null because there
  are no prices, and the screen leaves the amount blank and says that no prices are set. `as_of` is the stats
  freshness stamp: null until the stats Cron lands, as is 90-day usage.
- `GET /installations?status=&plan=&q=&limit=&cursor=` returns `installations`, each
  `{installation,routes,subscription,plan,primary}`: the `installations` row, its `installation_routes`, its
  `subscriptions` row and its plan (both null without a subscription). `q` is a substring of one of its hosts (case
  does not matter). `primary` is listed with `"primary":true`, and no action accepts it.
- `GET /installations/:id` returns the same fields as a list entry plus `limits` (its plan's `plan_limits`), `usage`
  (its `usage_counters`, newest period first, at most 120), `jobs` and `audit` (its last 20 `lifecycle_jobs` and
  `operator_audit` rows). Unknown is 404 `not_found`; `primary` and deleted installations are readable.
- `GET /plans` returns `plans`, each `plans` row with its `limits`.
- `GET /subscriptions?due=14d` (`N` or `Nd`, 0 to 365 days, default 14) returns `due_days`, `now`, `subscriptions`
  and `truncated` (more than 500). A subscription is due on its `trial_end` while `trial`, else its `period_end`;
  listed when that is at most `N` days from now, overdue ones included (`"overdue":true`), soonest first.
  `cancelled` and those without a date are never listed. Each has the `subscriptions` row, `plan_name`, `due_at` and
  `hosts`.
- `GET /jobs?kind=&state=&installation=&limit=&cursor=` returns `jobs`, `lifecycle_jobs` rows. `status` is accepted
  for `state`.
- `GET /audit?installation=&operator=&action=&from=&to=&limit=&cursor=` returns `audit`, `operator_audit` rows.
  `installation`, `operator` (any case) and `action` are exact; `from` (included) and `to` (excluded) are a UTC date
  (`2026-11-20`) or date-time (`2026-11-20T10:00:00Z`).
- `GET /audit.csv` takes the same filters and is a `text/csv` download of at most 10,000 rows, newest first, with a
  header row and CRLF line ends; `X-Truncated: 1` when more rows match. A cell starting with `=`, `+`, `-`, `@`, a
  tab or a CR gets a leading `'` so a spreadsheet does not run it, and a cell with a quote, comma, CR or LF is quoted.

### Planned

Not served yet; the shapes may still change.

- `GET /installations/:id/users` (metadata only)
- `PUT /plans/:id/limits`
- `PUT /installations/:id/subscription`
- `GET /subscriptions/rules` is a report only. A rule with an unset value shows `not run: value not set`.
- `POST /jobs` comes back `paused` for every kind. `kind` `provision` returns 409 `provisioning_disabled` while
  provisioning is off, which it is on prod.
- `POST /jobs/:id/confirm`, with `confirm_host` for a purge.
- `GET /jobs/:id/export` is a download for a finished export job (streamed, size-capped).

Deferred, and not in E6: `POST /installations/:id/users/:uid/disable`, `POST /installations/:id/users/:uid/reset-link`,
`POST /installations/:id/overrides` and `POST /jobs/:id/retry`.

The local preview in `ops/console` answers only `GET /config`, `GET /overview`, `GET /installations` and
`GET /installations/:id`, with made-up rows, and can answer 401, 503 and 410 so the error screens can be checked.
That preview is not part of the production build.

## Deploying the console

`apotheke-ops` is deployed by the `deploy-ops` job of
[`.github/workflows/deploy-edge.yml`](../.github/workflows/deploy-edge.yml). Merging a change deploys nothing: the job
is skipped (before the `deploy-edge` environment's review) unless the repository variable `OPS_DEPLOY_ENABLED` is
exactly `1`, and checks that again before any checkout. When it is on, it runs on the same refs as apotheke-api's
deploy (a published full release `vX.Y.Z`, or a manual run on `main` or such a tag), after that deploy (skipped is
fine, failed stops it), in the `deploy-edge` environment with its required reviewer. It builds `ops/console`
(`npm ci`, `npm run build`, `npm run test:bundle`), runs `scripts/ops-deploy-config.mjs`, then `scripts/cf-deploy.sh
apotheke-ops edge/ops` (the Worker guard, then `wrangler deploy`).

`scripts/ops-deploy-config.mjs` adds, on the runner's checkout only, what is never committed:

| Value | From | Note |
|---|---|---|
| `DB`'s `database_id` | secret `APOTHEKE_D1_DATABASE_ID` (environment `deploy-edge`) | the same secret apotheke-api's deploy uses |
| `ACCESS_TEAM_DOMAIN` | variable `OPS_ACCESS_TEAM_DOMAIN` | `<team>.cloudflareaccess.com`; not a secret |
| `ACCESS_AUD` | variable `OPS_ACCESS_AUD` | the Access application's audience tag; not a secret |
| `OPS_ALLOWED_EMAILS` | variable `OPS_ALLOWED_EMAILS` | comma-separated; `<operator email>` for the first deploy |

It refuses (and nothing is deployed) when a value is missing or malformed, when the committed config already has a
`database_id` or Access values, or when `OPS_PROVISIONING`, `LIMITS_ENABLED` or `OPS_WRITES_ENABLED` is not `"0"`.
Pull requests run the same script with dummy values, the Worker guard, and `wrangler deploy --dry-run`, and check the
bindings `DB` (apotheke-api), `OPS` (`apotheke-api#Ops`), `AUTOFILL` (`apotheke-autofill#HostedAutofill`) and
`ASSETS`, and the three flags at `"0"`.

The config has no route and no custom domain (the Worker guard refuses both), no Cron Trigger (`triggers.crons` is
empty) and no Workflow yet. The lifecycle Workflow (`edge/src/lifecycle-workflow.ts`) is hosted by this Worker, which
already binds `AUTOFILL` for it; declaring it also needs the cross-script `APOTHEKE` and `INSTALLATION` Durable Object
bindings, `STORAGE`, and a class that this Worker's typecheck accepts, and comes in a later change. Until then no job
can start from this Worker.

### Going live

Each step below needs Stathis's approval and is done by him, in this order:

1. **Access application** (Zero Trust): a self-hosted application for `console.getapotheke.com` (the host is a
   placeholder until then), policy *allow* for the email `<operator email>`, login with the one-time PIN, session
   8 hours. Note its audience tag.
2. **Variables** of the `deploy-edge` environment: `OPS_ACCESS_TEAM_DOMAIN`, `OPS_ACCESS_AUD` and `OPS_ALLOWED_EMAILS`
   (`<operator email>`). `APOTHEKE_D1_DATABASE_ID` is already there. No new secret.
3. **First deploy**: set the repository variable `OPS_DEPLOY_ENABLED` to `1`, then a manual run of Deploy edge on
   `main` (or the next release), approved in the `deploy-edge` review. The Worker answers nothing until it has a
   host.
4. **Route**: attach `console.getapotheke.com` to `apotheke-ops` as a custom domain in the dashboard (Workers &
   Pages, apotheke-ops, Domains & Routes), only after the Access application covers that host. Not in
   `edge/ops/wrangler.jsonc`: CI never adds a route.
5. Check: without an Access session the host shows the Access login; signed in as `<operator email>`,
   `GET /api/ops/v1/config` answers `"writes_enabled":false`; any other email is 403 `forbidden`.

Writes (`OPS_WRITES_ENABLED` `"1"`), provisioning and limits stay off; each is a reviewed change to
`edge/ops/wrangler.jsonc`, and the deploy config refuses them until then. To stop deploying, unset
`OPS_DEPLOY_ENABLED`; to take the console offline, remove the custom domain.

## First-admin claim and admin reset for the hosting side

With `APOTHEKE_HOSTED`, the container also answers `POST /internal/accounts/claim` and
`POST /internal/accounts/reset-admins` on the host `ops.internal` only, so the operator console can get a hosted
installation a first admin without a shell in the container. They are reached and refused exactly like
`/internal/backup` (see [Backup for a lifecycle job](#backup-for-a-lifecycle-job)): no forwarding headers, the same
`X-Apotheke-Ops-Signature` over `POST`, the request's own path, `t` and the body (any body up to 4 KiB, for example
`{}`; a larger one is refused with 401), accepted within 300 seconds. A signature for one path is not valid for
another. The path must match exactly: with a query string or a trailing `/` it is not this endpoint. Such a
request, and any request on `ops.internal` when self-hosted (where neither exists), gets the host list's 421, as an
unknown host does.

- **`/internal/accounts/claim`** does what `accounts:claim-create` does: 200
  `{"ok":true,"token":…,"expires_at":"YYYY-MM-DD HH:MM:SS UTC"}`, and any earlier claim stops working. The link is
  `https://<host>/claim#<token>`. The token is only in this answer: it is not stored (only its SHA-256 is) or
  logged. While an active `admin` exists: 409 `active_admin_exists`, and nothing changes.
- **`/internal/accounts/reset-admins`** does what `accounts:hosted-reset-admins` does (removes admin accounts, their
  session records and any claim; other accounts stay): 200 `{"ok":true}`, also when there was nothing to remove.

Other errors are `{"ok":false,"code":…}`: 401 `unauthorized`, 405 `method_not_allowed` (with `Allow: POST`),
500 `accounts_failed`, 503 `not_configured`.
