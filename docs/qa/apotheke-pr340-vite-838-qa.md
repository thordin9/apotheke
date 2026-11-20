# QA: apotheke #340, vite 8.3.7 → 8.3.8 (/frontend)

- PR: https://github.com/thordin9/apotheke/pull/340 (Dependabot, with two main catch-up merges from DevOps)
- Reviewed head: `b3f7edc739a6736809fd911972334d13d8903270`, the plain merge of `d68f3abf` and main `3a6156b6` (v3.57.0). 0 behind main `3a6156b6ada5c9ff27c92ecbfdfbc70588f42484`, 3 ahead, mergeable (clean). Parents are `d68f3abf` and `3a6156b6`.
- Prior review: light **PASS** at `396a91b082ca963923a2ab0b21cd61592653de48` (5 behind then-main `c62dafd8`). This reissue supersedes that doc.
- History: 3 commits. Non-merge subject: `build(deps-dev): bump vite in /frontend in the frontend group` (61). Merges exempt. The GitHub PR title `build(deps-dev): bump vite from 8.3.7 to 8.3.8 in /frontend in the frontend group` is 81 characters, 88 including " (#340)" (see L-2430). The PR was not retitled.
- Gate: merge-only read of the catch-up onto `3a6156b6` (commits `396a91b0..head`, parents, merge-tree, numstat, CHANGELOG/VERSION, compare). Prior light gate at `396a91b0` is unchanged; no mutants, no suites, no image rebuilds.
- CI on `b3f7edc7`: Frontend 44777901484, Deploy frontend 44777901253, CodeQL (PR #340) 44777888683 and CI 44777901538 (run attempt 2) all succeeded. Check-runs: 23 pass, 1 skipped (Deploy getapotheke-app). Attempt 1 of CI 44777901538 failed only on Headless mobile checks (PHP 8.4) with puppeteer-core `TimeoutError: Timed out after 30000 ms while waiting for the WS endpoint URL to appear in stdout!`; the same job passed on the rerun. Unrelated to the vite bump (Headless mobile uses puppeteer-core and the runner's Chrome). The Frontend "Build" job is among the passes.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only: commits `396a91b0..b3f7edc7` are two plain `--no-ff` merges. `d68f3abf` parents are the prior reviewed head `396a91b0` and main `34706c47` (#346 squash). `b3f7edc7` parents are `d68f3abf` and main `3a6156b6` (v3.57.0). `git merge-tree --write-tree` of each pair equals that merge's tree (`cdf0b72a…` and `b390e22b…`); no hand resolutions. `gh api compare` behind_by is 0 against current main.
- [x] Scope vs main `3a6156b6`: 2 files, same numstat as `19ed560a...396a91b0`: `frontend/package.json` +1/−1, `frontend/package-lock.json` +4/−4. Those two files on the head are byte-identical to `396a91b0`. CHANGELOG and VERSION are byte-identical to main. Nothing dropped.
- [x] Prior light gate (blobs unchanged): only `vite` 8.3.7 → 8.3.8 (exact pin). Lock changes only `node_modules/vite`. Every `resolved` URL is `registry.npmjs.org`; the vite 8.3.8 integrity matches the npm registry. No new or removed install scripts. Upstream 8.3.8 (https://github.com/vitejs/vite/releases/tag/v8.3.8, 2026-11-11) is a patch of bugfix, perf, chore and test commits. It rejects CSS files used as build entrypoints when `build.cssCodeSplit` is `false`. This repo sets `cssCodeSplit: false` but the rollup inputs are HTML only, so that rejection does not apply.
- [x] CHANGELOG and VERSION are identical to main. Per #243 (`docs/qa/apotheke-pr243-wrangler-4157-qa.md`) and #312: `scripts/release.php` cuts no release for `build` or `ci` types, so a hand-written bullet is not needed.

## Findings

| ID | Grade | Finding |
|---|---|---|
| L-2430 | Low | The GitHub PR title is 81 characters, 88 including " (#340)", over the 72-character Conventional Commits limit. The branch commit itself is within the limit (61). The PR was not retitled. Retitle (or squash with) `build(deps-dev): bump vite from 8.3.7 to 8.3.8 in /frontend` (59, 66 including " (#340)"), not the PR title as-is. |

0 High, 0 Medium, 1 Low, 0 Nit.

## Verdict

**PASS** at `b3f7edc7`, 0 behind main `3a6156b6` (v3.57.0), confirmed by the merge-only read. Highs and Mediums block; none are open. Squash-merge with `build(deps-dev): bump vite from 8.3.7 to 8.3.8 in /frontend (#340)` once the docs(qa) commit is on the branch and CI on that commit is green. A `build` change does not cut a release. The PM merge order is #340 first; #342 and #343 are also 0 behind now and will each need a catch-up, a merge-only read and a reissued doc after this lands (and after each later one). #341 follows #340 under the same rule. If main moves before this merges, it also needs a plain catch-up, a merge-only read and a reissued doc. This reissue supersedes the doc written for `396a91b0`.
