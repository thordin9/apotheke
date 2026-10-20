# QA: apotheke v2.5.3 tag, GitHub release and post-release PR #39

- **Release:** [v2.5.3](https://github.com/thordin9/apotheke/releases/tag/v2.5.3), from #36 (`fix(qr): disable function
  JIT before the encoder loads`).
- **Post-release PR:** [#39](https://github.com/thordin9/apotheke/pull/39) `docs(changelog): add the QR function-JIT
  note to 2.5.3`, head `856ec5290af166c73bcc08a36017d605e72d9c1a`.
- **Date:** 2026-10-20. All times are Europe/Athens. No credentials were used.

## Plan

1. **Tag and commits.** Check the tag object and its target, the release commit's parent, and the #36 merge parents.
   Verify the tarballs with `git get-tar-commit-id`. Diff the tag tree against `c04b25f`, check the version strings,
   and replay `scripts/release.php` on a rebuilt history.
2. **GitHub release.** Draft and prerelease flags, latest, tag, author and assets. Compare the body with the generated
   notes from the replay.
3. **Note text.** Check it against the L-2 wording from the #36 QA, and check that the CHANGELOG (#39) and the release
   body agree.
4. **#39.** The diff, base, CI, link check and gitleaks, plus a release replay after merge, with and without a QA doc
   commit.
5. **Workflow runs.** Main CI and the Release workflow.

## Results

### 1. Tag and commits (PASS)

- **Tag `v2.5.3`:**
  - The ref points at annotated tag object `218edfad…`, created by `github-actions[bot]` at 17:15:16 with message
    `v2.5.3`. It is unsigned, as tags created by the release workflow always are.
  - The tag object points at commit `4b26f72d7f1fa87b6148c5d1075a1382a2a92e50` `chore(release): v2.5.3 [skip ci]`.
- **Parents:**
  - `4b26f72` has one parent, `c04b25f`, the merge of #36.
  - `c04b25f` has the parents `42f497e` (v2.5.2) and `8b4a1a3`.
  - `8b4a1a3` is #36's last commit (`docs(qa): add QA report for the QR function-JIT fix`, on top of `b954a5e`).
  - The tree of `c04b25f` is identical to the tree of `8b4a1a3`.
- **Tarballs:** `git get-tar-commit-id` matches for `4b26f72`, `c04b25f`, `8b4a1a3` and `856ec52`. The codeload
  tarball of `refs/tags/v2.5.3` reports `4b26f72`.
- **Release commit diff against `c04b25f`:** 3 files, the same set as the earlier release commits.
  - `VERSION`: `2.5.2` → `2.5.3`.
  - `CHANGELOG.md`: the new `## [2.5.3] - 2026-10-20` section, plus the `[Unreleased]` and `[2.5.3]` compare links.
  - README coverage tables, refreshed by the workflow. For example, "Bootstrap, config, QR, seed" becomes 69.0 / 87.0 /
    90.0 %, and "All PHP" becomes 6553 lines, 63.3 / 93.9 / 96.7 %.
- **Version strings:** `app_version()` reads `VERSION`, so `/healthz`, `/health` and the footer report `2.5.3`. Apart
  from the CHANGELOG and QA docs, no other `2.5.x` string is in the code.
- **Replay:**
  - The rebuilt history is v2.5.2, then #36's 4 commits, then a `--no-ff` merge. Its tree is identical to `c04b25f`.
  - `plan` gives `current=2.5.2 last_tag=v2.5.2 commits=4 bump=patch next=2.5.3 release=true`.
  - `apply` writes a `CHANGELOG.md` **byte-identical** to the one at the tag, and `VERSION` 2.5.3.

### 2. GitHub release (PASS)

- **Flags:** `v2.5.3` targets `main` and is not a draft or a prerelease. `releases/latest` returns `v2.5.3`.
- **Timing:** published at 17:15:19 by `github-actions[bot]`, and last updated at 17:31:14 (the `gh release edit`).
- **Assets:** none, as for v2.5.0–v2.5.2. GitHub's source archives are the only downloads, so there are no checksums
  to compare.
- **Body compared with the generated notes from the replay**, exactly two line changes:
  - Added under `### Fixed`: the known-issue note.
  - Removed from `### Maintenance`: `- **changelog:** docs: drop the unreleased QR note (#36)` (N-3).
  - Everything else matches, including the `**Full changelog:** …/compare/v2.5.2...v2.5.3` footer.

### 3. Note text (PASS, see N-1)

- The CHANGELOG note in #39 and the one in the release body are **identical**. #39's `[2.5.3]` section equals the
  release body without its footer.
- **Checked against the measured results:**
  - "PHP 8.2 and 8.3 with `opcache.jit=1235` miscompiled 1 of 240 codes": both measured at 1/240.
  - "on PHP 8.4 that mode can hang `GET /labels`": 8.4.26 with `1235` hangs.
  - "other function-JIT modes such as `1205` wrote wrong codes": 8.4 with `1205`, `1215`, `0205`, `205` and `function`
    got 240 of 240 wrong.
  - Whole-worker scope, negligible cost, `opcache_jit_blacklist()` doesn't help, tracing stays on, Docker unaffected:
    all match the code and the #34/#36 measurements.
  - 8.2 is named.
- **The fix is implied, not spelled out.** The note sits in `[2.5.3]` and says `src/qr.php` "now" turns JIT off. It
  doesn't say "upgrade to 2.5.3", and it gives no workaround for 2.5.0–2.5.2 (N-1).

### 4. #39 (PASS)

- **Base and diff:** the base is `main` at `4b26f72` and the PR is mergeable (`clean`). Exactly 1 file,
  `CHANGELOG.md`, +1/−1: the note is added under `[2.5.3] ### Fixed` and the N-3 line is removed.
- **CI:** run 39438107894 on `856ec52`, 17:19–17:29, **10/10 success**. The skipped steps are the usual conditional
  ones (coverage only on SQLite, report uploads only on failure).
- **Checks:** the link checker at `856ec52` finds 186 links in 16 files, 0 broken. gitleaks 8.30.1 finds no leaks in
  the commit.
- **Replay after merge:**
  - With #39 merged onto v2.5.3: `commits=1 bump= next= release=false`.
  - With a `docs(qa):` commit added to #39: `commits=2 … release=false`.
  - `release.php` bumps only for `feat`, `fix` and `perf`, so neither case triggers a release.

### 5. Workflow runs (PASS)

| Run | Commit | Window | Result |
|---|---|---|---|
| CI (push to `main`) 39434804177 | `c04b25f` | 16:56–17:06 | 9 jobs success; Conventional Commits skipped (PR-only) |
| Release 39436319476 (`workflow_run`) | `c04b25f` | 17:06–17:15 | success: version, changelog, tag and GitHub Release |
| — | `4b26f72` | — | no CI, because of `[skip ci]` (expected) |

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| N-1 | Nit | The note doesn't say "upgrade to 2.5.3", and it doesn't give 2.5.0–2.5.2 users a workaround. It does sit in the 2.5.3 section and says `src/qr.php` "now" turns JIT off. | Optional. Append "Upgrade to 2.5.3; on 2.5.0–2.5.2, set `opcache.jit=disable` (or `tracing`)." in #39 and with one more `gh release edit`, keeping the two identical. |
| I-1 | Info | The next release will list #39's `docs(changelog):` commit (and a `docs(qa):` commit, if added) under Maintenance. This is the usual pattern, as in 2.5.1 and 2.5.2. | None. |

No high, medium or low findings.

## Verdict

**PASS.**

- Tag `v2.5.3` (`218edfa`) points at `4b26f72`. Its parent is `c04b25f` (`42f497e` + `8b4a1a3`).
- The release commit changes only `VERSION`, `CHANGELOG.md` and the README coverage tables, and the CHANGELOG matches
  the replay byte for byte.
- The release is published, latest, with no assets, like earlier releases. Its body is the generated notes plus the
  note, minus the N-3 line.
- #39 is a 1-file change on current `main` with green CI. It doesn't release, and its CHANGELOG matches the release
  body.
- The note text is accurate. N-1 is optional.
