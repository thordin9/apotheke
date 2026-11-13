# QA: apotheke #311, @playwright/test 1.64.1 → 1.65.0 plus CI image pin and 10 visual baselines

- PR: https://github.com/thordin9/apotheke/pull/311 (Dependabot, with a CI image pin, a baseline update and two main merges from DevOps)
- Reviewed head: `561dc123384f7165d67080f31fd406a21e37b766`, the plain merge of `c17c82e0` (baselines) and main `2510138e` (#313 wrangler squash). 0 behind main `2510138eb0bb2255fd7af8894026fa5c1b6059cb`, 5 ahead, mergeable (clean).
- History: 5 commits. Non-merge subjects: `ci: bump @playwright/test from 1.64.1 to 1.65.0 in the playwright group` (71), `ci(e2e): pin the Playwright image to v1.65.0-noble` (50), `test(e2e): update visual baselines for Playwright 1.65` (54). Merges exempt (70). PR title is the `ci:` subject (71).
- Gate: light (dependency bump + CI image pin + baselines: diff read, CI, merge-tree, pin grep, baseline image compare; no mutants, no Docker/PG suites).
- CI on `561dc123`: CI 43454485975 (all 3 Visual E2E shards green) and Frontend 43454485795 succeeded. 22 check runs, all success, incl. Tests (PHP 8.4, sqlite) and (PHP 8.4, pgsql). CodeQL 43454479250 also success.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main `2510138e`: 16 files. `package.json` +1/−1, `package-lock.json` +12/−12, `.github/workflows/ci.yml` +1/−1, `.github/workflows/snapshots.yml` +1/−1, `docs/ci.md` +1/−1, `CHANGELOG.md` +4, and exactly 10 PNG baselines (binary). No spec or test-logic changes. Only the root `package.json` depends on `@playwright/test`; frontend, edge and site have none.
- [x] Merges: `bc1f099f` (parents `5b5dcf7f` + `85c6ad8c`) and `561dc123` (parents `c17c82e0` + `2510138e`). `git merge-tree --write-tree` of each pair is a clean tree identical to the merge commit (`3270556e…` and `5da7f1e2…`). Each merge brought only main's files (#304 then #313). Nothing dropped.
- [x] Pins: `@playwright/test` 1.64.1 → 1.65.0 exact in package.json; lock root and `playwright` / `playwright-core` 1.65.0, consistent, no leftover 1.64 in the lock. Image tag `mcr.microsoft.com/playwright:v1.65.0-noble` in ci.yml, snapshots.yml and docs/ci.md. `scripts/update-snapshots.sh` already derives `v$(package.json)-noble` and was not edited. Repo-wide leftover `1.64` near Playwright is only historical (CHANGELOG #183, docs/qa, and the point-in-time plan inventory `docs/plans/feature12-ci4/APPENDIX-A-inventory.md`).
- [x] Workflows: only the image tag line changed in ci.yml and snapshots.yml. Permissions (`contents: read`), triggers and secrets are unchanged. `timeout-minutes` is unchanged (e2e 30, update-snapshots 40).
- [x] Baselines: all 5 chromium-pixel PNGs are 412×(h−21); all 5 desktop-chromium PNGs are 1280×(h−2). Widths unchanged. Side-by-side and diff review of each: same UI and copy in all five states (default, down, ok, photo, unauthorized). The −21/−2 px comes from Chromium 158 font metrics and wrapping, mainly the Access token help text; no broken layout, missing elements or baked-in error states. Artifacts from run 43445331382 had not expired: all 10 new baselines are sha256-identical to that run's actuals (first run and retry also identical to each other). The update-snapshots artifact was not used, as claimed.
- [x] CHANGELOG: a Maintenance bullet under Unreleased links https://github.com/thordin9/apotheke/pull/311. Placement and wording match #183 (`ci: bump @playwright/test to 1.64.1 and pin the Playwright image`). A hand-written bullet is appropriate here because the PR also pins the CI image and updates baselines; pure Dependabot bumps (#243, #313, #312) still rely on release.php.
- [x] The update-snapshots job 43447448414 hit the existing 40-minute timeout (Render baselines ran ~39 min and was cancelled; a partial `snapshots` artifact was uploaded and not used). Successful runs through 2026-10-24 finished in about 15–20 minutes. The timeout-minutes value was not changed by this PR, and Visual E2E on the same image finished in ~5 minutes per shard. Not filed: not clearly caused by this bump (could be a stall), and it does not affect the green CI on this head.

## Findings

No findings.

0 High, 0 Medium, 0 Low, 0 Nit.

## Verdict

**PASS** at `561dc123`, 0 behind main `2510138e`. Highs and Mediums block; none are open. Squash-merge with the PR title as the subject once the docs(qa) commit is on the branch and CI is green. If main moves before merge (e.g. #312), the PR needs a plain catch-up (conflicts reported as conflicts), a merge-only read and a reissued doc.
