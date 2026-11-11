# PR 262 hosted batch writes — QA

**Verdict: PASS** at `bbb33eaad97b44b873dafc8fa9b7adc0da0770fc`. Behind main `60a5c1fb` (v3.32.0): 0. H-1849 stays closed. N-1850 stays open. No new finding.

Branch `feat/p4-5-2b-chunk2-ii-a-v2`, based on `main`. The previous head `611ae721` is an ancestor. The only new commit is the no-ff merge of `60a5c1fb`.

## Checklist

| Check | Result |
|---|---|
| Delta | The merge brings #259 (`5bf98072`) and the v3.32.0 release. Code and test files that #259 also touched auto-merged. `Services.php` and `TestDatabase.php` keep the same patch-id as the previous own diff. |
| Own diff | Outside the changelog, the handoff, and the ADR, the normalised diff matches `611ae721` against `0a2c42c9`. The added changelog lines are the same set. |
| Handoff | The Piece 2b line keeps the chunk 2-ii-A sentence and #259's storage sentences. Next step 1 keeps #262's 2-ii-B wording, keeps "already follow StorageFactory", and says "Until 2-ii-B,". The future-tense storage clause is gone. The 501 paragraph is still there once. |
| ADR | #262's batch and 501 sentences stay, and #259's four storage lines follow. The stale "write paths move to the batch path next" line does not. |
| D1 and storage | `D1Pdo.php`, `D1MigrationRunner.php`, and #259's storage files match main, except `Services.php` and `TestDatabase.php`, which carry #262's own patch. |
| Changelog | Both #262 bullets appear once under Unreleased. `## [3.32.0]` and `## [3.31.0]` match main. |
| CI | `D1 (local workerd)` job `130444813223` succeeded. Rollup at the last look: 19 success, 2 pending. No failed conclusion. |

## Findings

H-1849 stays closed. N-1850 stays open with the stand-in that matches main. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1851.

## Security

Hosted item, account, undo, and search writes still commit as one batch. Bulk edit, CSV import, and backup restore still answer 501 `hosted.unavailable` before anything is stored. Photos, originals, attachments, imports, and brand files follow the selected storage, as on main.

## Tests

None. This pass is the diff and the hashes only.

## Verdict

PASS. The hosted gate stays unsatisfied.
