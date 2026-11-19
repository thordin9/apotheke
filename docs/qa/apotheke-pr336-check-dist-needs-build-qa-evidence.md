# PR #336 evidence

Final head `dfedecc3b60275531334c1b06f7cf6ed1a6bad33`, 0 behind main `b1fd288264e4bd6b805cb5ee0c73430b8db5d091`; see Delta below. Full gate head `fd6a97e46ecd047a28781004fba73d11f3cc8856`. Live `git ls-remote origin refs/pull/336/head` returned that SHA immediately before the verdict. `refs/heads/main` was `a3689d892794e3cf6bf7546b3ce3ede543a53f7d`. The head is 0 behind that main. Commits: `0eeb0440` and `fd6a97e4`.

## Files

`git diff --name-only a3689d89...HEAD`:

- `docs/HANDOFF.md` (+1): the open-PR note.
- `frontend/README.md` (+3/−1): the test line now says the URL tests and `check-dist.mjs` need `frontend/dist`, and names `npm ci` and `npm run build`.
- `frontend/scripts/check-dist.mjs` (+19/−1): exports `DIST_REQUIRED`, `missingDistMessage`, and `requireDist`. `run()` exits 1 with `FAIL  ` plus that message when `frontend/dist` is absent, instead of pushing a failure and continuing.
- `frontend/scripts/check-dist.test.mjs` (+12/−1): one new test, and `requireDist()` at the start of the three URL tests.

No workflow file, no PHP, no `frontend/src`.

## Runs

Node v22.23.4. `frontend/dist` was absent at the start.

Without `dist`: `node --test frontend/scripts/check-dist.test.mjs` exit 1, `# tests 19`, `# pass 16`, `# fail 3`, no skipped line. The three failures are `resolves rejects http and allows https and mailto`, `a link to another host must name https`, and `relative links resolve from the page that contains them`. Each error is `frontend/dist is missing (run npm ci and npm run build in frontend/)` with `code: 'ERR_TEST_FAILURE'`. `node frontend/scripts/check-dist.mjs` exit 1, one line: `FAIL  frontend/dist is missing (run npm ci and npm run build in frontend/)`.

With `dist`: `npm ci --ignore-scripts --no-audit --no-fund` and `npm run build` in `frontend/` (vite 8.3.7). The test file exit 0, `# tests 19`, `# pass 19`, `# fail 0`, `# skipped 0`. The script exit 0, `dist check passed`.

`resolves()` still returns `missing in dist` when an on-site path is not a file under `dist`, and `ok: false` for `http:`. The three tests still assert those results after `requireDist()`.

## Mutation

Shortening `DIST_REQUIRED` to `frontend/dist is missing` fails the new test (18 pass, 1 fail). Deleting the three `requireDist();` lines and running with `dist` present stays 19/19. The file was restored. `git diff` was empty. `frontend/dist` and `frontend/node_modules` were removed afterwards.

## N-2147

`docs/qa/apotheke-pr289-privacy-session-cookie-qa.md` says a bare run fails the same three tests because `resolves()` reads `frontend/dist`, that CI builds first, and that a follow-up could use a fixture dist or note the build step. This head notes the step in the README and in the failure. The three tests still fail without a build, on purpose. CI Frontend Build on this head is pass, and `frontend.yml` still builds before both commands. N-2147 is closed. N-2151 is QA 2's alias for it.

Subject: `test(frontend): fail fast when check-dist has no dist (#336)` is 60 characters.

CI on this head, not waited on: Analyze (actions), Analyze (javascript-typescript), Build, Build and check, CodeQL, Conventional Commits, D1, DI, both Compose jobs, Headless mobile, Lint, Secret scan, both upgrade jobs, and deptrac pass. Deploy getapotheke-app is skipping. Docker build, Docker FrankenPHP, PHP sqlite, PHP pgsql, and three visual shards are pending.

## Delta

Head `dfedecc3b60275531334c1b06f7cf6ed1a6bad33` (live `refs/pull/336/head` at the verdict). Main `b1fd288264e4bd6b805cb5ee0c73430b8db5d091` is an ancestor, 0 behind. Last PASS `fd6a97e4` is an ancestor.

### Checklist

1. `f8dbc286` is a merge. Parents are `fd6a97e4` and `b1fd2882`. `b1fd2882` is main (`#335`). Against that main the merge touches the same four files as `a3689d89...fd6a97e4`. `frontend/README.md` and both check-dist scripts are the same patch. `docs/HANDOFF.md` adds the same bullet. The extra context is main's RestoreFence line. CHANGELOG equals main, preamble and released sections included. README and VERSION have no diff.
2. `dfedecc3` edits only `frontend/scripts/check-dist.mjs` and `frontend/scripts/check-dist.test.mjs`. `getDist()` returns `frontend/dist` when `APOTHEKE_CHECK_DIST` is unset or `''`. A non-empty value is the directory it checks. Those two files are the only readers. No PHP and no workflow change.
3. With `frontend/dist` absent: 17 pass, 3 fail, 0 skipped. The three URL tests throw `frontend/dist is missing (run npm ci and npm run build in frontend/)`, `ERR_TEST_FAILURE`. The new test passes. The script exits 1 with that one line. After `npm ci` and `npm run build`: 20/20, 0 skipped, and the script prints `dist check passed`.
4. With `dist` present, the new test fails if any one `requireDist()` call is removed, if all three are removed, if `getDist()` ignores the variable, and if the message text drops the build instruction. Each edit was restored.

### Findings

L-1913 is closed. No new finding. Next id 1914.

### Verdict

PASS at `dfedecc3`. No high or medium finding.

CI on this head, not waited on: Analyze, Build, Build and check, CodeQL, conventional commits, D1, DI, Compose FrankenPHP, Lint, secret scan, both upgrade jobs, deptrac, and Headless mobile pass. Deploy getapotheke-app is skipping. Compose Dockerfile, both Docker builds, PHP sqlite and pgsql, and the three visual shards are pending.
