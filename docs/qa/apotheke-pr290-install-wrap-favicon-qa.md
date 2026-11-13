# QA: apotheke #290, wrap install commands and serve favicon.ico

- PR: https://github.com/thordin9/apotheke/pull/290 (Front-End), base `main`. Fixes L-1930 and L-1931 from the 2026-11-12 live audit.
- Approved head: `478a7c5d1bb4fe3229aaa809dc91995c6577072b`, a plain merge with parents `bc6aa9eb` (PR) and main `ff3d676e` (#310). 0 behind main `ff3d676e`, 11 ahead, mergeable (clean). Author and committer thordin9.
- Chain since the last PASS at `02d0b3de`: plain merges only, `a408c399` (main `3be6990d`, v3.37.1), `d2d0d4e8` (main `5fe1f141`, v3.37.2) `bc6aa9eb` (main `c4fee3ce`, v3.42.3) and `478a7c5d` (main `ff3d676e`). No non-merge commits. Merge subjects (89) are git-generated and exempt. PR title: "fix(frontend): wrap install commands and serve favicon.ico" (58).
- Gate: merge-only read of all four merges, a careful read of the conflicted files, and the CI frontend steps run locally on `bc6aa9eb` with one mutant. `478a7c5d` merged cleanly and brings only #310's 4 files (test and docs, no frontend or CHANGELOG), identical to `c4fee3ce...ff3d676e`.
- CI on `478a7c5d`: CI 43414387839, Frontend 43414387940, Deploy frontend 43414387844 and PR #290 43414380260 all succeeded. 24 check runs: 23 success, 1 skipped (Deploy getapotheke-app, PR event).
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only: for each of the 11 files, the +/- lines of `main...478a7c5d` equal those of `c4fee3ce...bc6aa9eb`, `5fe1f141...d2d0d4e8` and `3be6990d...a408c399`. Against `2e62bf69...02d0b3de` the only change is the CHANGELOG blank line (N-1989). The tree differs from main only in the 11 PR files (+59/-5).
- [x] `check-dist.mjs`: main's exported `expectedCache` is kept, and `/favicon.ico` joins the one-day condition with the brand icons. `run()` also requires `dist/favicon.ico`. #295's URL resolution and #289's landing connect-src are untouched. No duplicate functions, no conflict markers.
- [x] `check-dist.test.mjs`: 18 tests, the same names as main, and no duplicates. The only change adds `/favicon.ico` to the `max-age=86400` list.
- [x] `tests/e2e/app.spec.ts`: 34 tests. All of main's tests are present, the privacy and Decline tests included, and the only addition is the install-wrap test at 320, 390 and 421 px. No duplicate names.
- [x] CHANGELOG: `[Unreleased]` has only `### Fixed` with the #290 bullet and its full URL, followed by a blank line before `## [3.42.3]`. The header and everything from `## [3.42.3]` down are byte-identical to main (192772 bytes). #290 is in no released section.
- [x] Run as CI does (scratch copy, `npm ci && npm run build`): contrast 90/90, node tests 39/39, `check-dist` passed, `dist/favicon.ico` present (980 B). CI Frontend (Node 22) matches: 39/39, dist check passed, 90/90, e2e 50 passed.
- [x] Mutant: removing `/favicon.ico` from the one-day condition makes `check-dist.mjs` fail (exit 1, "/favicon.ico Cache-Control ... want no-cache"). The unit tests still pass, because they check `_headers` rather than `expectedCache`. Restored, it passes.
- [x] No secrets. The only credential-like text is the documented placeholder `APP_PASSWORD=change-me`.

## Status of earlier findings

- **N-1942, N-1975: closed** at `02d0b3de`. The install HTML, CSS and test are unchanged since.
- **N-1989: closed.** The blank line before the next release heading was added in the `a408c399` merge and kept through `bc6aa9eb`.

## New findings

None. 0 High, 0 Medium, 0 Low, 0 Nit open. L-1930 and L-1931 are closed in this PR. The live effect is verified only after deploy.

## Verdict

**PASS** at `478a7c5d`, confirmed by the merge-only read against main `ff3d676e`. Squash-merge with the PR title as the subject once CI on the doc commit is green. If main moves before merge, the PR needs a plain catch-up, a merge-only read and a reissued doc. Earlier versions of this doc (sha256 `e9f3aa7c…`, `c7d2823f…` and `429a028f…`) are void.
