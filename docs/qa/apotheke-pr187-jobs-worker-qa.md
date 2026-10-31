# QA: PR #190 (replaces #187), background jobs worker in both images (final re-check, delta at `0cc3908`)

- **Head: `0cc3908aeef44d0aa738ca76c2c053e28a205325`** (`cursor/jobs-worker-v2`, DevOps). Fully reviewed at `549e543`. `0cc3908` is a fast-forward of one commit, `docs(changelog): link #190 instead of closed #187`, and got a light delta check (diff read and CI). #190 replaces #187: #187's head `3309335` failed the Conventional Commits check (two subjects too long), so DevOps re-pushed the same work on a new branch with three reworded commits instead of force-pushing. #187 is closed, not merged. Its branch is still at `3309335`, so no pushed history was rewritten. Last PASS: #187 at `b0c0b5b` (0H/0M/1L/4N). Reviewed 2026-10-31 (EET).
- **CI at `0cc3908`:** 22 check runs. 21 passed (Conventional Commits and CodeQL included) and `Deploy apotheke-api` was skipped, as expected on a PR. The branch is 0 behind `main` (`2843df5`), and GitHub reports it mergeable and clean. Same result at `549e543`. `docker-jobs-checks.sh`: 61 `ok` on FrankenPHP and 67 on nginx, with no failures.

## Checklist

| Check | Result |
|---|---|
| #190 vs #187 | Both branch from `b0c0b5b`. `e584f52`, `5b1b9db` and `9cd2ef9` are rewordings of `861885b`, `8b0d53f` and `3309335`, with the same trees (`9cd2ef9` = `3309335`, tree `3df25e4`). Delta since `b0c0b5b`: `d144b38` (worker fix), `e584f52` (tests), `9cd2ef9` (deploy gate), and main merges `5b1b9db`, `0067f92` (#183) and `d707771` |
| Main merge `d707771` | `9cd2ef9..d707771` touches the same 40 files with the same changed lines as `main` `0a1ee97..1c5d2a2`, except `docs/deployment-and-operations.md`. CHANGELOG: `[Unreleased]` sits above 3.6.1 and 3.6.0, and nothing is dropped. Background-jobs intro: keeps main's `import.commit` sentence, drops "the image does not start the worker yet", and states that both images run the worker. Accurate. Main's 300 s lease paragraph is kept |
| Flip `87a89c2` | Exactly 4 files. `wrangler.jsonc`: the value stays `getapotheke.com`, and the comment now says consent cookie only, never `__Host-`. Compose: the reserved block is removed, with one line added on #188's passthrough. The deploy-docs paragraph matches #188's Cookie consent section. In the CHANGELOG, only the edge bullet's `COOKIE_DOMAIN` clause changed. No "reserved"/"not read" wording is left in the docs |
| Merge `549e543` | `diff 87a89c2 549e543` is byte-identical to `diff 1c5d2a2 2843df5` (#189) |
| Worker `d144b38` | `spawn` checks `stopping` before each `&`, including the backoff sleep. After a stop, `reap` and both entrypoints' `end()` re-send the signal every 0.2 s until the child has exited. Both entrypoints check the flag before each `&`. The FrankenPHP worker-off path clears its trap before `exec` |
| Harness (busybox 1.37 ash, Alpine 3.22, stub `php`) | Head: 0 of 80 stops lost (40 right after start-up, 30 around the forced restart, 10 in the backoff sleep), each within 1 s, exit 0. Mutant "no re-send in `reap`": 2 of 40 hung. That mutant and "no flag check before `&`" are each caught by `DockerfileTest` |
| CI stop races (`e584f52`) | 10 stops at random times after a kill and 4 right after start-up, on each image: all exit 0 within 3 s. A clean stop of the web server alone (FrankenPHP TERM; nginx or php-fpm QUIT) makes the container exit 1 and logs *exit 0*. A KILL still gives 137 |
| Deploy gate `9cd2ef9` | The job `if:` starts `vars.EDGE_STORAGE_READY == 'true' && (`, before `environment: deploy-edge`. The in-job gate is kept. The guard, `DeployWorkflowsTest` and the docs have not changed since `9cd2ef9` |
| Local runs | PHPUnit (PHP 8.4.24, sqlite): 434 tests OK (28 skipped, 1 incomplete). `edge/` `npm run check` passes on TS 7.0.2. shellcheck and actionlint report nothing. `release.php check-links` passes |
| Delta `0cc3908` | Only `CHANGELOG.md`, +3/-3: the three `[Unreleased]` links change from `pull/187` to `pull/190`, and nothing else changes. No `pull/187` link is left in the CHANGELOG |
| Squash simulation | `release.php apply` after a squash `feat(docker): … (#190)` writes a clean 3.7.0. The handwritten `docker` and `edge` bullets replace the generated `(#190)` line |

## Findings

**H / M / L:** none. **M-1481 is fixed** at `0cc3908`: all three `[Unreleased]` bullets now link #190 instead of the closed #187. **L-1470 is fixed** (see the checklist).

**N**
- **N-1473 (accepted):** squash-merge #190; that is the condition. `docs/releasing.md:26` still says "this repository merges with merge commits", to be fixed in a later follow-up.
- **N-1475:** accepted. **N-1476 / N-1477:** optional, still open.
- **Resolved:** M-1481, L-1470, N-1480 (CI now covers web exit 0 → 1), and N-1482 (#190's description is updated: `COOKIE_DOMAIN` active, main through v3.6.1 and #189, squash merge, `0cc3908` listed).

## Security

No secret appears in the diff, the logs, the tests or this doc. `COOKIE_DOMAIN` is a non-secret var that applies only to the consent cookie, and the `__Host-` session cookie never gets a `Domain`. The deploy gate only adds a skip condition. No request-path code changed outside main's merged PRs.

## Verdict

**PASS** at `0cc3908aeef44d0aa738ca76c2c053e28a205325`: 0H/0M/0L/3N (N-1473 and N-1475 accepted; N-1476 and N-1477 optional). Condition: squash-merge #190 (N-1473).
