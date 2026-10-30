# QA: PR #178, FrankenPHP production image (delta re-check after merging main)

- **PR / head:** #178 `build(docker)` -> `main` (DevOps), same repo, not draft, mergeable clean. Head `df175ba19eb943a00fe83e3096526194bb13ad2d`, 14 commits, 23 files, +1116/-49, base `cbd26c5` (#182). Delta from my PASS at `627fb3ea4a9deb03252d89c686ef47d5707f365a`: merge `35ba537` (parents `627fb3e`, `cbd26c5`) and `df175ba` (`test(docker): send consent cookie in #178 checks`, 2 files, +19/-12). Compare `627fb3e...df175ba`: ahead 3 (`cbd26c5`, `35ba537`, `df175ba`), behind 0, so no force-push.
- **CI:** 20/20 check runs success on the exact head (2026-10-30 22:50 to 23:01 EET), incl. both required `Tests (PHP 8.4, sqlite)` and `Tests (PHP 8.4, pgsql)`, both Docker legs and both Compose legs; no legacy statuses. Job logs: FrankenPHP leg 214 `ok` / 0 `FAIL`, nginx leg 190 `ok` / 0 `FAIL` (the count rose from 184/160 because of #182's own checks).
- **Scope:** delta only, read-only review on the box (no Docker, so no image runs; I relied on CI for the runtime checks). Re-checked 2026-10-30 (EET).

## Checklist

| Check | Result |
|---|---|
| Merge resolution | Rebuilt the merge of `627fb3e` and `cbd26c5` from base `f1eee32`: the same 3 conflicts. Every other file in `35ba537` equals the clean merge: #178-only files are byte-identical to `627fb3e` and #182-only files to `cbd26c5`. The 6 files both sides changed without conflict (`Routes.php`, `RoutePermission.php`, `docker-compose.yml`, `deployment-and-operations.md`, `docker-auth-checks.sh`, `PermissionFilterTest.php`) match git's auto-merge |
| Conflicts | `.env.example`: both blocks kept (image/threads, then `COOKIE_CONSENT`), with a blank line between them. `RoutesCompiled.php`: only the hash line conflicted; the new hash matches a recomputation from the merged `Routes.php`, and `CompiledRoutesTest` passes in CI. `docker-checks.sh`: #182's consent form of the 30 MB restore line, then all of #178's h2c, slow-upload and slow chunked-GET checks. Nothing from either side was dropped |
| #178 code | `docker/frankenphp/Caddyfile`, `Dockerfile.frankenphp`, the PHP ini and the workflow are byte-identical to `627fb3e` |
| `df175ba` intent | Consent is added only where #182's 403 would otherwise answer first, so each asserted status still comes from the mechanism under test. Slow uploads and slow chunked GETs: the clients now look like an accepted browser, so the web server alone holds them (a stronger test). Small POSTs: `419` is the CSRF answer, not a 403. Anonymous chunked POST: `401/303` from the sign-in check. Anonymous PUT restore: the app's `303`, which still tells it apart from the probe's 401. Behind the proxy: `jar2` starts with consent so sign-in works, and the signed-out 300 KB `/save` and restore `401` come from the sign-in probe only. The 411 checks and the helpers are unchanged and still have no consent; the anonymous restore checks, the 600 MiB restore and the anonymous 300 KB `/save` also stay without consent, so the probe's 401 without consent stays covered. No expected status was loosened or changed |
| CI evidence | Every changed check prints `ok` in the matching leg's log: the FrankenPHP leg for the FrankenPHP-only ones and both legs for the rest (small POSTs, slow uploads in flight, anonymous chunked POST, PUT restore, all 8 behind-the-proxy checks, the 30 MB restore, h2c, 411, 521/600 MiB) |
| Lint | shellcheck 0.10.0 is clean on `docker-checks.sh`, `docker-auth-checks.sh` and `docker-log-checks.sh` at the head. The commit subject passes Conventional Commits |
| Docs / CHANGELOG | `df175ba` touches no docs. Every CHANGELOG and docs change in the delta comes from main (#182) and is byte-identical to `cbd26c5`; there is no hand edit to the CHANGELOG |
| Main moved | main is now `c057394` (`chore(release): v3.4.0`, CHANGELOG/README/VERSION only). A merge-only delta is expected next, and I'll re-check it then |

## Findings

### H
None.

### M
None open. M-1420, M-1450, M-1451 and M-1454 stay fixed (the Caddyfile is unchanged and their checks pass on both images).

### L
None open. L-1421, L-1422 and L-1423 stay fixed.

### N
- **N-1452 (residual, accepted)** and **N-1453 (accepted, optional):** unchanged from the previous doc.
- N-1455 stays fixed.

No new findings.

## Security

The delta changes only test inputs; server config is untouched. Consent is sent only where the consent gate would otherwise mask the mechanism under test, and checks without consent still cover the probe's refusal. No secrets or identifiers were printed or committed.

## Verdict

**PASS** at `df175ba19eb943a00fe83e3096526194bb13ad2d`: 0H / 0M / 0L / 2N (N-1452, N-1453, both accepted). The merge is faithful, the #178 code is unchanged, the consent changes keep every check's intent, CI is 20/20 and shellcheck is clean. Only merge-only deltas from main or the docs(qa) commit may follow. Merge #178 before #180.
