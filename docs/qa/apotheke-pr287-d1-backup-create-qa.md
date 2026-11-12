# QA: apotheke #287, page d1 backup reads, guard the spool disk, record the d1 driver

- PR: https://github.com/thordin9/apotheke/pull/287 (Coder), base `main`, ready (not draft).
- Code gated at `db4ba088a4ca8477a9a9c1c229d9e0867d3ab2e4` (off `00945588`, 1 behind main `48d2fbc1` at the time). Commits: `6c1be376` fix(backup): page d1 reads, guard the spool disk, record d1 driver (66 chars), `db4ba088` docs(changelog): add the d1 backup fix under Unreleased (55 chars).
- Gate: full (diff read, ran-vs-skipped from the CI job logs, Backup module and full suites on PHP 8.4 with SQLite and PostgreSQL 17, the d1 path on the local `node:sqlite` bridge, new tests against main, 25 mutants, 10 page-boundary probes)
- Reviewer: Apotheke QA 2
- Merge-only read at `724952ae59e6f66530bbab841ad60001cc4001bc`, 0 behind main `48d2fbc1`. Since `db4ba088` there is only `724952ae`, a plain merge (parents `db4ba088`, `48d2fbc1`), plus main's `48d2fbc1` (#284). `Backups.php`, `BackupController.php` and `D1BackupCreateTest.php` are byte-identical to `db4ba088`, and their +/- lines of `main...724952ae` equal `00945588...db4ba088`. CHANGELOG [Unreleased] has #284's `### Changed` bullet first, then #287's `### Fixed`; 3.36.6 and older are byte-identical to main. Still 4 files, +323/-2.

## Checklist

- [x] Scope: `Backups.php` (+46/-2), `BackupController.php` (+3), the new `D1BackupCreateTest.php` (+270), `CHANGELOG.md` (+4). Conventional Commits, subjects at most 72 chars. No secrets.
- [x] Paging: keyset (`WHERE (key) > (?)`, no OFFSET), `ORDER BY` the same key as the old single SELECT (`id`; `item_id, tag_id` for `item_tags`; `name, value` for `app_settings`). Every carried table has an `id` primary key or a unique non-null two-column key, so there are no ties or NULLs in the key. The loop stops on a short page, so exactly 500 or 1000 rows cost one extra empty read. Probes on d1: activity 999/1000/1001/1500 and item_tags 498/500/502/1000 rows all give dumps byte-identical to SQLite apart from `driver`, in `floor(n/500)+1` reads. SQLite and PostgreSQL paths are unchanged.
- [x] Disk guard: bytes throughout. Before each remote object is written, free space on `dirname(spool path)` (the temp folder, the same one as the download zip) must be at least the object plus everything spooled so far (JSON included, this object included) plus 64 MiB, which covers the spooled files and a zip of them. `disk_free_space()` returning false skips the guard. On failure the existing `finally` removes every spooled file and the JSON, no zip is written, and the download also removes its temp zip.
- [x] User path: `GET /backup.zip` shows the message on the backup page (422); `bin/backup.php` prints it and exits 1. The 507 code is not surfaced anywhere.
- [x] `driver: "d1"`: `Restorer` reads it only as a label (`open()` and the preview). `driver()` is unchanged, so the `pgsql` branches are unaffected. A d1 dump restores on SQLite (tested).
- [x] Cloudflare phase: storage stays behind `Storage` (R2 here), the free-space seam is a constructor closure (no global), and d1 is detected from the connection, which comes from the DSN config.
- [x] CI run 43209641881 on `db4ba088`: 21 check runs, all success. CI run 43214719720 on `724952ae` was still queued at the time of this read.
- [x] Ran vs skipped: the gate is `node` with `node:sqlite` plus `edge/node_modules`. Tests (8.4, sqlite) uses Node 20.20.2 and no `edge/` install, so it skips the test there (modules 1812 tests, 47 skipped). D1 (local workerd) runs a fixed list without it. Tests (8.4, pgsql) has Node 22.23.4 and `npm ci` in `edge/`, and the "Prove the D1 driver bridge tests ran" step in that job passed 5/5 with `--fail-on-skipped` under the same gate condition. So the test runs there, but only inside the hidden `Ci4ModuleSuiteTest` subprocess; no log line names it (L-1960).
- [x] Local, PHP 8.4.24 (8.3 is not supported: composer needs ^8.4, and the CI matrix is 8.4 only): the new file 3/3; Backup module 126/126; modules with PostgreSQL 17.11 configured 1812 tests OK, 7 skipped (workerd harness, Imagick/HEIC), with `D1BackupCreateTest` run; root suite on pgsql 586 OK, 10 skipped; `ci4-pgsql-proof.php` OK. The root and module suites on SQLite only with `CI=true` fail only on the CI-only checks for PostgreSQL and HEIC. Docker isn't on this box, so PostgreSQL ran from local binaries in place of the `postgres:17` service.
- [x] Against main's `Backups.php` and `BackupController.php`, all 3 new tests fail. With only main's controller, the guard test fails.
- [x] Mutations: 17 of 25 killed. Killed: LIMIT 499, LIMIT 501, page size 1, no ORDER BY, no second key column, `>` to `>=`, single page, d1 unpaged, no `return` after paging (duplicate rows), no headroom, the wrong directory checked, guard removed, nothing written to the spool, no spool cleanup, no JSON cleanup, driver as sqlite, no UserError catch in download. Survived: `<` to `<=`, no zip-so-far term, the object counted once, false treated as no room, JSON not counted (L-1961); 507 to 500 and a changed message (N-1965); no `unlink` before the 422 (equivalent: no zip exists yet when the guard fires).

## Findings

- **L-1960:** CI never names `D1BackupCreateTest` as run. It runs only in the pgsql leg's hidden module subprocess, and the SQLite leg skips it silently. Hardening: add it and `D1Hosted*` to the pgsql "prove" step with `--fail-on-skipped`.
- **L-1961:** the guard's arithmetic isn't pinned. The test's free space (headroom + 10 bytes) is tripped by any term, so 5 arithmetic mutants survive. Hardening: tests at exactly the needed bytes and 1 byte less, and one for `disk_free_space()` returning false (today it skips the guard, which is fine but should be pinned).
- **L-1962:** `docs/self-host-cloudflare.md` "Backups" still says there is nothing to back up on Cloudflare and that the app backup is for installs with a disk. Hardening: say that the backup download works on the hosted API, and that restore is still refused there.
- **L-1963:** a d1 backup has no read snapshot, so writes during it can give a dump whose rows don't match across tables. This was already true across tables, and paging adds it within a table. Hardening: document making hosted backups while idle, or check the row counts again at the end.
- **N-1964:** the restore preview labels a d1 backup "SQLite" (`BackupView` maps everything but `pgsql` to SQLite), and the CLI prints the raw "d1". Hardening: a hosted label.
- **N-1965:** download turns any `UserError` from `create()` into the 422 page, the 507 code is unused, and the test compares against the constant, not the text. Hardening: catch only the no-space case and pin the sentence.

0 High, 0 Medium, 4 Low, 2 Nit.

## Verdict

**PASS**. Approved head `724952ae` (merge-only read), with the code gated at `db4ba088` (CI run 43209641881, green). CI run 43214719720 on `724952ae` must be green before merge. Squash-merge with the PR title as the subject. L-1960 to N-1965 can go in a follow-up.
