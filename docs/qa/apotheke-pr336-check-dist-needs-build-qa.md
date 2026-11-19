# PR #336 check-dist needs a build QA

Head `dfedecc3b60275531334c1b06f7cf6ed1a6bad33` (live `refs/pull/336/head` at the verdict). Main `b1fd288264e4bd6b805cb5ee0c73430b8db5d091` is an ancestor, 0 behind. Full gate at `fd6a97e4`, then a delta for `f8dbc286` (merge of main) and `dfedecc3`.

## Checklist

1. Four files: `frontend/scripts/check-dist.mjs`, `frontend/scripts/check-dist.test.mjs`, `frontend/README.md`, `docs/HANDOFF.md`. No PHP, no `frontend/src`, no `.github/workflows` file. `check-dist.mjs` is the checker the workflow already runs. Its missing-directory path prints the build instruction and exits 1 before the other checks.
2. `f8dbc286` merges `fd6a97e4` and main `b1fd2882` (#335). Its own patch equals the gated one. HANDOFF only gains main's RestoreFence line as context. CHANGELOG equals main. README and VERSION have no diff.
3. `dfedecc3` edits only the two check-dist scripts. `getDist()` returns `frontend/dist` when `APOTHEKE_CHECK_DIST` is unset or empty. A non-empty value is the directory it checks. Nothing else reads the variable.
4. With `frontend/dist` absent, the suite is 17 pass, 3 fail, 0 skipped. The three URL tests fail with `frontend/dist is missing (run npm ci and npm run build in frontend/)`, `ERR_TEST_FAILURE`, not a skip. `node frontend/scripts/check-dist.mjs` exits 1 and prints only that line.
5. After `npm ci` and `npm run build` in `frontend/`, the suite is 20/20, 0 skipped, and the script prints `dist check passed`. The three tests still assert http is rejected, https and mailto are allowed, and relative links resolve from the page.
6. With `dist` present, the new test fails if any one `requireDist()` call is removed, if all three are removed, if `getDist()` ignores the variable, or if the message drops the build instruction. Changing `DIST_REQUIRED` also fails.
7. `.github/workflows/frontend.yml` is unchanged. Its Build job runs `npm ci` and `npm run build` in `frontend/` before `check-dist.test.mjs` and `check-dist.mjs`.
8. N-2147 in `docs/qa/apotheke-pr289-privacy-session-cookie-qa.md` is closed. A bare run still fails those three tests, now with the build instruction, and the README names the same step. CI still builds first. No fixture `dist` was added.

## Findings

L-1913 is closed: removing the `requireDist()` calls is now caught with `dist` present. No new finding. Next id 1914.

## Security

The checker reads the local `frontend/dist` tree, or the directory in `APOTHEKE_CHECK_DIST`, which only a local or CI operator sets. When that directory is absent it exits before those reads. When present it still runs the header, link, and file checks. Nothing here takes a path from a request.

## Verdict

PASS at `dfedecc3`. No high or medium finding. L-1913 and N-2147 closed. N-2151 is the same item as N-2147, handled here.

Squash subject, 60 characters: `test(frontend): fail fast when check-dist has no dist (#336)`.

CI on this head, as it stood: Analyze, Build, Build and check, CodeQL, conventional commits, D1, DI, Compose FrankenPHP, Lint, secret scan, both upgrade jobs, deptrac, and Headless mobile pass. Deploy getapotheke-app is skipping. Compose Dockerfile, both Docker builds, PHP sqlite and pgsql, and the three visual shards are pending.
