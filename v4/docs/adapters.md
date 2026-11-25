# Platform adapters (S0.3)

One page, because the interactions below are the ones that bite: two of them involve a process that
restarts itself when it is unhappy.

## What a handler may assume

`Sql`, `Blobs`, `Clock`, `Secrets` and the config loader are the whole platform surface. A handler
imports these and nothing else from `src/platform/`; it never sees a binding, a driver name or a
`DB_DRIVER` value. That is D12's rule in code: hosted D1, self-host rqlite and single-process DO
SQLite differ only inside the driver factory.

| Adapter | File | Contract |
|---|---|---|
| `Sql` | `src/db/sql.ts` (+ `d1.ts`, `do-sql.ts`) | Reads via `all()`, writes as one `batch()`. No `begin`/`commit`: the unit of work is the batch, written for the weakest backend. |
| `Blobs` | `src/platform/blobs.ts` | R2 (hosted) or the disk bucket (self-host) behind one key layout: `installations/<installation>/…`, so a bucket written by PHP reads here unchanged. |
| `Clock` | `src/platform/clock.ts` | `stamp()` is `gmdate('Y-m-d H:i:s')` in UTC. `APOTHEKE_CLOCK` pins it, which is how the fixtures are reproducible. |
| `Secrets` | `src/platform/secrets.ts` | `enc:v1:` + base64(nonce24 ‖ secretbox), XSalsa20-Poly1305 — the bytes PHP's `sodium_crypto_secretbox` produces. No key means plaintext, as in PHP. |
| Config | `src/platform/config.ts` | Env → `AppConfig` with PHP's defaults, precedence and normalisation. The single defaults source the settings seed reads. |

## Boot, readiness, and the two health routes

The `Installation` Durable Object starts `prepare()` inside `blockConcurrencyWhile` in its
constructor: migrate → seed → stamp. No RPC is answered until that finishes, so **"seed pending" is a
state that exists only inside one boot**, never between a request and its database.

```
/healthz   always 200, never touches a database      ← the compose healthcheck probes THIS
/readyz    200 {schema, seed:"done"} or 503 problem+json with the reason
anything   503 until boot succeeded, then the router (S0.5): 401/403/404/405/419/429/501
           problem+json — and the page routes: a page that needs a session is 303 to `/login`
           since S1.3 (a fetch gets 401 JSON), a role without the permission gets 403;
           `/login` (GET/HEAD) as 200 text/html since S1.1, its
           POST as 303 to `/`, `/logout` as 303, `/consent` as 303, `/claim` as 200 with a
           hosted install (404 without), `/account` as 200 for a signed-in account since S1.5 —
           whose POST answers 303 to `/account?done=pw` or re-renders the page with `#acct-err`
           (403 for a wrong current password, the 422s, 419 for the page's token, 429 when the
           check is throttled), and `POST /account/signout` as 303 to `/account?done=out` — and
           `/settings/security` as 200 for a role with `users` since S1.3 — whose users and roles
           POSTs answer 303, or re-render that page with v3's 409/422 (a fetch gets `{ok, key}`
           from the roles POSTs). The API answers problem+json with a `code`: `/api/v1/session`
           GET and a successful POST as 200, DELETE as 204, 400/403/409/415/422/429 otherwise;
           `/api/v1/me` as 200 for a session and 401/403 otherwise. Its CSRF refusal is 403
           `csrf.invalid`, not the pages' 419.
           With the CORS preflight (204) those are the 2xx answers here; `router.md` lists them
```

Two rules follow, and both are in the code rather than in a habit:

1. **`/healthz` must not consult readiness.** It answers "the process is up". A database still
   electing a rqlite leader, or an install whose seed was refused (a short `APP_PASSWORD`), would
   otherwise fail the healthcheck and the stack would restart-loop on its own gate — §11 rule 7.
2. **The compose healthcheck probes `/healthz`, never `/readyz`.** In phase 1 `/readyz` moves behind
   the session/permission filter, so an unauthenticated probe would get 401 — the same trap §11
   already records for `rqlited -auth`. An operator who wants readiness in compose needs credentials
   in the check; the default is the liveness probe.

## The seed phase

Schema steps write no configuration rows. `docs/v4-migration.md` §5 defers seven steps
(`0013 0022 0023 0024 0025 0026 0027`) to this phase, and `src/db/seed.ts` replays what they did, in
order, in one unit of work under D10's envelope:

- **always**: insert the settings keys that are missing, with current defaults
  (`settings_seed()`'s idempotent half);
- **fresh installs only**: the editor/viewer permission lists (`FreshInstallRoles`), and 0018's adopt
  path — `settings.password_hash` becomes the account `admin`, then the key is cleared.

The `fresh` gate matters on upgrades: 0025 forces `autofill_auto` off on every install, and replaying
that on a v3.72 database would overrule an admin's choice at every start.

0018's own adopt path stays in the migration and is a no-op on a fresh v4 install, because the
settings rows do not exist yet at that point — which is exactly why the seed phase performs the
adopt, and why the gate above exists instead of a silent skip.

## Known gaps, recorded rather than hidden

- **`libsodium-wrappers` does not run in workerd.** It instantiates WebAssembly at load time and
  workerd refuses `new WebAssembly.Module(bytes)`, so the dependency is gone and
  `src/platform/secretbox.ts` implements the same primitive. It is checked two ways: against a
  fixture real libsodium produced (`test/fixtures/secretbox.json`), and — closed 2026-11-23 23:44 —
  against `sodium_crypto_secretbox` in both directions (a PHP-sealed `enc:v1:` value opens here, and
  a value sealed here opens in PHP 8.4.23). Plan §3's Crypto row records it.
- **`/readyz` is answered before the router** (`src/index.ts`, S0.5), so at HEAD it is
  unauthenticated by construction: the boot gate's own answer does not go through the session and
  permission filters. That is why the compose healthcheck above does not use it.
- **CI proves the emulated D1 binding, not hosted D1.** The `v4.yml` `d1` job boots workerd on the
  Miniflare D1 binding — SQLite under workerd — and then reads the database it wrote: 36
  `schema_migrations` rows, 0019's seven `search_fts` objects against the PHP oracle (so a backend
  without FTS5 fails loudly rather than skipping them), and no application table in the Durable
  Object's own storage (D12). A real D1 database is first exercised when a hosted installation is
  provisioned, which is phase 1; plan §5 states the same limit.
- **The `sqlite` job proves workerd's SQLite, not a host's file.** The single-process driver's
  database is a Durable Object's storage: an ordinary SQLite file, but one the app never opens and
  cannot tune or name (`PRAGMA` and `sqlite_version()` are refused). The job reads it with the
  `sqlite3` CLI; a second process opening it is not covered, and the contract above says why.
- **TLS from workerd to rqlited is unproven.** The `v4.yml` rqlite job runs plain HTTP on loopback,
  which is what a compose network looks like, so `https://` is implemented but not exercised. A
  self-signed certificate would additionally need to be trusted by the runtime, and the worker has no
  escape hatch for that by design.
- **The 503 retry is proven by the driver's specs, not against a live election.** The job does not
  kill and restart the leader; the backoff, the keyed/unkeyed split and the replay mapping are
  covered by stub-`fetch` tests. Re-running a leader restart is a G3 item, where the stack is
  restarted with data in it.

## The rqlite contract (S0.8)

Self-host defaults to a real `rqlited` beside `workerd` (§10), selected by `DB_DRIVER=rqlite`. The
driver is `src/db/rqlite.ts`; `loadDatabaseConfig()` in `src/platform/config.ts` is the only reader of
these variables, and it refuses rather than substitutes — an install pointed at the wrong database is
worse than one that did not start.

| Variable | Meaning | Default |
|---|---|---|
| `DB_DRIVER` | `d1` (hosted binding), `rqlite` (HTTP), `sqlite` (this process's own DO storage) | `rqlite` when `RQLITE_URL` is set, `d1` otherwise |
| `RQLITE_URL` | `http(s)://host:4001`. No query, no fragment, no trailing slash needed. | — (required for rqlite) |
| `RQLITE_AUTH` | `username:password` from rqlited's `-auth` file. Split on the first colon, so a password may contain one. | absent ⇒ unauthenticated node |
| `RQLITE_READ_CONSISTENCY` | `weak`, `strong` or `none` — rqlite's read level | `weak` |

What falls out of the plan's rules, in the order they bite:

1. **One unit of work is one request.** `batch()` sends the whole unit as a single
   `POST /db/execute?transaction`; the spike proved a failing statement aborts the transaction, so a
   unit commits whole or not at all. There is no `begin()`/`commit()` to misuse.
2. **Reads retry; writes only under the key.** Reads retry freely (connect error, `503`, timeout) on a
   bounded backoff of 100 ms → 1.6 s, about 3.1 s in total, which covers the ~1.6 s window a leader
   election answers `503`. A write retries when its first statement is D10's key (`_write_log`, or
   `schema_migrations` before 0036 exists), or when the failure provably happened before the request
   was sent (connection refused, DNS, TLS handshake). Anything else fails closed: after the bytes left
   there is no way to know whether the batch committed.
3. **A replay is success.** When a replayed unit dies on its key, the driver maps
   `UNIQUE constraint failed: _write_log.id` (or `schema_migrations.version`) to a completed unit —
   exactly-once without an idempotency feature from rqlite. The batch being atomic is what makes this
   sound: the conflict on statement 1 means no other statement ran.
4. **Read-after-write asks for the leader.** `all(statement, { strong: true })` sends `level=strong`
   for the one read that must not be answered by a lagging replica (§10 rule 8); everything else uses
   `RQLITE_READ_CONSISTENCY`.
5. **TLS is the URL's job.** `https://` verifies the certificate the runtime trusts; there is no
   insecure-skip switch, deliberately. Self-host on the compose network uses `http://rqlite:4001`,
   which never leaves that network (§11 rule 1).
6. **A 401 names the variable, never the value.** An authenticated node answers 401 for a missing or
   wrong credential, and the error says `RQLITE_AUTH` — the credential itself never reaches a message
   or a log. `/readyz` on rqlited is behind auth too, which is why compose healthchecks rqlited with
   credentials (or a TCP connect) and the app with `/healthz` (§11 rule 7).
7. **Winning the race is allowed.** Boot runs migrate → seed under `blockConcurrencyWhile`; if the
   database is unreachable at that moment the install is not ready, and a later `/readyz` retries once
   per second — `depends_on` does not survive an rqlite restart mid-session (§11 rule 2). A schema
   newer than the code and a refused seed are *not* retried: those are decisions, and retrying would
   only hide them.

## The sqlite contract (single process, `DB_DRIVER=sqlite`)

The third backend of §10, and `docker-compose.sqlite.yml`'s mode: no database process, no binding to
resolve. The installation's Durable Object owns `ctx.storage.sql` (D12), and `src/db/do-sql.ts` is a
thin RPC client of it, so `transactionSync` is the unit of work and one `batch()` is one transaction.

Five facts are this backend's own. `test/sqlite.spec.ts` asserts each, because every behavioural spec
in the suite runs on this driver by default (`wrangler.jsonc`), so the contract was covered by
inheritance and stated nowhere:

| Fact | Consequence |
|---|---|
| `changes` and `lastRowId` are `null` for every statement (D26) | A unit that changed a row and one that changed nothing report identically. Never branch on `changes`; read the state back — what `users.ts` and `roles.ts` do. |
| A failing statement rolls the whole unit back, DDL included | The same contract as D1's `batch()` and rqlite's `?transaction`; 0019's `optional` FTS5 statements rely on it. |
| At most 100 bound variables — D1's cap (§10 rule 3), not SQLite's 999 | 100 placeholders bind; 101 answers `too many SQL variables`. Rule 3 holds here too, so no handler needs a per-backend limit. |
| A BLOB arrives as an `ArrayBuffer` | The rqlite driver returns a `Uint8Array` (`rqlite-live.spec.ts` asserts it) and the emulated D1 binding a plain byte array: the same column has three JS shapes. No handler reads one today (media is in R2), so the shape is asserted rather than normalised. |
| The engine cannot be named or tuned from SQL: `PRAGMA journal_mode` answers `SQLITE_AUTH` and `sqlite_version()` answers “not authorized to use function”, here and on emulated D1 | No statement in `src/` may branch on the engine's identity or its journal mode — there is nothing to read. The refusal, not a value, is the contract. |

### What the `v4.yml` `sqlite` job proves, and what it does not

It runs the whole suite with `DB_DRIVER` named rather than inherited, fails if anything other than the
six rqlite live specs is pending, boots workerd on the driver, and reads the file the DO wrote: 36
`schema_migrations` rows, 64 `settings` rows, one active `admin`, 0019's seven `search_fts` objects
against the PHP oracle, the application tables **present** — the exact inverse of the `d1` job, where
the same file must hold none — and no D1 database file at all.

**What that does not prove, because the difference is the point:** the engine is workerd's own SQLite,
reached through Durable Object storage; it is not a self-hoster's `node:sqlite` or system `sqlite3`.
The file *is* an ordinary WAL-mode SQLite database — which is how the job, and an operator, read it —
but the app never opens it, has exactly one writer (the DO), and cannot tune or even name the engine:
`PRAGMA journal_mode` answers `SQLITE_AUTH` and `SELECT sqlite_version()` answers "not authorized to
use function" on this driver and on the emulated D1 binding — asserted, not inferred, in
`test/sqlite.spec.ts`. Schema
compatibility is therefore proven across two engines — PHP's SQLite in the fixture, workerd's here —
and nothing here says two processes may open that file at once. The compose smoke is where the stack
itself is proven: boot, migrate, `/readyz`, and a second boot that changes no row.
