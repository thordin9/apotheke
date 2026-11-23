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
