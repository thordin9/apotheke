# QA: PR #161, CI4 step 18 cutover into feature/ci4

- **Head:** `bf1f468cb93c68d97866f8776889df9ed0980d5c`. 28 commits (21 + 7 fix commits), base feature/ci4 `1b2e107`, 0 behind, mergeable, same repo.
- **CI:** run 40945878121 passed 14/14.
- **Verdict: PASS.** No H/M remain. Open items are L/N only: L-963 (partial), L-1000, N-964 (partial), N-965 (partial).

## Checklist

- [x] **PR state.** `955c736` (#160) remerge-diff is empty. `61b106f` contains only renames. The 7 fix commits are plain (no merges).
- [x] **Fix delta scope.** Tests, test fixtures, docs and comments, plus the CI job name. No production code changed: the 4 command docblocks and phpstan-di.neon are comment-only, and the upgrade job has the same steps (only the name changed).
- [x] **Inventory.** 122 D, 358 R, 104 M, 2 A at the cutover.
  - ci4/public equals the new public/ except one app.js comment.
  - All 5 bin wrappers are kept.
- [x] **Suites at the new head.**
  - Root: SQLite 303 OK, PG17 303 OK.
  - Modules: SQLite 625 OK, PG17 774 OK.
  - The new tests (migration runner, page/JSON goldens, router guard, served-copy colour step) pass on both drivers.
- [x] **Static checks** (local at the cutover head; CI 14/14 at the new head). lint, deptrac, PHPStan DI, routes and check-commits all pass. The DI exclusion is narrow.
- [x] **Upgrade-rollback script.** 2.6.26@8.3 → CI4@8.4 → 2.6.26, plus cross-restore, on SQLite and PG: all steps equal.
- [x] **Docker upgrade from the 2.6.26 image (48 checks).**
  - Data matches; sign-in with 2.x passwords works and roles are kept.
  - 2.x photos are served unchanged; /originals returns 404.
  - The sealed autofill token still works.
  - Originals are byte-identical with mode 640.
  - Served and autofill copies are resized with metadata stripped.
  - Backup restore works and rollback to 2.6.26 serves 3.0 uploads.
- [x] **Docker image.** signin, heic, smoke, docker and auth checks all ok; graceful stop exits 0.
- [x] **e2e, desktop-chromium @smoke.** 31/33 pass. The 2 failures are host-rendering screenshot diffs only.
- [x] **CHANGELOG and hygiene.** Released sections are byte-identical to main. Only `https://autofill.example` appears.
- [x] **Cloudflare.** No blockers; no Worker or config changes.
- [x] **Mutations: 16 at the cutover, re-run on the fixes.**
  - Now killed by the new tests: the short-APP_PASSWORD refusal, the adopt backup, legacy v3, `BEGIN IMMEDIATE`, the matchGd no-op, and the router originals guard.
  - Golden drift (one byte in the item page) fails the new golden test.
  - Still surviving: the restore wrapper pre-check (N-965).

## Findings

**Fixed:**
- **M-960, the migration runner lost its tests:** fixed in `0585cba`. It now covers every schema version, v1–v3 adopt with backup, legacy v3, concurrent apply-once, status and the short-password stop.
- **L-961, weaker HTTP regression guard:** fixed in `61bcc81` (goldens for the item pages and list JSON).
- **L-962, served-copy colour handling untested:** fixed in `943e017` (flatten and CMYK fixture test).

**L-963, stale docs: partly fixed (`f9bce59`).** The listed pages are fixed. These remain:
- AGENTS.md and CONTRIBUTING.md still describe the legacy app, PHP 8.2+ and `ci4/`.
- docs/index.md still says "PHP 8.2+ … no framework".
- docs/deployment-and-operations.md still lists `src/` among the paths that must not be reachable.

**N-964, stale comments: fixed in `d6f45c4`.** The entrypoint `su -p` comment was left as-is on purpose (PM decision).

**N-965, untested defence-in-depth checks: partly fixed (`bf1f468`).** The router originals guard is now pinned. The restore wrapper's DataDirOwner pre-check is still untested; spark checks again before boot.

**L-1000, SQLite first WAL switch (robustness).** The first `journal_mode = WAL` switch on a brand-new SQLite file can return "database is locked" despite `busy_timeout = 5000`.
- How often: about 10% of runs with two `bin/migrate.php` processes started at the same time, and about 4% with two bare connections.
- No failures in 100 runs of 2–6 simultaneous first HTTP requests.
- Once WAL is set, the error does not recur: 0 in 320 further connections.
- The losing process stops cleanly. The winner completes all 28 migrations, the DB is consistent, and a retry is a no-op.
- Real-world exposure is limited to two processes racing on the very first start. The entrypoint does not run migrate alongside php-fpm.
- **Fix:** switch to WAL once under an exclusive step, or retry on SQLITE_BUSY at the switch.

## Security

- **gitleaks 8.30.1.** Full history (1,499 commits) and the PR range, including the fix range: no leaks. `.gitleaks.toml` is identical to base.
- **Kept and verified:** SEC-M3 exit handling, the legacy-app refusal (64), the symlinked data dir refusal, the session-marker wipe, /originals returning 404 (now also pinned in the dev router), metadata stripping, sealed tokens still decrypting after upgrade, and no secrets in logs.
- **Short-APP_PASSWORD refusal on migrate:** covered again (M-960 fixed).
- **Remaining hardening:** N-965 (restore wrapper pre-check) and L-1000.
- **Testing setup:** private scratch dirs, a throwaway DB/role, local ports and a local image tag. No live Cloudflare spend.
