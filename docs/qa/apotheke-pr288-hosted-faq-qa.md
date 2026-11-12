# QA: apotheke #288, say hosted Apotheke is invitation-only

- PR: https://github.com/thordin9/apotheke/pull/288 (Front-End), base `main`. Fixes the landing-FAQ half of M-1922 from the 2026-11-12 live audit.
- Approved head: `21d95fcffdaf3fb6e79bbc1b8aa82b24b116a5b1`, 0 behind main `5e2d3471` (v3.37.0), 8 ahead, mergeable (clean). Content confirmed at `4421ef34` (below); `21d95fcf` confirmed by a second merge-only read.
- Merge-only read, `4421ef34` to `21d95fcf`: `e60d0508` (doc commit, sha256 `29d1f84b…`, now void) and `21d95fcf`, a plain merge of main `5e2d3471` (parents `e60d0508`, `5e2d3471`; brings #292 and v3.37.0). The +/- lines of `main...21d95fcf` equal those of `2e62bf69...4421ef34` for `CHANGELOG.md`, `frontend/index.html` and `tests/e2e/app.spec.ts`, and the last two are byte-identical to `4421ef34`. CHANGELOG differs from main `5e2d3471` only by the #288 [Unreleased] bullet, with a blank line before `## [3.37.0]`. CI on `21d95fcf`: CI 43269231065, Frontend 43269231588, Deploy frontend 43269231069 and PR #288 43269218512 all succeeded.
- Earlier content reviews: PASS at `8a94ce7b`, and a content gate at `5a2db220`.
- Commits since 5a2db220: plain merges `4a68cead` and `de3eaabe` of main (#287, #285, #291, #286 and releases v3.36.7–v3.36.10), then `4421ef34` docs: separate the unreleased changelog section (47 chars). The PR's own subjects are 53, 51 and 47 chars. The merge subjects (77) are git-generated, and the squash uses the PR title (53).
- Gate: light merge-only read (gh api compare, tarball byte-compare, CI). No suites, mutations or Docker.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only: for each file, the +/- lines of `main...4421ef34` equal those of `48d2fbc1...5a2db220`. The files are `frontend/index.html`, `tests/e2e/app.spec.ts` and `CHANGELOG.md`. `frontend/index.html` is byte-identical to 5a2db220. The tarball differs from main `2e62bf69` only in those 3 files, so every other file is byte-identical to main.
- [x] CHANGELOG: [Unreleased] has only `### Fixed` with the #288 bullet and its full URL (https://github.com/thordin9/apotheke/pull/288), with a blank line before `## [3.36.10]`. From `## [3.36.10]` down it is byte-identical to main (180397 bytes), and so is the text above [Unreleased].
- [x] CI on 4421ef34: CI 43255750484, Frontend 43255749859, Deploy frontend 43255750139 and PR #288 43255734850 all succeeded. 23 check runs succeeded and Deploy getapotheke-app was skipped (intended).

## Findings

- **N-1935** (unchanged): there is no invite-token flow. "Invitation-only" is accurate as a policy (an admin creates the account) and can stay.

0 High, 0 Medium, 0 Low, 1 Nit. No new findings.

## Verdict

**PASS** at `4421ef34`, confirmed at `21d95fcf` by the merge-only read against main `5e2d3471`. Squash-merge with the PR title as the subject. If main moves before merge, the PR needs a plain catch-up, a merge-only read and a reissued doc. Earlier versions of this doc (sha256 `ed05a18d…`, `29d1f84b…`) are void.
