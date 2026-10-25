# QA: PR #103: CI4 step 11a part 2 (item photos)

Final at head `3c3c67e1d3ece6624397590f6ab76cb664c8b1de`.
- The fix delta `53347e8..3c3c67e` is re-verified: e06da90 (M-90, P6), a5b882a (M-91), fd6e990 (L-92 tests) and 3c3c67e (docs).
- The base `53347e8f2cc71f2dda8cceac288a9d6c1f450b1e` passed the full gate on `6420cb6`, then a merge-only read of #100.

**Verdict: APPROVE.** M-90, M-91 and L-92 are closed. The new items are Notes only.

## Gates (delta)
- gitleaks on `53347e8..3c3c67e` and `02dfee1..3c3c67e`: no leaks. The `.gitleaks.toml` sha256 matches `b5bc9f98…3a8f`.
- check-commits: 4 ok. lint: 0. check-links: 212 links, 0 broken. deptrac: 0 violations. DI rule: OK.
- The CHANGELOG change is a single sentence on the existing #103 bullet. `docs/qa` is unchanged; no QA doc was committed.
- Suites, with a fresh `qa103_` role and DB (N-71 privileges):
  - CI4 module suite: 488 OK on SQLite and 488 OK on PG.
  - Full CI4 suite: 682 OK on SQLite and 682 OK on PG.
- `Ci4PhotoRacesTest` flake check: 6 runs each on SQLite and PG, 12/12 green, run while the suites and Playwright were running.
- Playwright on CI4, Chromium projects plus pgsql-smoke: photos, item, originals and add specs, 30 passed, 2 skipped. The local WebKit build does not launch, as before.

## Reproductions (shipped router, `PHP_CLI_SERVER_WORKERS`)
- **M-90: two concurrent crop/rotate edits of one photo.** 53347e8 (before the fix) failed every round; 3c3c67e passed every round.

  | Head | SQLite | PG |
  |---|---|---|
  | 53347e8 | lost the original 10/10 | lost the original 10/10 |
  | 3c3c67e | 20/20 one 200 and one 500 "Could not read that photo." | 20/20, same |

  - On 3c3c67e the surviving original is byte-identical to the upload, with 0 stray served files, thumbnails or originals.
  - Legacy still loses it 10/10. That is a `main` follow-up, tracked in HANDOFF.
  - 6 concurrent edits: 10/10 rounds clean on both databases.
  - An edit and a delete at once: 10/10 rounds give 200 for the delete and 404 for the edit, with no strays.
- **M-91: `/save` with a 12 MP or 24 MP photo while a favourite toggle commits at 20–80% of the save.**
  - 53347e8 (before the fix): 500 in 6/12 rounds (every round with an existing location). The toggle waited up to 635 ms.
  - 3c3c67e: 303 in 48/48 rounds on SQLite and PG.
  - The toggle now waits at most 12 ms on SQLite (legacy: 5 ms) and 37 ms on PG. No strays.
- **P6: 6 concurrent adds to an item at 3 photos with a limit of 4.**
  - 10/10 rounds on SQLite and PG: exactly one 200, the rest get 400 or 422, the count stays at 4, no strays.
  - 53347e8 also returned 500 "database is locked" on SQLite here; that is gone too.

## Still holds
- **L-81.**
  - A trigger failing on items, activity, item_photos or item_tags during `/save` with a photo and a new place: 500, 0 files, 0 new places, on SQLite and PG. Legacy keeps 4 files and the place.
  - A 422 from SVG or a truncated JPEG keeps the new place (as legacy) and leaves 0 files.
  - A forced fatal error after hold (the new window between store and transaction), at the start of the transaction, or after the save: 500, tables unchanged, 0 files, on both databases.
- **Photo requirement.**
  - The original is byte-identical. The served copy is resized (1600×1067), and `/media/full` returns the served copy.
  - 5 URL tricks for reaching an original all return 404.
  - After a rotate+crop, the new photo's original is byte-identical and the old files are removed.
- **L-80.** C5 and U4 are still killed.

## Mutants (delta): 12/14 killed
- **Killed:**
  - P6: by `Ci4PhotoRacesTest` on SQLite (4/4 runs) and PG (2/2);
  - P7 and P8: by the new interleavings in `PhotoRepositoryTest`;
  - N85;
  - no compare-and-swap, and the changed-row count not checked;
  - `copy()` succeeding without an original;
  - L-81 delete-on-throw, a non-atomic save, and an ignored store error;
  - C5 and U4.
- **Survived (minor, N-97):**
  - Removing `stillCurrent()` after a failed `images->edit()`: a deleted photo answers 500 instead of 404.
  - Removing the delete on a UserError returned from the transaction: not reachable over HTTP, because names are checked before the store and duplicate names reuse the existing entry.

## Residuals (Coder's) judged
- **Races test on a slow runner.** The P6 and M-90 assertions depend only on outcomes, so they don't depend on timing. The M-91 case times the write at half of a measured save, so a slow runner can make it pass without testing the overlap (a false pass, not a flake). Acceptable; this is N-98.
- **SQLite busy window of a few ms.** Measured at ≤12 ms, close to legacy's ~5 ms, and no 500s in 48 rounds. Acceptable; `BEGIN IMMEDIATE` in Core is a good follow-up.
- **An edit whose map entry points at a missing original now returns 500.** This holds the requirement. Legacy's M-90 race leaves no map entry, and CI4 still edits those photos. The state only comes from lost originals (a restore, or an `ORIGINALS_DIR` volume that isn't mounted). The message is misleading; see N-96.
- **The losing concurrent add gets 400, as legacy.** Confirmed.

## Findings
- **M-90, M-91, L-92: closed.**
- **N-93, N-94, N-95:** tracked in HANDOFF; accepted.
- **N-96. An edit of a photo whose original file is missing (map entry present) always answers 500 "Server could not save the image."** Legacy edits it without an original. Delete still works.
  - Suggestion: a clear message such as "This photo's camera original is missing", plus a log line.
  - Because the compare-and-swap already blocks the M-90 loser, a later option is to allow the edit and drop the stale map entry.
- **N-97.** Add tests for the two surviving defensive branches: 404 when a photo is deleted during the edit's decode, and no files when the transaction returns a UserError.
- **N-98.** Make the M-91 race test confirm the overlap happened, for example by checking that the write landed between the save's start and end, or by holding the decode with a hook.
