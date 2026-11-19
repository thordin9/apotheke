# PR #320 operator console C2 QA

Head `b138a3c7e10992584f4cfa699f9622ac6b152dae` (live), 0 behind main `81b51e07` (v3.47.0, which includes #317 as `1733bbfa`). Own diff 18 files, +1077/−9. Gated: full security gate at `c0bbc7ce`, the delta `c0fe6cd1` at `9c73ed75`, merge-only reads at `6c5060e1` and this head.

## Checklist

1. Public `fetch` always rewrites the container request in `toContainer`, which sets `X-Forwarded-Host` and `X-Forwarded-Proto`. Those headers make `applies()` false, so the path is the usual 404. `opsBackupRequest` does not set them and is not on the public fetch. Host must be exactly `ops.internal` (no port, no suffix). Forwarded names are matched case-insensitively; any value refuses. Self-hosted returns null. `opsbackup` runs before the host list, session and CSRF.
2. `verify` uses `hash_equals` on the full MAC. The header must be `t=<1–12 digits>,v1=<64 lowercase hex>` after trim. Extra fields, spaces, uppercase hex, a sign, or a huge `t` fail the pattern. `±300` s is both ways. The MAC is method, path, `t` and the body SHA-256. A body over 4 KB is 401 before the MAC. The same `job_id` replay returns the stored backup or 409.
3. Key is HMAC-SHA256 of `apotheke-ops-v1` under the 32 APP_KEY bytes. Missing or short keys are null and the endpoint is 503. The derived key is not logged (`error_log` is the exception class). PHP and the edge helper match the shared vector.
4. The row `ops_backup_job:<id>` is claimed in one unit keyed by that name: `NOT EXISTS` plus insert, or an update of the exact old value. PostgreSQL races 15/15 made one backup (409 for the waiter). A running claim younger than 2 h is 409. The name is not a Control Panel key, so export omits it. Restore imports that export.
5. Part keys written by `store()` are `part-00001` under `backups/<id>/`. `get()` requires that exact key, size and lowercase sha256, and the sizes must sum to the backup. `..`, an absolute key and a full key are rejected. A manifest with no `files` list is still read. Exports are left out of `prune()`. The answered key is relative; the Worker prefix is `installations/<do_name>/`.
6. Changelog, README and VERSION: released sections and VERSION `3.43.0` match `58135eee`. One Unreleased bullet was added. It linked pull/318, a different open PR (L-1892); `c0fe6cd1` relinks it to #320. Suites fail only the known zip-entry case. CMYK did not run (no Imagick). No deadlock flake. Edge 120 pass. D1 workerd 323/1237.
7. Delta and merge-only reads. `c0fe6cd1` changes only that link. At `6c5060e1` the C2 own diff over `307f40f3` was byte-equal to the `9c73ed75` diff over `dc1d232b`. At `b138a3c7` the merge of main conflicted in CHANGELOG, `docs/HANDOFF.md`, `docs/operator-console.md` and `edge/tests/r2.test.mjs`. The own diff over main (excluding CHANGELOG and docs/qa) is byte-equal to `307f40f3..6c5060e1`, so no C2 change was altered. The +3 lines are the new `### Added` heading. The changelog preamble and released sections equal main. README and VERSION equal main.

## Findings

L-1892, closed at `c0fe6cd1`. The bullet had linked [#318](https://github.com/thordin9/apotheke/pull/318) instead of #320.

N-1893. `Backups::create` reads each table in its own statement. It does not start a transaction and does not take the restore fence. A commit between two reads can mix old and new tables on SQLite, PostgreSQL and D1. The live catalog is not written.

N-1894. `set_time_limit(900)` limits CPU time. The takeover waits 2 hours of wall time. A backup still inside `store()` after that can be claimed again. The first `settle` then does not match its token. A hard kill can leave parts with no manifest; `get()` ignores them and `prune()` does not delete exports.

Next id 1896.

## Open points

(a) Deriving the signing key from APP_KEY is acceptable. The Worker already holds that key to start the container. The HMAC context separates it from other uses. Rotation applies to the next request; nothing signed is stored. A leak of APP_KEY already exposes the installation. A missing key is 503, not an empty HMAC.

(b) The call is synchronous. `ignore_user_abort` lets a disconnect finish and record the backup; a retry in the 2 hours gets 409, and a finished job returns `created: false`. See N-1894 for a request that outlives the 2 hours, and for parts left by a hard kill.

(c) The export is not a snapshot and is outside the fence. See N-1893. A concurrent restore does not block it and does not corrupt the live database. It can make the zip inconsistent if it commits during the dump.

## Security

The public Worker path cannot present `ops.internal` without the forwarded headers the endpoint refuses. The signature binds method, path and body, and a replay of one job does not make a second backup. The job row is claimed under its own lock.

## Verdict

PASS at `b138a3c7`. No high or medium finding. L-1892 is closed. N-1893 and N-1894 remain as notes.

CI on this head so far: Analyze, CodeQL, D1, Lint, deptrac, DI rule, Secret scan, Conventional Commits and both Upgrade and rollback jobs pass. Tests (sqlite, pgsql), Docker, Headless mobile, Visual E2E and the API dry run are pending. Tests must be green before merge.
