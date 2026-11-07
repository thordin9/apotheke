# PR 247 restore storage — re-gate

**Verdict: PASS.** **M-1834 is closed.** The catalog owner is the committed `restore_marker` fence row. A new object-lease holder does not commit catalog, settings, undo, or map-pointer work until that claim commits, and a stale token's transaction rolls back. One low finding remains.

Gated and latest `986da304aca8e4ce62e0834268eeea11510009c4`. Previous HOLD `b1531826ed7a6dcb9506cd986aaa4192916bcdbd`. Branch `cursor/p4-4-restore-storage-e9c8`. Base `cf6f64b5924c2ebcdf206e45707f83cae8037f01` (v3.24.0). Behind 0. Commits 6. Diff 27 files, +3002/−58. Same at start and end. CI on that head: 21 passed, 0 failed, 0 pending. The deploy workflow did not run; this diff matches none of its path filters.

## Checklist

| Check | Result |
|---|---|
| Catalog commit | On SQLite and PostgreSQL, a second claim did not commit while the first transaction held the fence row. The holder's insert committed. A later insert with the old token rolled back. |
| Bridge write | A map-pointer PUT was held open. The claim had not committed and the object was absent. After the PUT finished, the new owner wrote the pointer. Same result on both drivers. |
| Settings, undo, cleanup | A stale settings import and a stale undo prune rolled back. The new owner's name was kept, and the undo file was not released. Cleanup after the committed claim left the new photo bytes. |
| Media | A conditional create did not replace bytes already stored. That matches the local keep-existing check. |
| Chunks | A stale create did not delete a chunk the new holder had committed. The next append stored the new bytes. |
| Two databases | Each database has its own fence row. Local restore still uses the filesystem lock and does not set the fence. |
| Release, renewal, tag | A lost release still retries and leaves another holder. `version()` is still one GET. M-1833, M-1830, M-1831, and N-1832 stay closed. |
| Index order | The index is written before the manifest. Reversing that failed the order test. N-1837 closed. N-1835 and N-1836 stay closed. |
| Schema | No new migration. The fence is a row in the existing marker table. Upgrade and rollback passed on both drivers. |
| Bounds | Chunks stay at most 16 MiB. The bridge cap stays 64 MiB. Confirm stays in the request. No worker or readiness change. |

## Findings

**M-1834 (closed).** The in-flight catalog transaction, the map-pointer replace, settings, undo, and cleanup now take or check the fence row. A committed claim is the takeover. The probes above did not let the old token change the new owner's committed rows, pointer, or received chunk.

**L-1838 (new).** On SQLite, a claim that waits on an open fence transaction returns a write failure after 10 seconds, and the token stays with the holder. A bridge call inside that transaction can outlast that wait. The failed claim does not take the fence and does not write. PostgreSQL waited for the holder in the shorter probes.

**Tracked, untouched.** N-1786 (deferred), N-1794, N-1795, L-1811, N-1812.

## Security

The fence token is the object-lease token, compared by the row update. Release of the object lease still refuses another holder. User-facing errors stay generic. Names are a fixed prefix plus a 32-hex id. The diff has no account, bucket, or host secret. Cleanup deletes only after the same token still owns the row.

## Tests

Lease, store, and version tests: 24 tests, 128 assertions, passed. Module restore and fence filter, both drivers: 87 tests, 686 assertions, passed, including the existing hundred-thousand-row staged swap. Root SQLite and PostgreSQL: 585 tests, 1 failure each. Nested modules: 1136 SQLite and 1594 PostgreSQL, 2 failures each. Those are the known zip-entry text, the ImageMagick 6 alpha case, and the module wrapper. CI SQLite and PostgreSQL passed. Worker tests 57 passed. Types match. Upgrade and rollback: both drivers, all steps equal. Mutants killed: fence check removed, index order reversed, release retry removed, split tag. A live kill during swap was not performed.

## Verdict

PASS. Next free id is 1839.
