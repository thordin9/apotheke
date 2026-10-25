# QA: apotheke v2.6.13 release (#86, footer version width)

Scope: release diff read only (no tests run; #86 light-gate approved at `4d64a06`). Read-only clone, no API.

**Verdict: PASS** (0H / 0M / 0L / 1N / 4I)

## Checklist

- [x] Tag `v2.6.13` = `8ba9511` `chore(release): v2.6.13 [skip ci]`, parent `0779215` = merge of #86 (parents `c3c14de` v2.6.12, `4d64a06`).
- [x] PR final head = approved head `4d64a06`; no commits after it. `git diff 4d64a06 0779215` is empty.
- [x] Tag patch touches only `VERSION` (2.6.12 -> 2.6.13) and `CHANGELOG.md`; README unchanged (CSS-only change, no coverage delta). Excluding VERSION/CHANGELOG/README, `c3c14de..v2.6.13` equals `c3c14de..0779215` (identical diff).
- [x] Net release vs v2.6.12: `public/assets/app.css`, `CHANGELOG.md`, `VERSION`, 34 list-footer PNGs.
- [x] CHANGELOG `[2.6.13]`: one Fixed bullet (**ui**, #86) matches the PR bullet verbatim; `[Unreleased]` empty; compare links added.
- [x] Dedupe: one Fixed line; no duplicate fix line from commit subjects. Maintenance lists 3 test/docs commits only.
- [x] `[2.6.12]` to EOF identical to the v2.6.12 tag except the expected `[Unreleased]`/`[2.6.13]` link lines.
- [x] docs/qa in tag: all files except `2.4.0-qa.md` match /workspace/qa-docs (incl. `apotheke-pr84-session-lifetime-qa.md` `e7192070…`, `apotheke-v2.6.11-release-qa.md` `85f31395…`). `2.4.0-qa.md` has no local copy; unchanged since v2.6.12.
- [ ] CI / Release workflow runs: not verified (API 403).

## Findings

- **N-1:** Maintenance lists `test: pin the footer version label before screenshots` (`b5db93a`), which was reverted in the PR (revert `0d3202e` not listed); net effect is nil, so the line slightly misdescribes what shipped.
- **I-1:** `apotheke-v2.6.12-release-qa.md` (`7cfbba61…0758e`) is not in the tag; it rides in #87 (`63b1d32`). Hash-check when #87 merges.
- **I-2:** `docs/qa/2.4.0-qa.md` has no copy in /workspace/qa-docs to compare; untouched by this release.
- **I-3:** No README coverage change (no PHP changed).
- **I-4:** No hostnames, keys or tokens in the release diff.
