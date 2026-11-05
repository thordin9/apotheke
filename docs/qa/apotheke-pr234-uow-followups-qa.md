# QA review: PR #234, UoW merge-guard follow-ups (N-1776 to N-1779)

Head: `33cd94d16e0668891ae9cb45632d398aa8fbcfab` (2 commits, 0 behind main, CI 20/20).
Scope: the shared lock wait budget, the merge 409 message, and new tests. N-1780 stays in the backlog.

## Checklist

- [x] Head, commit count, CI and mergeability confirmed
- [x] Statics: lint, deptrac, DI, routes, commit check, secret scan clean
- [x] Only the merge message, the lock wait budget, tests, CHANGELOG and HANDOFF changed
- [x] Root and module suites on SQLite and PostgreSQL (one backup race test
      failed once on PostgreSQL while other suites loaded the host; it passed
      5 of 5 re-runs and that code is not in this PR)
- [x] Shared-budget test repeated 30 times, source-adjust race 20 times (both drivers), no failures
- [x] Review probes: two-key unit blocked on both keys; timeout releases held keys
- [x] Mutations against the PR's own tests

## Shared wait budget (N-1778)

- One deadline is set before the first key and passed to each key. Each key
  makes at least one try before the deadline check, so a free key is still
  taken after the budget is spent, and a busy key answers 503 at once. The
  wait cannot go negative.
- The lock path uses try-lock with back-off. It does not set lock_timeout or
  statement_timeout, so nothing leaks to later statements. Both settings read
  0 after a timed-out unit.
- A two-key unit blocked on both keys answers 503 after about 5.0 s in
  connection and batch mode, not 10 s. Production stays at 5000 ms.
- After a 503 the transaction is rolled back, and the first key is free for
  another client at once. A later unit on the same connection succeeds.
- A per-key budget mutant is killed by the new lock test. The 450 to 800 ms
  window passed 30 of 30 runs.

## Previous findings

| ID | Status |
|---|---|
| N-1776 source-adjust race | Closed: the single-key mutant is killed by the PR's tests on PostgreSQL |
| N-1777 surviving mutants | Partly closed, see N-1781 and N-1782 |
| N-1778 per-key wait | Closed |
| N-1779 409 wording | Closed: the new text matches the existing quantity conflict message. No client matches on the old text, and status codes are unchanged |
| N-1780 merged fields in batch mode | Backlog (unchanged) |

## New findings

### N-1781 Descendant-walk bound not covered by tests
The new seeded-cycle test moves a node that has no children under the cycle.
That stops at the depth check, and the bounded ancestor walk is covered (its
mutant hangs the test). The descendant walk is never entered, so removing
its bound survives. Moving a node that is in the cycle would cover it.

### N-1782 Merge guards removed together
Removing both the source-exists check on the target write and the source
delete row-count check survives the PR's tests. The preview read still
answers 404 in the tested cases. Backlog; it overlaps N-1780.

Killed by the PR's own tests: the single merge key, the last-admin guard,
the ancestor-walk bound, the per-key budget, and the old 409 text.

## Security
No secrets in the diff or history. No access-control changes. The 503 path
still releases locks, so a busy key cannot cause a lock leak.

## Verdict
**PASS**: no H or M findings. Next free ID: 1783.
