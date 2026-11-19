# PR #335 evidence (reissue)

Gated head `c87ff4cb6abd14f53c2ef2da7c55f7a299587551`. Live `git ls-remote origin refs/pull/335/head` returned that SHA immediately before the verdict. `refs/heads/main` was `a3689d892794e3cf6bf7546b3ce3ede543a53f7d`. `git merge-base --is-ancestor` was true and the behind count was 0.

Since `cc52fc7b`: `eafe73fd` merges `a3689d89` (`#334`). `c87ff4cb` edits `RestoreFence.php`, `RestoreFenceTest.php`, and `restore-fence-worker.php`. Own diff versus `a3689d89` is the same seven paths as `624148bf...cc52fc7b`, with the extra lines from `c87ff4cb`. CHANGELOG preamble equals main. The file from `## [3.53.2]` equals main. Unreleased on both sides is the two `#334` maintenance bullets. README and VERSION match main. `docs/HANDOFF.md` versus main still changes the latest-release line from v3.53.1 to v3.53.2 and adds the fence bullet.

## Hook

The new `querySingle('PRAGMA busy_timeout')` and `file_put_contents` are inside `if (is_string($waiting) && $waiting !== '')`, and the pragma read is also inside `if ($sqlite !== null)`. Unset: no marker file, `getenv` false. Empty: no marker file. SQLite with the variable set: body `60000`. PostgreSQL with it set: body `waiting`. The earlier `PRAGMA busy_timeout` read that saves the previous timeout is unchanged and still runs on every SQLite claim, hook or not.

## L-1912

`testAClaimWaitsPastTheUsualSqliteTimeout` asserts the marker is `60000` during the wait on SQLite, and that the worker prints `busy_timeout 5000` after `claim()` on that same `SQLite3` handle. PostgreSQL asserts the marker is `waiting`.

Removing the two lines that set `SQLITE_CLAIM_WAIT_MS` fails the SQLite dataset. Removing the `finally` restore fails the SQLite dataset. The file was restored. `git diff` was empty.

`RestoreFenceTest` 20 times: each OK, 7 tests, 76 assertions. Backup suite once: `Tests: 253, Assertions: 25260, Failures: 1, Skipped: 19`. The failure is `BackupHelpersTest::testAZipIsRefusedOnItsEntriesBeforeItsContents`.

N-2151 is the alias, in `docs/qa/apotheke-pr289-privacy-session-cookie-qa.md`, for N-2147 (`check-dist.test.mjs` needs `frontend/dist`). It is not a fence-test finding.

Subject: `test(backup): check fence claims by outcome, not wall-clock (#335)` is 66 characters.

Scratch PostgreSQL 16, port 54354, data dir `/tmp/apotheke-pg-335d.*`, was stopped with `pg_ctl -D` that dir `-w stop` and the directory was removed.

CI on this head, not waited on: Lint, deptrac, DI, D1, CodeQL, secret scan, conventional commits, Headless mobile, both Compose jobs, and both upgrade jobs pass. PHP sqlite, PHP pgsql, both Docker builds, and three visual shards are pending.
