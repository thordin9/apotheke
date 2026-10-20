# QA: apotheke PR #35, sync `main` (v2.5.2) into `feature/ci4`, and v2.5.2 tag check

- **PR:** [#35](https://github.com/thordin9/apotheke/pull/35) `chore(ci4): merge main v2.5.2 into feature/ci4` (draft, into `feature/ci4`)
- **Head:** `a06bbc780bfac37d3105afba651d2432200b5625` (the N-6 fix) on top of merge commit
  `ff88ee1e056e0dae146f271b454479b1a4a84b4b`, whose parents are `ef93566…` (`feature/ci4`) and `42f497e…` (`main`, v2.5.2).
  Sections B1–B9 were run at `ff88ee1`; the delta is in section C.
- **Merge base:** `87850e379d85d6690e232ba107373208f9b7ffbd` (v2.5.0)
- **Date:** 2026-10-20 (Europe/Athens). No live service calls, no credentials, 0 Neurons.

## Test plan

**A. v2.5.2 tag and release**

1. Tag target.
2. The GitHub release is published (not a draft or prerelease), and its notes equal the CHANGELOG `[2.5.2]` section.
3. `VERSION` is bumped.
4. `42f497e` = `1b300b9` + the release bump only.
5. The Release workflow is green, and its assets are as expected.

**B. PR #35 sync**

1. The parents are exactly `ef93566` and `42f497e`, and neither `main` nor `feature/ci4` moved.
2. Do my own three-way merge of the two parents (codeload tarballs, `git get-tar-commit-id`) and diff it against
   `ff88ee1`. Every difference must be a declared resolution.
3. Nothing from `main` is lost: every change from the merge base to `42f497e` is at `ff88ee1`.
4. Nothing from CI4 is lost: likewise for the merge base to `ef93566`.
5. CHANGELOG ordering and the `[Unreleased]` link; README wording.
6. The `ci.yml` union: the CI4 matrix, deptrac and the DI rule are kept, and `imagick` and `docker-heic.sh` are added
   in the right jobs. Run actionlint. Exactly one job uploads `coverage-sqlite`.
7. Suites: PHP 8.4 on the host, and the Alpine image via podman.
8. CI run 39415801052.
9. gitleaks on the merge.

**C. Fix delta `ff88ee1...a06bbc7` (N-6)**

1. One commit, `CHANGELOG.md` only, deletions only.
2. Exactly the 11 stale entries are removed, and the Maintenance line is kept.
3. From `## [2.5.2]` to the end of the file, byte-identical to `42f497e`; the header through `## [Unreleased]` is
   identical to `main`.
4. The merge commit `ff88ee1` (parents `ef93566`, `42f497e`) is still in the ancestry.
5. CI run 39417312997.
6. gitleaks on the delta.

## Results

### A. v2.5.2 (PASS)

- `refs/tags/v2.5.2` is an annotated tag by github-actions[bot] (unsigned, like earlier bot tags). It points to commit
  **`42f497e28d5bacaa5642c28882747cf971f83fea`**, which is `main`'s head.
- The GitHub release `v2.5.2` was published at 14:20 (Europe/Athens), with `draft: false` and `prerelease: false`.
  Its notes are byte-identical to the CHANGELOG `[2.5.2]` section, plus the "Full changelog" compare link. It has no
  assets; v2.5.1 has none either, and `release.yml` only runs `gh release create`.
- `VERSION` is 2.5.2.
- `42f497e` (`chore(release): v2.5.2 [skip ci]`, parent `1b300b9`) changes only `VERSION`, `CHANGELOG.md` (the new
  `[2.5.2]` section and the compare links) and the README coverage row (6536 → 6538 lines).
- `1b300b9` is the merge of #32 (parents `eeb7a34`, `beb7c82`), and its tree equals `beb7c82`'s.
- Both QA docs on `main` are byte-identical to the reviewed ones: `apotheke-pr32-qa.md` (`2e34adce…`) and
  `apotheke-pr32-recheck-qa.md` (`a2b135f5…`).
- The Release run 39414160524 (on `1b300b9`) succeeded, after CI push run 39412947840 succeeded.

### B1. Parents and branches (PASS)

- `ff88ee1` parents: `ef93566`, `42f497e`. `main` is still `42f497e` and `feature/ci4` is still `ef93566`, so nothing
  was pushed to either.

### B2. My own merge vs `ff88ee1` (PASS, see N-6)

- I rebuilt merge base, CI4 and main as snapshot commits and ran `git merge`. **Only `README.md` conflicts**, as
  Coder said. `ci.yml`, `CHANGELOG.md`, `docs/architecture.md` and `docs/ci.md` auto-merge.
- My merge (with the README markers kept) differs from `ff88ee1` in exactly two files:
  - **`README.md`**: the conflict is resolved as declared. main's "GD, and Imagick in the Docker image so HEIC photos
    can be read" comes first, then "The legacy app has no framework and no runtime Composer packages, no JS build
    step and no CDNs", then CI4's 3.0.0 cutover / CodeIgniter 4 paragraph. Accurate, and nothing is lost from either side.
  - **`CHANGELOG.md`**: git's auto-merge silently puts the CI4 `### Maintenance` line under `[2.5.1]`. Coder moved it
    back under `[Unreleased]`, which is correct, but also restored the 11 stale v2.5.0-era entries there (N-6).
- `ci.yml`, `docs/architecture.md` and `docs/ci.md` at `ff88ee1` are byte-identical to the plain three-way merge.
  This matches the PM's review.

### B3 and B4. Nothing lost (PASS)

- From the merge base, `main` changed 26 files and CI4 changed 27; 5 were changed by both (`ci.yml`, `CHANGELOG.md`,
  `README.md`, `docs/architecture.md`, `docs/ci.md`).
- All 21 main-only files and all 22 CI4-only files are byte-identical at `ff88ee1` to their side. That includes
  `src/autofill.php` (4:2:0 on the analysis copy only), `src/items.php` (the v2.5.1 HEIC encode), the Docker
  Imagick/HEIC changes, `HeicTest`/`AutofillTest`, and both QA docs.
- `ff88ee1` has no change that comes from neither side.
- The 5 shared files are clean three-way merges, except the README and CHANGELOG resolutions above.

### B5. CHANGELOG and README (CHANGELOG: N-6)

- Byte-identical to `main`: the 8-line header, and everything from `## [2.5.2]` to the end of the file (sha256
  `f078ec3d…`). That covers the `[2.5.2]`, `[2.5.1]` and older sections and all compare links, including
  `[Unreleased]: …/compare/v2.5.2...HEAD`.
- `[Unreleased]` at `ff88ee1` has 12 bullets:
  - **11 stale:** Added ×2, Fixed ×5, Changed ×1, Security ×3. All of them shipped in 2.4.1 or 2.5.0, and #32 cleared
    them on `main`.
  - **1 correct:** the CI4 `### Maintenance` line.
- **N-6 is confirmed.** I count 11 stale bullets, where the PM's note says 13.

### B6. `ci.yml` (PASS)

- **Kept from CI4:** push on `[main, feature/ci4]`, the `deptrac` and `DI rule` jobs, and the Tests matrix (legacy/ci4 @
  8.4 plus legacy @ 8.3 on sqlite and pgsql). Also `scripts/ci4-leg.sh` gating each step, and `APOTHEKE_APP`.
- **Added from main:** `imagick` in the Tests setup-php (all legs), and `bash scripts/docker-heic.sh apotheke:ci` in
  *Docker build + smoke test*.
- **Coverage:** only one job uploads `coverage-sqlite`, guarded by `app == legacy && php == 8.3 && db == sqlite`.
  This matches DevOps's check and the one artifact `release.yml` downloads. The other uploads are
  `playwright-report-*`, on failure only.
- actionlint is clean on all workflows.

### B7. Suites at `ff88ee1` (PASS)

| Where | PHPUnit | Integration / `tests/run.sh` |
|---|---|---|
| Host PHP 8.4.26 + Imagick | **290 tests, 2031 assertions, 8 skipped** (pgsql ×2, zbarimg ×6) | `tests/run.sh`: lint OK (220 links / 0 broken, shellcheck, actionlint), **1423 passed, 0 failed** |
| Alpine image (podman, PHP 8.3.35, git added) | 290 tests, 10 skipped (pgsql ×2, php-cgi ×1, node ×1, zbarimg ×6), 4 failures, all `Ci4BranchCiTest` exit 127 (no `bash` in the image); with `bash` added, `Ci4BranchCiTest` gives OK (12 tests) | `tests/integration.php`: **1423 passed, 0 failed** |

Coder's 290 / 9 skipped is consistent with this: the counts differ only in which tools each environment lacks.

### B8. CI run 39415801052

**PASS: all 16 jobs succeeded** (run on `ff88ee1`, 14:33 to 14:43:48 Europe/Athens):

- **Lint, Conventional Commits, Secret scan, deptrac, DI rule, Headless mobile checks:** all succeeded.
- **Docker build + smoke test:** includes `docker-heic.sh`, which reports "Imagick lists HEIC".
- **Tests (legacy @ 8.3, sqlite):**
  - `imagick` enabled.
  - PHPUnit OK (290 tests).
  - Coverage run OK.
  - Integration: 1425 passed.
  - `coverage-sqlite` uploaded.
- **Other Tests legs:** legacy @ 8.3 pgsql, and legacy @ 8.4 sqlite and pgsql, all succeeded.
- **Tests (ci4 @ 8.4, sqlite and pgsql):** succeeded. The suite is gated off because `ci4/public/index.php` is
  absent, as designed.
- **Visual E2E (Playwright):** shards 1/3, 2/3 and 3/3 all succeeded.

The PR's `mergeable_state` was `unstable` while jobs were running. It is still a draft.

### B9. gitleaks (PASS)

- 0 leaks on the replayed merge (`ci4..ff-tree`: the main snapshot plus the merge), and 0 on the full `ff88ee1` tree.
- The README and CHANGELOG resolutions add no URL apart from existing GitHub compare links.

### C. Delta `ff88ee1...a06bbc7` (PASS)

- **C1:** the compare is `ahead` by 1 commit and `behind` by 0. The one commit is `a06bbc7`
  `docs(changelog): drop shipped entries from Unreleased`, with parent `ff88ee1`. Only `CHANGELOG.md` changes:
  **+0 / −23**, in one hunk. Comparing the tarballs (`git get-tar-commit-id` = `a06bbc7`) against `ff88ee1` shows no
  other file differs.
- **C2:** exactly 11 bullets are removed: Added ×2, Fixed ×5, Changed ×1, Security ×3, plus their 4 headings and the
  blank lines. That makes 23 lines. `[Unreleased]` now holds only `### Maintenance` and the CI4 bullet, unchanged.
- **C3:** the section from `## [2.5.2]` to the end of the file, compare links included, has sha256
  `f078ec3d154a5e5efc832739ff9937341ff454cfde4a3a12cf85e35f4a8d5d87`. That is identical to `42f497e`. The
  header through `## [Unreleased]` is byte-identical to `main`.
- **C4:** `a06bbc7` → `ff88ee1` → parents `ef93566` and `42f497e`. PR #35 now has 20 commits and 26 files, and it is
  still a draft. Its base `feature/ci4` is still `ef93566`, and `mergeable_state` is `clean`.
- **C5:** CI run 39417312997 on `a06bbc7`: **all 16 jobs succeeded** (14:46 to 14:55:30 Europe/Athens). That covers
  Lint, Conventional Commits, Secret scan, deptrac, DI rule, all six Tests legs, Docker build + smoke, Headless mobile,
  and all three Playwright shards.
- **C6:** gitleaks finds no leaks in the delta or in `CHANGELOG.md` at `a06bbc7`. The delta only removes lines; the one
  URL in the removed text was the placeholder `https://autofill.example`.

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| N-6 | nit (changelog), **closed in `a06bbc7`** | `[Unreleased]` at `ff88ee1` keeps 11 entries that shipped in 2.4.1/2.5.0 (Added ×2, Fixed ×5, Changed ×1, Security ×3) and that #32 cleared on `main`. When `feature/ci4` reaches `main` they would show up as unreleased again. | Raised by the PM; Coder is fixing it. `[Unreleased]` should contain only `### Maintenance` and the CI4 bullet, with everything from `## [2.5.2]` down byte-identical to `main`. A plain auto-merge is also wrong: it files the CI4 line under `[2.5.1]`. |
| I-7 | info (merge method) | #35 must land as a **merge commit**, so that `42f497e` stays a parent of `feature/ci4`. A squash or rebase would drop main's history from `feature/ci4` and recreate the conflicts at cutover. Merge, squash and rebase are all enabled on the repo (DevOps). | Merge with `--merge` (create a merge commit), pinned to the reviewed head. |
| I-8 | info (environment) | The Alpine runtime image has no `git`, `bash` or `node`. `ReleaseTest`, `Ci4BranchCiTest` and `LinkCheckTest` cannot pass or run in it unless those are added. CI runs PHPUnit on Ubuntu, where they pass. | None needed. Note it for anyone running PHPUnit inside the image. |

N-6 is closed and verified in section C. I-7 and I-8 are still open as notes. There are no high, medium or low findings.

## Verdict

- **v2.5.2 tag and release: PASS.**
- **PR #35 at `a06bbc7`: PASS. Ready to merge** once it is out of draft.
  - The merge is correct: only README conflicted, and nothing from `main` or CI4 is lost.
  - The `ci.yml` union is coherent, and exactly one `coverage-sqlite` upload remains.
  - Suites pass and gitleaks is clean.
  - N-6 is closed. CI is 16/16 green on `a06bbc7`.
  - Merge with **`--merge`**, a merge commit pinned to `a06bbc7` (I-7), so that `42f497e` stays a parent. Do not squash
    or rebase.

Evidence on the QA box: `/workspace/apotheke-qa/pr35/`, including `rel-bump.diff`, `qa-merge-vs-ff.diff`, `mrg/` (the
replayed merge), `a06bbc7.tgz`, `a06-cl.diff`, `logs/`, `gl-*.json` and `cl252.md`.
