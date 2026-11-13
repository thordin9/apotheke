# QA: apotheke #295, check-dist URL-resolution hardening

- PR: https://github.com/thordin9/apotheke/pull/295 (Front-End), base `main`. Follow-up to N-1537.
- Reviewed head: `5224f13ee7e4c6930fd79a8394bc0bcd5ec703a4` (Merge remote-tracking branch 'origin/main' into cursor/check-dist-urls-4d62). Parents `427b01b0c07fe9fabede073d85a47bde24174b61` (previous content PASS) and main `9ab59945b735dac5960ef5f8fedc08c414e48bc2` (`ci:` #306, 10:32:23 Europe/Athens, UTC+2). 0 behind, 16 ahead (the previous 15 plus this merge), mergeable_state blocked (required CI still running), open. This is a merge-only read. The held doc at `427b01b0` (sha256 `9ab6dedd…`, 4603 B) is void.
- Earlier reviews: PASS at `ed89d547`, `101a22a5`, `6ec499b8`, `b7352ce7` (N-2067, N-2068), `05bc2939` (N-2069) and content PASS at `427b01b0`. No other commits since `427b01b0` besides this plain merge.
- PR title: "test(frontend): stricter URL resolution in check-dist" (53), fit for the squash. The merge subject is exempt.
- Gate: merge-only read (diff compare, CHANGELOG, CI). No suites, mutations or Docker. Reviewer: Apotheke QA 2

## Checklist

- [x] PR head is exactly `5224f13e`. A plain merge of main `9ab59945`. First-parent `427b01b0..5224f13e` contains only this merge. behind_by 0.
- [x] CI on `5224f13e` is not finished. 24 check runs at review time: 16 success, 1 skipped (Deploy getapotheke-app, as intended on PRs), 7 in_progress, 0 failed. mergeable_state blocked. Already green: Frontend 43396262399 (Build, completed 10:50:31 Europe/Athens, UTC+2), Deploy frontend 43396262537 (Build and check; Deploy getapotheke-app skipped, 10:50:07), and PR #295 43396255208 (Analyze / CodeQL). CI 43396262391 is still running (started 10:49:34); in progress: Tests (PHP 8.4, sqlite and pgsql), Visual E2E shards 1–3, and both Docker build + smoke tests (PHP 8.4 and FrankenPHP). After the docs(qa) commit, CI must be green on that head before merge.
- [x] Merge-only read of `5224f13e`. Per-file +/- of `9ab59945...5224f13e` equals `4786e44d...427b01b0` on the two check-dist files: `frontend/scripts/check-dist.mjs` +80/−21 and `frontend/scripts/check-dist.test.mjs` +97/−1. Those two files are byte-identical to `427b01b0`, and their patches are byte-identical including hunk line numbers (main did not touch them). The #295 check-dist hunks survived the merge.
  - `CHANGELOG.md` is +1 vs main, vs +4 at `4786e44d...427b01b0`. Expected: the old diff also inserted the `### Changed` heading because `[Unreleased]` was empty; main already has that heading from #306, so only the #295 bullet is added. The #295 bullet (full PR URL) and the #306 bullet both sit under `[Unreleased]` / `### Changed`, above `## [3.42.2]`. The header through `## [Unreleased]` (494 bytes) and everything from `## [3.42.2]` down (191738 bytes) are byte-identical to main `9ab59945`. `#295` appears only in `[Unreleased]`, never in a released section.
- [x] Scope unchanged: the tree differs from main only in those two files and `CHANGELOG.md`. Only CI tooling changes. No secrets.

## Status of the earlier findings

- **N-2067: closed.**
- **N-2068: closed.**
- **N-2069: closed.** Unchanged from the `427b01b0` content PASS.

## New findings

None. 0 High, 0 Medium, 0 Low, 0 Nits.

## Verdict

**PASS on content** at `5224f13e`, 0 behind main `9ab59945` (#306). All earlier findings remain closed. CI is still running on this head; Frontend and Deploy frontend are already green. Squash-merge with the PR title as the subject only once CI on the docs(qa) head is green.

#289 also edits `check-dist.mjs` and `check-dist.test.mjs` (the landing CSP constant and rule, and the first test). The hunks don't overlap, but whichever of the two merges second needs its catch-up re-read for these files.

Commit this doc last as a docs(qa) commit, byte-exact. The held doc (sha256 `9ab6dedd…` at `427b01b0`) is void. If main moves, the PR needs a catch-up, a merge-only read and a reissue.
