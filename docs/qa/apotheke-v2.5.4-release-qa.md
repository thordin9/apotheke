# QA: apotheke v2.5.4 tag, GitHub release and post-release PR #40

- **Release:** [v2.5.4](https://github.com/thordin9/apotheke/releases/tag/v2.5.4), from #37 (`fix(images): flatten
  Imagick alpha and convert CMYK to sRGB`, closing #33).
- **Post-release PR:** [#40](https://github.com/thordin9/apotheke/pull/40) `docs(changelog): add the Imagick colour note
  to 2.5.4`, head `8ee45fab23f7ca52b101d48a41fa94067f53f158`.
- **Date:** 2026-10-20. All times are Europe/Athens. No credentials were used.

## Plan

1. **Tag and commits.**
   - Check the tag object and its target, the release commit's parent, and the #37 merge parents.
   - Verify the tarballs with `git get-tar-commit-id`.
   - Diff the tag tree against `6e704e5` and check the version strings.
   - Replay `scripts/release.php` on a rebuilt history with the real commit dates.
2. **GitHub release.** Draft and prerelease flags, latest, tag, author and assets. Compare the body with the generated
   notes from the replay.
3. **Note text (N-3).** Check that the CHANGELOG line in #40 and the release body agree. Check that the `#33` and `#37`
   links resolve.
4. **#40.**
   - The diff against `main`: CHANGELOG plus two docs files, no code.
   - That `[2.5.3]` is unchanged.
   - The N-4 wording against the #37 QA measurements.
   - Base, CI, link check and gitleaks.
   - A release replay after merge, with and without a QA doc commit.
5. **Workflow runs.** Main CI and the Release workflow.
6. **Public safety.** No secrets, addresses, account ids or hostnames in the delta or the release body.

## Results

### 1. Tag and commits (PASS)

- **Tag `v2.5.4`:**
  - The ref points at annotated tag object `cab6ffc5…`, created by `github-actions[bot]` at 18:11:21 with message
    `v2.5.4`. It is unsigned, as tags created by the release workflow always are.
  - The tag object points at commit `e07192125e71985cbb492708c9f141ff6e7da10d` `chore(release): v2.5.4 [skip ci]`,
    which is also the tip of `main`.
- **Parents:**
  - `e071921` has one parent, `6e704e5`, the merge of #37.
  - `6e704e5` has the parents `62b9dec` (the merge of #39, on top of v2.5.3 `4b26f72`) and `b341b57`.
  - `b341b57` is #37's last commit, `docs(qa): add QA report for the Imagick colour fix`, on top of the reviewed
    `46a3adf`. Its tree is `46a3adf` plus `docs/qa/apotheke-pr37-imagick-colour-qa.md` only.
  - The tree of `6e704e5` is `b341b57` plus #39's `CHANGELOG.md` change and `docs/qa/apotheke-v2.5.3-release-qa.md`.
- **Tarballs:** `git get-tar-commit-id` matches for `e071921`, `6e704e5`, `62b9dec`, `b341b57` and `8ee45fa`. The
  codeload tarball of `refs/tags/v2.5.4` reports `e071921`.
- **Release commit diff against `6e704e5`:** 3 files, the same set as earlier release commits.
  - `VERSION`: `2.5.3` → `2.5.4`.
  - `CHANGELOG.md`: the new `## [2.5.4] - 2026-10-20` section, plus the `[Unreleased]` and `[2.5.4]` compare links.
  - README coverage tables, refreshed by the workflow. For example, "Items, search, bulk, lending" goes to 97.0 %
    combined, and "All PHP" is now 6600 lines, 63.2 / 93.4 / 96.3 %.
- **Version strings:** `app_version()` reads `VERSION` (2.5.4) for `/healthz`, `/health` and the footer.
- **Replay:**
  - The rebuilt history uses the real commit dates: v2.5.3, then #39 merged (`release=false`), then #37's commits
    and its main-merge, then the `--no-ff` merge. The merge trees are identical to `62b9dec` and `6e704e5`.
  - `plan` gives `current=2.5.3 last_tag=v2.5.3 commits=9 bump=patch next=2.5.4 release=true`.
  - `apply` writes a `CHANGELOG.md` **byte-identical** to the one at the tag, and `VERSION` 2.5.4.

### 2. GitHub release (PASS)

- **Flags:** `v2.5.4` targets `main` and is not a draft or a prerelease. `releases/latest` returns `v2.5.4`.
- **Timing:** published at 18:11:25 by `github-actions[bot]`, and last updated at 18:37:52 (the `gh release edit`).
- **Assets:** none.
- **Body compared with the generated notes from the replay:** exactly one line changed, the `### Fixed` images line:
  - before: `- **images:** flatten Imagick alpha and convert CMYK to sRGB (#37)`
  - after: `- **images:** flatten Imagick transparency onto white and convert CMYK to sRGB (#33, #37)`, with links to
    issue 33 and PR 37.
  - Everything else is unchanged, including Changed, all 7 Maintenance lines and the
    `**Full changelog:** …/compare/v2.5.3...v2.5.4` footer.
  - There are no stray lines, no trailing whitespace, no CR characters and no doubled blank lines.

### 3. Note text (PASS)

- **The body equals #40's `[2.5.4]` section** (without its heading) plus the footer, byte for byte. So the CHANGELOG
  line and the release line are identical.
- **The wording is the post-release note from #37's description, with the `#37` link added.** As N-3 asked, it
  replaces the generated line rather than adding a second one.
  - "transparency onto white" and "CMYK to sRGB" match the #37 QA measurements.
- **Links:** the links are inline (`[#33](…/issues/33)`, `[#37](…/pull/37)`), so no reference definitions are needed.
  The issue, PR, compare and release URLs all return HTTP 200.

### 4. #40 (PASS)

- **Base and diff:**
  - Base `main` at `e071921`, mergeable (`clean`), 0 behind.
  - 3 commits:
    - `docs(changelog): add the Imagick colour note to 2.5.4`
    - `docs: clarify that only the autofill JPEG is one channel`
    - `docs(changelog): link #37 from the 2.5.4 images line`
  - Exactly 3 files, +5/−3, and no code:
    - `CHANGELOG.md` +1/−1: the `[2.5.4]` Fixed line above.
    - `docs/deployment-and-operations.md` +2/−1, in the HEIC paragraph.
    - `docs/user-guide.md` +2/−1, in the HEIC paragraph.
- **`[2.5.3]` is byte-identical** at `62b9dec`, `e071921` and `8ee45fa`. That includes the QR function-JIT note from #39.
  The rest of `CHANGELOG.md`, including the compare links, is unchanged.
- **N-4 wording:** "For a grayscale photo, only the reduced JPEG sent to autofill is 1-channel. The displayed copy (user
  guide: the photo shown) is re-encoded by GD as 3-channel and stays within a few levels."
  - This matches the #37 QA measurements. The Imagick analysis copy of a grayscale JPEG, grey+alpha PNG and grayscale
    HEIC is 1-component.
  - The `image_store_heic()` display copy is 3-component, and the pixel values are within 3 levels.
- **CI:** run 39448484937 on `8ee45fa`, 18:26–18:37, **10/10 success**. The earlier run 39446664535 on `07e61c5` was
  also 10/10.
- **Checks:**
  - The link checker at `8ee45fa` finds 186 relative links in 16 files, 0 broken.
  - gitleaks finds no leaks in the diff, the three changed files, or the replayed commits.
- **Replay after merge:**
  - With #40 merged onto v2.5.4: `commits=3 bump= next= release=false`.
  - With a `docs(qa):` commit added to #40: `commits=4 … release=false`.
  - Neither case triggers a release.

### 5. Workflow runs (PASS)

| Run | Commit | Window | Result |
|---|---|---|---|
| CI (push to `main`) 39442660637 | `62b9dec` (#39) | 17:48–17:59 | success |
| Release 39444223985 (`workflow_run`) | `62b9dec` | 17:59 | success, no release (docs only) |
| CI (push to `main`) 39444480188 | `6e704e5` (#37) | 18:00–18:11 | success |
| Release 39446069847 (`workflow_run`) | `6e704e5` | 18:11 | success: version, changelog, tag and GitHub Release |
| — | `e071921` | — | no CI, because of `[skip ci]` (expected) |

### 6. Public safety (PASS)

The #40 diff and the release body contain only `github.com/thordin9/apotheke` issue, PR and compare URLs. There are no
IP addresses, other hostnames, tokens, keys, account ids or e-mail addresses.

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| N-1 | Nit | #40's description still says "`gh release edit` is left for a separate step". That step is now done: the body was edited at 18:37:52 and matches. | Optional: update the description before merge. |
| I-1 | Info | The next release will list #40's three docs commits (and the `docs(qa):` commit, if added) under Maintenance. This is the usual pattern, as in 2.5.3 and 2.5.4. | None. |

No high, medium or low findings.

## Verdict

**PASS.**

- **Tag:** `v2.5.4` (`cab6ffc`) points at `e071921`. Its parent is `6e704e5`, which merges `62b9dec` (#39 on v2.5.3)
  and `b341b57` (#37).
- **Release commit:** changes only `VERSION`, `CHANGELOG.md` and the README coverage tables. The CHANGELOG matches the
  replay byte for byte, and the version is 2.5.4.
- **GitHub release:** published, latest, with no assets. Its body is the generated notes with only the images Fixed
  line replaced, and it is identical to #40's `[2.5.4]` section.
- **#40:** a docs-only change of 3 files on current `main`, with green CI. `[2.5.3]` is unchanged, the N-4 wording is
  accurate, and merging it doesn't release.
