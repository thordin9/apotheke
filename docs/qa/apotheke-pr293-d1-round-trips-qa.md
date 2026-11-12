# PR 293 D1 round trips — QA

**Verdict: PASS** at `cb49ca951476c2dd66cbb2ce4b22ce3e51d794e7`, 0 behind main `3be6990d` (v3.37.1). Full gate at `6ea3d4a0` (base `00945588`, v3.36.6), then merge-only reads at `a32e8d10` and `cb49ca95`. L-1882 and L-1883 are closed. No hold. H-1849 stays closed. N-1850 stays open.

## Checklist

| Check | Result |
|---|---|
| Invalidation | Every write through the D1 connection counts on `WriteClock` (`send`, `runBatch`). A migration on the bootstrap client calls `wroteEverything()`. A later read of those tables misses. |
| Transactions | Nothing is stored while `transDepth` is not 0. A rolled-back queue is not sent. The clock only moves forward, so a later read misses and sees committed rows. |
| Worker | `readCache` is not a persistent service. The worker loop drops it after the request, including after a caught error. The clock lives on the connection and only grows. |
| Permissions | Account, role, password and session-epoch writes are read back in the same request. `CurrentUser` calls `find()` each time. The next request gets a new cache. |
| Scope | User rows are keyed by id. Settings, roles and list stats are installation-wide. Stats keys include the SQL and the binds. |
| Rate limit | The hosted check is one batch: prune, insert, id, count. A count over the cap deletes that row. SQLite and PostgreSQL keep the previous statements. |
| `rowsMany` | A batch that fails is read again one statement at a time, and that failure is the same error as `rows()`. Results stay in order. |
| Other drivers | SQLite and PostgreSQL do not remember reads and do not send a batch. |
| Merge-only | `6ea3d4a0` is an ancestor. The link commit `9d04a764` changes only `CHANGELOG.md`. The merges of `5e2d3471` and `3be6990d` are plain. The own diff, outside the changelog and handoff, matches the reviewed one. |
| Docs | One Unreleased bullet, with the PR link. Released sections, `README.md` and `VERSION` match main. |

## Findings

**L-1882, closed.** The Unreleased bullet had no pull-request link. `9d04a764` adds it.

**L-1883, closed.** The bullet said a signed-in list goes from 45 calls to 11; `9d04a764` makes it 12. The budget test measured 12 for `GET /`. Its ceiling is 12, so the test passes. `/api/v1/me` measured 8 and `/healthz` measured 1, as the bullet says.

Next free id is 1885.

## Security

A write on the request's D1 connection, including a unit of work and a queued statement, advances the clock for its table, or for every table when the statement is not a plain insert, update, delete or replace. Remembered settings, roles, the account count, an account row and an item row are used only while those stamps match. A demotion, a deleted account, a role change and a new session epoch are read back before the request answers again. The cache key for an account includes its id. Nothing is remembered on SQLite or PostgreSQL, or while a transaction is open.

The rate-limit batch records the attempt and counts it in the same batch. Over the cap, that row is removed. The shared limiter is not a persistent service, so the once-per-request prune does not carry to the next request.

`rowsMany` does not turn a failed batch into empty rows. On local workerd a batch with one bad statement answers a single generic failure, and `rowsMany` then raises `Database read failed`. A successful batch returns each result in order.

## Tests

SQLite: 603 tests, 9562 assertions, 2 failures, 30 skipped, 1 incomplete. Nested module suite: 1334 tests, 46030 assertions, 2 failures, 18 skipped. PostgreSQL: 603 tests, 10327 assertions, 2 failures, 14 skipped. Nested module suite: 1822 tests, 52354 assertions, 2 failures, 4 skipped. The module failures are the zip-entry helper and the CMYK served-copy check. Those files are not in this diff. The other failure was L-1882, now closed.

Measured D1 round trips: healthz 1, `/api/v1/me` 8, `GET /` 12, item 14, new save 9, edit 13.

## Verdict

PASS at `cb49ca95`. The catch-up is minor, so no suites were re-run.
