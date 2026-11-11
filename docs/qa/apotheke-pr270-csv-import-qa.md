# PR 270 hosted CSV import — QA

**Verdict: PASS** at `e4e1f500d19f50d62b5599c67bad373aad9cf59f`. Behind main `f4667cf2` (v3.35.0): 0. H-1849 stays closed. N-1850 stays open. No new finding.

Branch `cursor/p4-5-2b-chunk2-ii-b-import-e9c8`, now based on `main`. The previous head `d65727d5` is an ancestor. The move is `f7986e86` (merge of `f412d9f0`) and then `e4e1f500` (merge of `f4667cf2`).

## Checklist

| Check | Result |
|---|---|
| Delta | From `d65727d5` the files are the v3.35.0 release (`CHANGELOG.md`, `README.md`, `VERSION`), the two #265 QA notes, and #272's five files. `README.md` and `VERSION` match main. |
| First merge | Merging `f412d9f0` conflicted in the changelog, the handoff, the ADR, and three D1 test files. The tip differs from the automatic tree only by removing the other side of those conflicts. The result keeps #270's side. |
| Own diff | Against `f4667cf2` the diff is 13 files and contains no #265-only code. Excluding the changelog and `docs/qa`, the normalised diff matches the round-6 diff of #270 against #265. The raw hash differs because #265's bullets now sit in `## [3.35.0]`. |
| Second merge | The merge of `f4667cf2` is automatic. The tip tree matches it. The added files are #272's five: two docs, the QA note, the check page, and `scripts/storage-preview-check.sh`. |
| Changelog | The #270 bullet appears once under Unreleased. `## [3.35.0]`, `## [3.34.0]`, `## [3.33.0]`, and `## [3.32.0]` match main. |
| Handoff | The next-step line matches main, including "Until 2-ii-B,". The CSV per-line paragraph remains #270's own line. |
| CI | Run `43096807215` was still queued with no jobs, so D1, SQLite, and PostgreSQL had not started. CodeQL run `43096801631` ended `startup_failure`. Main had no failed check in the recorded list. |

## Findings

H-1849 stays closed. N-1850 stays open. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1851.

## Security

Hosted CSV import is still one batch per line, with the 5 MB and 5,000-row caps. The preview still skips internal table names. Hosted bulk edit matches main.

## Tests

None. This pass is the diff and the hashes only.

## Verdict

PASS. The hosted gate stays unsatisfied.
