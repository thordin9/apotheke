# QA: PR #164, release notes link merges titled like squash commits (+ M-1080 VERSION mask)

- **Head:** `8e5dd03e74d2a3413ab4e055b8fa301053e75b79`. 5 commits, base main `940cf52` (v3.0.0), 0 behind, mergeable, same repo.
- **CI:** run 40999996160 passed 14/14 on this exact head.
- **Verdict: PASS.** No H/M findings; L/N items below.

## Checklist

- [x] **Files.** `scripts/release.php`, `tests/Unit/ReleaseTest.php`, `tests/Unit/Ci4PagesGoldenTest.php`, `CHANGELOG.md` (Unreleased), plus `tests/Unit/Ci4BranchCiTest.php` (N-1101). No app, Docker or workflow changes; `.gitleaks.toml` is unchanged.
- [x] **Release fix (one line).** In `rel_commits()`, a first-parent merge on the release branch is now attributed to PR #N when its subject is either `Merge pull request #N …` or ends in `(#N)`.
  - Only merge subjects are read. The `$` anchor means text like `(#N) …` in the middle of a subject is ignored.
  - The existing squash path (a non-merge subject ending in `(#N)`) is unchanged.
- [x] **Probe on a synthetic repo, new vs old script:**
  - A merge ending in `(#14)` links to 14 (new).
  - `Merge pull request #16` still links to 16, and an inner `(#15)` commit keeps the merge's 16 (unchanged precedence).
  - These stay unlinked, as before: a merge with `see (#12) for context`, a merge `Revert "… (#13)"`, a plain merge, `(#17)` only in a body, and a commit with `(#18)` mid-subject.
- [x] **Real history, v2.6.26..v3.0.0.** Old and new scripts differ only in 7 commits now linked to #29. I checked via the GitHub API that #29's merge commit is `8b87609` ("… (#29)"). There are no other differences across the 635 commits.
- [x] **No effect on versions or released notes.**
  - `rel_bump()` reads commit types only, and `pr` feeds only the link text of the new section.
  - The released CHANGELOG sections are byte-identical to main (same sha256).
  - `release.php plan` on PHP 8.3 gives `last_tag=v3.0.0`, `commits=5`, `bump=patch`, `next=3.0.1`. This is the expected 3.0.1 for a `fix` once the PR is merged.
- [x] **Tests.** `ReleaseTest` 23 pass (1 skipped: there is no upgrade note under Unreleased) and `Ci4BranchCiTest` 12 pass (1 incomplete without PG), both on PHP 8.4.
  - The PHP 8.3 run stops at Composer's platform check (the lock needs PHP 8.4), so I did the dry run on PHP 8.3.
  - Mutation: removing the new `(#N)$` alternative fails `ReleaseTest::testCommitsFromAGitHistory`.
  - Mutation: removing the `$` anchor is not caught (L-1100).
- [x] **M-1080 mask.**
  - `Ci4PagesGoldenTest` passes on SQLite at the head with VERSION `3.0.0`; on main it still fails.
  - Changing the footer `v` prefix or the `title` text fails the golden, so the mask does not hide the footer markup.
  - VERSION `3.7.12` and `3.0.0-rc.1` both pass (the version is read from VERSION at run time).
- [x] **Secrets.** gitleaks 8.30.1 on `940cf52..8e5dd03`: no leaks.

## Findings

**L-1100: the new `$` anchor is untested.** With the `$` dropped, a merge whose subject merely contains `(#N)` would be linked, and `ReleaseTest` still passes. **Fix:** add a merge with mid-subject `(#N)` text to the test history and expect it to stay unlinked.

**N-1101: one file beyond the stated scope.** `Ci4BranchCiTest` now pins `release.yml`'s whole trigger block, the single `branches:` and the atomic push. This closes the earlier N-1061 suggestion and is test-only.

**N-1102: the mask matches every occurrence of the version.** `str_replace` masks the VERSION string anywhere in the page, not only in the footer. This is harmless with the seeded data. Anchoring it to `v<VERSION></span>` would keep it as narrow as the CSRF mask.

**N-1103: commit order is no longer pinned.** `ReleaseTest` now compares the commits as a set (`assertEqualsCanonicalizing`), because commits made in the same second can come back in any order. "Oldest first" is therefore no longer pinned there.

## Notes

The QA doc commit on the branch re-triggers CI on the new head. Merging this `fix` makes the release workflow cut v3.0.1.
