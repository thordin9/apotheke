# QA recheck: apotheke PR #32 at `83ea804` and `c555f16`

- **PR:** [#32](https://github.com/thordin9/apotheke/pull/32), draft into `main`
- **New head:** `83ea804ad4132d2ee3083061f5d3ae4386f406ad`. **Previous QA head:** `fb857dcb12145523e0ffadc7fcefadf9a594fcdc`
  (PASS, `docs/qa/apotheke-pr32-qa.md`). **Base:** `main` at `eeb7a347…` (v2.5.1)
- **Delta:** `4027f7d` docs(qa) adds the QA doc. `83ea804` fix: limit 4:2:0 to the analysis copy, tidy docs, CI.
- **PM decision applied:** 4:2:0 only in `autofill_analysis_imagick()`. The HEIC display copy keeps its v2.5.1 chroma.
  I-1 is tracked as issue #33 and is out of scope here.
- **Date:** 2026-10-20 (Europe/Athens). No live service calls, no credentials, 0 Neurons.

## Test plan

1. Compare `fb857dc...83ea804`: scope of each commit, no force-push. Check the QA doc's hash at the head.
2. The previous L-1: `image_store_heic()` matches v2.5.1. Re-run the fixtures from the first pass. The served HEIC
   copy at `jpeg_quality` 82 and 95 must be byte-identical to v2.5.1, on the host and in the PR image. The analysis
   copy must stay 4:2:0 and within 524288. The new test must fail on a mutant and pass on the head, and skip only
   without Imagick or HEIC support.
3. The previous N-1: `[Unreleased]` is empty and the released sections are byte-identical. Replay the 6 commits
   through `release.php` and check that the `[2.5.2]` section is sane.
4. The previous N-2 wording, and whether the docs still match the code.
5. The previous I-3: `imagick` is in setup-php, the Imagick tests ran in CI (not skipped), and the final CI count.
6. PHPUnit with GD + Imagick and with GD only, `tests/integration.php`, lint, and gitleaks on the range.

## Results

### 1. Scope and doc hash (PASS)

- GitHub compare: `ahead 2, behind 0`, and both new commits are direct children (`4027f7d` → `fb857dc`,
  `83ea804` → `4027f7d`), so there was no force-push.
- **`4027f7d`** adds exactly one file, `docs/qa/apotheke-pr32-qa.md`. The tarball tree differs from `fb857dc` only by
  that file. `cmp` with my copy matches. sha256 `2e34adce389b84a904865b584d95b592419a841d3e4c24e56bc259903a8dc03c`,
  both in that commit and through raw.githubusercontent at `83ea804`.
- **`83ea804`** changes 8 files (+132 / −37): `.github/workflows/ci.yml` (1 line), `CHANGELOG.md`, the three docs,
  `src/autofill.php`, `src/items.php` and `tests/Unit/HeicTest.php`. Nothing unexpected.
- gitleaks: 0 leaks on the replayed range `v2.5.1..pr` (6 commits). The delta adds no URL, address or hostname.

### 2. L-1 from the first pass: HEIC display copy restored (PASS), new test (see L-1)

- **Code.** `src/items.php` at `83ea804` is **byte-identical to v2.5.1** (whole file, not just `image_store_heic()`).
  `src/autofill.php` is v2.5.1 plus 5 lines in `autofill_analysis_imagick()`: the `jpeg:sampling-factor` option, the
  property and three comment lines. The shared helper is gone, so no other code path can pick up the override.
- **Served copy, all 13 fixtures, base code vs `83ea804`, same environment:**

| Environment | `jpeg_quality` 82 | `jpeg_quality` 95 |
|---|---|---|
| Host (PHP 8.4, Debian libgd, IM 7.1.1) | 13/13 byte-identical to v2.5.1 | 13/13 byte-identical (`fb857dc` differed for all 4 HEIC) |
| PR image `83ea804` (podman; PHP 8.3, Alpine libgd, IM 7.1.2) | 13/13 byte-identical | 13/13 byte-identical |

- **Analysis copy.** On the host, all 22 analysis copies are identical to `fb857dc`'s output (same bytes and sizes).
  They are 4:2:0 (grayscale is a single 2x2 component), and the largest multipart body is 495,180 B, under 524288.
  Imagick with a 4:4:4 photo still gives 82,115 B, and noisy 1024 px gives 465,282 B. In the PR image every copy is
  2x2 and the largest is 495,180 B. Originals: sha256 equals the upload for every fixture in both places.
- **New test `HeicTest::testHeicDisplayAtHighQualityKeepsSampling`:**
  - Mutant = `fb857dc` code (4:2:0 forced in the HEIC path) + the new test: **fails** at line 86, the byte comparison
    of the display copy against the v2.5.1-style encode. Head: **passes**.
  - GD only: skips with `Imagick is not available`. The second skip, `Imagick HEIC support is not available`, is in
    the code; I did not exercise it because both my environments have libheif.
  - **In the PR's own Docker image it fails** at line 74, `high jpeg_quality does not write 2x2 on its own`. That
    image's libgd writes 4:2:0 even at q95, so the precondition does not hold there. This is a false failure, not a
    product problem (see L-1).
  - Probe: with the two sampling `assertNotSame` lines removed, the test passes on the head and fails on the mutant
    (line 86) in the PR image as well. The byte comparison is the assertion that actually guards the change.
  - The fixture is a 48 px JPEG fed to `image_store_heic()`, so the test exercises the Imagick display path without
    needing a real HEIC decode.

### 3. N-1 from the first pass: CHANGELOG and release (PASS, see N-1)

- `[Unreleased]` is now a bare heading followed by one blank line. From `## [2.5.1]` to the end, the file has
  sha256 `bf011db0…` at base and at head.
- Replay of all 6 commits on a v2.5.1-tagged repo, merged with a merge commit: `plan` gives `commits=6 bump=patch
  next=2.5.2`.
- `apply` writes `VERSION` 2.5.2, an empty `## [Unreleased]`, and `## [2.5.2] - 2026-10-20`. Fixed has 2 lines and
  Maintenance has 4 (test, 2 docs, qa). It also writes valid `[Unreleased]` and `[2.5.2]` compare links. An empty
  Unreleased block causes no broken or empty section.
- The two Fixed lines read awkwardly together (N-1).

### 4. Docs (PASS)

- The previous N-2 is fixed. The admin guide now reads "no longer inflates the analysis copy … the JPEG the catalog
  shows does not get this override".
- `docs/autofill.md` (twice) and `docs/deployment-and-operations.md` now say that only the analysis JPEG forces 4:2:0.
- `rg` finds no remaining claim that the HEIC or display path forces 4:2:0. The code comment in
  `autofill_analysis_imagick()` gives the reason. Lint: 186 links, 0 broken.

### 5. I-3 and CI (PASS)

- `ci.yml` line 63 (the Tests matrix) lists `imagick`. Both Tests logs show `imagick Enabled`.
- Tests (sqlite): `OK (277 tests, 1994 assertions)`. Tests (pgsql): `OK (277 tests, 1995 assertions)`. A plain `OK`
  means no skips and no incompletes, so the noisy test, the 4:4:4 test and the new HEIC display test all ran and
  passed. That also means the runner has HEIC support. Integration: `1425 passed, 0 failed`.
- Run 39406617363: **10/10 success**. Lint, Conventional Commits, Secret scan, Tests sqlite and pgsql, Headless mobile,
  Docker build + smoke test, and Playwright shards 1–3. It ran 13:15–13:25 (Europe/Athens).

### 6. Local suites at `83ea804` (PASS)

| Suite | GD + Imagick | GD only |
|---|---|---|
| PHPUnit | 277 tests, 1985 assertions, 8 skipped (pgsql ×2, zbarimg ×6), 0 incomplete | 277 tests, 1923 assertions, 10 skipped (+2 `Imagick is not available`) |
| `tests/integration.php` | 1423 passed, 0 failed | 1422 passed, 0 failed |
| `scripts/lint.sh` | OK | |

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| L-1 | L (test portability) | `testHeicDisplayAtHighQualityKeepsSampling` fails inside the project's own Docker image (Alpine libgd writes 4:2:0 at q95) at the precondition on line 74. CI is green because it runs on the Ubuntu runner, but anyone running PHPUnit in the shipped image gets a false failure. Neither sampling `assertNotSame` catches the mutant; the byte comparison on line 86 does, on the host and in the image. | Delete the two sampling `assertNotSame` lines (74 and 84) and keep the byte comparison, or `markTestSkipped` when the reference is already 2x2. Small, test-only. PM to decide whether it goes in before the merge. |
| N-1 | nit (release notes) | The generated `[2.5.2]` Fixed section will read "photos: force 4:2:0 in Imagick JPEG encodes" (no longer accurate, it is analysis only) next to the unscoped "limit 4:2:0 to analysis copy, tidy docs, CI". | Optional. Merge-commit mode lists every subject and the commits cannot be reworded without a force-push. Accept, or tidy the section in a later `docs(changelog)` commit. |
| N-2 | nit (test) | The new test's failure output is a binary JPEG diff, which is hard to read. | Optional: compare `sha256` of the two strings, with the samplings in the message. |

No H or M findings. The first-pass L-1, N-1, N-2 and I-3 are closed, and I-1 is tracked as #33.

## Verdict at `83ea804` (superseded by the final verdict below)

**PASS** at `83ea804ad4132d2ee3083061f5d3ae4386f406ad`, with L-1 left to the PM.

- The served copy is byte-identical to v2.5.1 for all 13 fixtures, at q82 and q95, on the host and in the PR image.
- The analysis copy is still 4:2:0 and within 524288. Every original is byte-identical to its upload.
- `[Unreleased]` is empty, the released sections are unchanged, and the release replay gives a sane v2.5.2.
- CI ran the Imagick tests (277 tests, 0 skipped). My QA doc was committed byte-identical.

Evidence on the QA box: `/workspace/apotheke-qa/pr32/`, including `delta.diff`, `compare-fb857dc-83ea804.json`,
`r-*.log`, `out/head2-*`, `out/docker2*`, `docker2-run.log`, `mutant/`, `probe/`, `replay/` and `ci2-tests-*.log`.

## Recheck 2: `c555f16` (test-only fix)

- **Head:** `c555f16b6df84a29c6f452c5edc0afd69b0da01f`, `test: make HEIC display test portable to Alpine GD`
- **PM rule:** only H or M findings block; L and nit items are follow-ups.

### Plan

1. Compare `83ea804...c555f16`: one commit, only `tests/Unit/HeicTest.php`, `src/` untouched.
2. Review the diff: both sampling `assertNotSame` lines are gone, a sha256 comparison is used, and the test still
   asserts something meaningful.
3. Run PHPUnit on the host (PHP 8.4, Debian libgd) and in the PR's Alpine image (podman). Explain the skip counts.
4. Mutant: re-add `jpeg:sampling-factor 2x2,1x1,1x1` to `image_store_heic()`. The test must fail in both environments.
5. CI run 39410093009.

### Results

- **Scope (PASS).** GitHub compare: ahead 1, behind 0, parent `83ea804`. The only file changed is
  `tests/Unit/HeicTest.php` (+2 / −4). The tarball tree differs from `83ea804` only in that file, so `src/`, docs,
  CHANGELOG, CI and the QA doc are unchanged. A `test:` commit does not change the bump: it is still v2.5.2 (patch).
- **Diff (PASS).** Both `assertNotSame('2x2,1x1,1x1', …)` lines are removed (old lines 74 and 84), and the docblock
  says why. The test still asserts four things:
  - the source is 4:4:4;
  - `image_store_heic()` succeeds;
  - the served copy has the same sampling as the unforced v2.5.1-style encode;
  - **sha256 of the served copy equals sha256 of that reference encode** (now line 84). This is the assertion that
    guards the PM's decision.
- **Targeted test** `testHeicDisplayAtHighQualityKeepsSampling`:

| Environment | Head `c555f16` | Mutant (4:2:0 back in `image_store_heic()`) |
|---|---|---|
| Host, PHP 8.4.26, Debian libgd, IM 7.1.1 | OK (1 test, 8 assertions) | **fails** at `HeicTest.php:84` (sha256) |
| PR image (Alpine, PHP 8.3.35, IM 7.1.2) | OK (1 test, 8 assertions) | **fails** at `HeicTest.php:84` (sha256) |

- **Full PHPUnit at `c555f16`:**

| Environment | Result | Skips |
|---|---|---|
| Host | OK, 277 tests, 1983 assertions, **8 skipped** | pgsql cross-driver ×2 (no `pdo_pgsql`), QR decoder ×6 (no `zbarimg`) |
| PR image, writable copy of the tree + `git` added in a throwaway container | OK, 277 tests, 1973 assertions, **9 skipped** | pgsql ×2 (no server), `php-cgi` refusal ×1 (no `php-cgi`), QR decoder ×6 (no `zbarimg`) |

  - **What the skips mean.** None of the three Imagick/HEIC tests is skipped in either environment. Every skip is a
    missing tool or service (PostgreSQL, `php-cgi`, `zbarimg`), and none is in a test this PR touches.
  - **Why these counts differ from the ones you've seen.** The 277 with no skips at `83ea804` was CI, which has
    PostgreSQL, `php-cgi` and `zbarimg`. Coder's 3 skips on Ubuntu are pgsql ×2 and `php-cgi`, as listed in the PR
    body. Coder's 9 in Alpine match mine.
  - **Image setup caveats.** The Alpine image has no `git`, so without it `ReleaseTest::testCommitsFromAGitHistory`
    exits 127. With the tree mounted read-only, `OriginalsTest` cannot create its directories. Both come from the test
    environment, not from this PR, and neither test was changed by it.
  - **Acceptable:** yes.
- **CI:** run 39410093009 is **10/10 success** (13:44–13:54 Europe/Athens). Tests (sqlite): imagick is enabled and the log
  shows `OK (277 tests, 1992 assertions)` with no skips; integration `1425 passed, 0 failed`. Tests (pgsql), the Docker
  build + smoke test and Playwright shards 1–3 are all green.

### Status of recheck findings

| ID | Status |
|---|---|
| L-1 (HEIC display test fails in the Alpine image) | **Closed** by `c555f16`. Passes in the image and on the host, and the mutant fails in both. |
| N-1 (two overlapping 4:2:0 lines in the generated `[2.5.2]` notes) | **Accepted** by the PM. No change. |
| N-2 (binary diff on failure) | **Closed**. The test compares sha256 strings. |
| First pass L-1, N-1, N-2, I-3 | Closed at `83ea804` (see above). I-1 is tracked as #33. |

No open H, M or L findings.

## Final verdict

**PASS** at `c555f16b6df84a29c6f452c5edc0afd69b0da01f`.

- The served copy is byte-identical to v2.5.1, and the analysis copy is 4:2:0 and within 524288 (from `83ea804`;
  `src/` has not changed since).
- The display-copy test is portable and catches the regression on Debian and Alpine GD.
- CI is 10/10 green (run 39410093009). Merging cuts v2.5.2.

Evidence on the QA box: `/workspace/apotheke-qa/pr32/` (`delta3.diff`, `mutant3/`, `r3-phpunit-*.log`).
