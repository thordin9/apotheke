# QA review: PR #233, remaining UoW sites (part 2)

Head: `12e5253d250141868e24069a8698e3ad7d0981f1` (14 commits, 0 behind main, CI 20/20).
Earlier rounds: HOLD at `b462d747` (M-1764, M-1765) and at `3ab199f2` (M-1771).

## Checklist

- [x] Head, commit count, CI and mergeability confirmed
- [x] Statics: lint, deptrac, DI, routes, commit check, secret scan clean
- [x] #232 code intact; Bulk, SearchIndex, Imports and migrations untouched
- [x] Root and module suites on SQLite and PostgreSQL
- [x] Race and lock tests repeated 25 times, both drivers, no failures
- [x] Parallel probes in batch and connection mode on both drivers: merge with
      concurrent adjustments, crossing and chained merges, self merge, photo
      positions and cover, seeded parent cycle, last active admin
- [x] Mutations on the new guards

## Multi-key units

- PostgreSQL takes one advisory transaction lock per key. Keys are
  de-duplicated and sorted the same way for every unit. Single-key units hold
  one key, and nested units join the open unit without taking new locks, so
  lock order cannot form a cycle.
- A timeout raises 503 inside the transaction. The rollback releases every key
  already held.
- SQLite connection mode takes the database write lock, which covers every key.
  The SQLite batch runs as one atomic batch. The quantity write is relative,
  and the source delete must affect exactly one row, so a stale read cannot
  lose quantity.
- A self merge is rejected before any lock or write.

## Previous findings

| ID | Status |
|---|---|
| M-1764, M-1765, L-1766, N-1768 to N-1770 | Closed (re-confirmed) |
| L-1767 merge locks only the target | Closed with M-1771 |
| M-1771 merge loses quantity | Closed: totals and concurrent adjustments kept in all modes |
| L-1772 generic merge write error | Closed: only 404 or 409 seen |
| L-1773 photo position collision | Closed: positions unique, cover follows position after a delete |
| N-1774 unbounded recursion | Closed: a seeded cycle ends with 422 |
| N-1775 settings rewrite | No issue |

## New findings

### N-1776 Coverage of the merge lock keys
A mutant that keeps only the target key survives the PR's own tests. It is
not equivalent: the probes show lost source adjustments and a lower total
across crossing merges. Add a race test for adjusting the source during a
merge.

### N-1777 Mutants that survive the PR's tests
The last-admin guard and the unbounded cycle query survive the PR's tests,
but the review probes catch both. The source-exists check on the target
write, and the source delete row-count check, each survive alone, because
the other one still guards the case.
Changing the stocktake upsert to do nothing is equivalent.

### N-1778 Lock wait is per key
The wait limit applies to each key separately, so a two-key unit can wait up
to twice the limit before it answers 503.

### N-1779 Response wording
When the target write misses, the response is 409 with the message
"Item not found.". A message that names the conflict would be clearer.

### N-1780 Merged fields read before the write in batch mode
Tags, notes and the other merged fields come from the preview read. In batch
mode without a unit lock (the edge path), a concurrent edit to these fields
can be overwritten. This is the known batch read-then-write class, not a
regression. Backlog.

## Security
No secrets in the diff or history. The last-admin guard holds under
concurrency. No access-control changes.

## Verdict
**PASS**: no open H or M findings. Next free ID: 1781.
