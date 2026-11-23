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
anything   503 until boot succeeded, then 404 (until S0.5 adds routes)
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
  workerd refuses `new WebAssembly.Module(bytes)`. `src/platform/secretbox.ts` implements the same
  primitive and is verified against a fixture libsodium produced (`test/fixtures/secretbox.json`),
  not against itself. The plan's §3 row needs a revision-8 note.
- **The PHP-produced `enc:v1:` value is still unproven.** The PHP build on the machine that made the
  fixtures has no ext/sodium, so the phase-0 spike (plan §5) needs a runtime where it does.
- **`/readyz` is unauthenticated** until phase 1's filter chain exists; that is why the compose
  healthcheck above does not use it.
- **CI proves the emulated D1 binding, not hosted D1.** The `v4.yml` `d1` job boots workerd on the
  Miniflare D1 binding — SQLite under workerd — and then reads the database it wrote: 36
  `schema_migrations` rows, 0019's seven `search_fts` objects against the PHP oracle (so a backend
  without FTS5 fails loudly rather than skipping them), and no application table in the Durable
  Object's own storage (D12). A real D1 database is first exercised when a hosted installation is
  provisioned, which is phase 1; plan §5 states the same limit.
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
