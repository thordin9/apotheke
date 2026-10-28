# QA: PR #137, CI4 boot cost (L-472)

**Head** `7b5964a` (final round; plain push on `636b80a`) · **Base** `feature/ci4` `27c422b`, unmoved (#136 not merged) · **CI** run 40496937824, 23/23 green (read-only `gh`) · PHP 8.4.27, PostgreSQL 17, throwaway `qa137_` roles and DBs · 2026-10-27

## Verdict

**PASS.** L-472 and L-500 are fixed. N-501 is partly fixed, and the PM has decided N-504. N-507 and N-508 are notes only.

## Checklist

- [x] **Merge.** `merge-tree` with current `feature/ci4` is clean and gives the head's tree; the earlier merge `bfbd9e9` has an empty remerge-diff.
- [x] **Login count (independent, `pg_stat_database.sessions`).** CI4 at the head: 2 on the first request (migrates), then 1 per request, also on `/login`, `/` and redirecting pages, and with `PHP_CLI_SERVER_WORKERS=4`. Base: 2 per request. Legacy: 1 per request.
- [x] **Mutations.** Adding a runner connect back on the current-schema path fails `Ci4OneConnectionTest` (10 vs 5). Reverting the L-500 fix fails the new SQLite case of `Ci4DatabaseDownTest` ("CI4 wrote to ci4/writable/logs").
- [x] **Migration path, PG and SQLite.** Fresh DB: 28 steps. Partially migrated: 26, then 28 after a restart. Removed `schema_migrations` row: detected and re-run. 12 concurrent first requests with 4 workers, 3 rounds per driver: all 200, no duplicate rows. Runner connection closed in `finally`. Schema checks follow the same rule as legacy `migrations_pending()`.
- [x] **L-500 fix.** With an unopenable SQLite file, `/healthz`, `/health`, `/login` and `/` give the same status, headers, body and sanitized server-log line as `636b80a`. `ci4/writable/logs` no longer grows on the health routes. Page routes: N-507. On a good open, `foreign_keys=1`, `busy_timeout=5000`, `journal_mode=wal` and `synchronous=1` are the same as before. There is one SQLite file handle per request, as before.
- [x] **PG path unchanged.** A refused connect gives the sanitized line with no user or password, the same as before.
- [x] **DSN credentials, DB down.** `Ci4DsnCredentialsTest`, `Ci4ConnectFailureLeakTest` and `Ci4DatabaseDownTest` are green.
- [x] **Dotted env overrides.** Base applied `app.*`, `cookie.*`, `encryption.key`, `session.*`, `security.*`, `database.default.*` and `logger.*`; the head applies none. Nothing in the repo, image, entrypoint, CI, docs or tests sets one. Sign-in at start-up (SEC-M3), the localhost bind, `APP_PASSWORD`, `DB_*`/`DB_DSN`, `APP_KEY` (encryption at rest) and `CI_ENVIRONMENT` don't depend on them, so they are unaffected. Matches main. Documented in `docs/configuration.md`.
- [x] **Module discovery (services only).** No module or package ships routes, filters, events or a registrar. `spark routes` (routes and filters) and `spark list` are identical between base and head. Views are unaffected.
- [x] **Suites.** Root suite with the CI4 app on SQLite and PG: OK, 822 tests (includes the photo-race and migrate concurrency tests). `ci4/phpunit.xml.dist` on SQLite and PG: OK, 670 tests each. Lint, `check-commits` (12 commits) and gitleaks are clean. The CHANGELOG bullet names L-500.
- [x] **Parity gate at the head, 15 rounds.** 196 steps, 0 diffs on both DBs once asset mtimes are aligned (N-490). Median gap: SQLite +1.1 to +2.1 ms, PG -0.4 to +1.2 ms. Budgets of +8 and +20 ms are met.

## Findings

| ID | Grade | Status | Finding / change |
|---|---|---|---|
| L-500 | Low | **Fixed** `88f9dc6` | DB-down log hygiene on SQLite (hardening). The file is opened by Apotheke first; a failure is the sanitized line. Test `2a62ac8`. |
| N-501 | Note | Partly fixed | `Ci4OneConnectionTest` now includes a page route (`/login`). There is no open count for SQLite. |
| N-502 | Note | Open | The override switch-off is a process static that a config-cache restore turns back on. Keep `configCacheEnabled` off, or add a test. |
| N-503 | Note | Open | The request service overrides an internal framework service. Re-check on upgrades. |
| N-504 | Decision | **Decided (PM)** | Budget unchanged (+8/+20 ms median). p95 enforcement and the tighter median come in step 16 part 3, after #140. |
| N-505 | Note | Open | Cloudflare phase: per-process migration memo, a filesystem scan of `migrations/`, `require_once` of legacy `src/config.php`, and the boot-time config static. No new `getenv`, superglobals or temp files. |
| N-506 | Note | Open | When the test admin isn't a superuser, tests that fail in setup leave their throwaway roles. Register cleanup first. |
| N-507 | Note | New, pre-existing | While the DB is down, page routes (not health routes) still add one framework log entry per request to `ci4/writable/logs`, on both drivers. It has the sanitized message and an app-path trace, no secrets. Legacy writes traces to the server log. Hardening: log once, or at a lower level. |
| N-508 | Note | New | The L-500 fix sets the handle itself and skips the framework's connect routine: connect timing isn't recorded and failover (unused) is skipped. Framework coupling; re-check on upgrades. Cloudflare phase: no new statics, no temp files, the path comes from config. `88f9dc6` is typed `refactor` although it changes logging. |
| N-490 | Note | Open | Manifest `?v=` diff from asset mtimes on a fresh checkout. |

## Cleanup

Servers stopped, `qa137_` DBs and roles dropped, scratch worktrees removed. Nothing posted or pushed.
