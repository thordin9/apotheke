# PR 259 R2 file storage — QA

**Verdict: PASS** at `fbbc5cca61c5baa62a2da935cfc14d9fe93ff576`. Behind main `0a2c42c9` (v3.31.0): 0. H-1849 stays closed. N-1850 stays open. No new finding.

Branch `feat/p4-5-r2-files`, now based on `main`. The previous head `bd0ef409` is an ancestor. The move is two no-ff merges, of `9986ebfb` (#258) and then `0a2c42c9`.

## Checklist

| Check | Result |
|---|---|
| Delta | From `bd0ef409`: the #258 squash, the v3.31.0 release, and the two merges. The file list is `CHANGELOG.md`, `README.md`, `VERSION`, and the two #258 QA notes. Those notes, `README.md`, and `VERSION` match main. |
| Own diff | Against `0a2c42c9`, the diff outside `CHANGELOG.md` has the same hash as `bd0ef409` against `eebc5a36`. The added changelog lines are the same set. The full diff hash differs only by the `## [3.31.0]` context. |
| Conflicts | Merging `9986ebfb` conflicted in the changelog, the handoff, and the storage ADR. The release merge conflicted only in the changelog. The tip differs from that automatic release merge only in `CHANGELOG.md`. |
| D1 files | `D1Pdo.php` and `D1MigrationRunner.php` match main. |
| Changelog | Both #259 bullets appear once under Unreleased. `## [3.31.0]` matches main, including the single #258 bullet now in that section. |
| CI | `D1 (local workerd)` job `130354554420` succeeded. Rollup at the last look: 10 success, 11 pending. No failed conclusion. |

## Findings

H-1849 stays closed. N-1850 stays open: the stand-in still refuses `_cf_` inside a string literal, and that file now matches main. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1851.

## Security

Storage mode still selects R2 only with `DB_DRIVER=d1`. The preview still skips `sqlite_` and `_cf_` names before reading a committed table. An unusable R2 selection is still an error, not a disk fallback.

## Tests

None. This pass is the diff and the hashes only.

## Verdict

PASS. The hosted gate stays unsatisfied.
