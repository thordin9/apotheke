# QA review: PR #232, remaining unit-of-work sites (part 1)

- PR: #232 `feat/uow-remaining-sites` (Coder2): P4-2b remaining sites, part 1
- First review at `00b109c` (HOLD, M-1752). Re-review at head `e5a94699e1aa8e26679db641f3919f909e44bd52` (9 commits, 0 behind main)
- Scope: item save, TagRepository::set, Taxonomy create/createUnder, QuantityAdjust, Loans lend/giveBack, L-1746 fold-in, import savepoint rule, HANDOFF
- QA gate, 5 Nov 2026 (Europe/Athens)

## Checklist

| Check | Result |
|---|---|
| Head, 0 behind, mergeable; CI | confirmed, clean; 20/20 success |
| Root suite SQLite / PostgreSQL 17 | 463 OK each (6 / 1 skipped; one PostgreSQL log-check failure came from QA's own concurrent runs writing to the shared log directory and passed on a clean re-run) |
| Module suite SQLite / PostgreSQL env | 1397 OK each |
| lint, deptrac (0), DI, routes --check, check-commits (9), gitleaks | clean |
| Migrations; out-of-scope sites | none; unchanged (the fix diff touches only QuantityAdjust, Loans, TaxonomyRepository, tests, CHANGELOG, HANDOFF) |
| Real-parallel probes, both modes, both DBs, 3 rounds each | all OK (below) |
| Race tests plus the L-1746 lock test, 30 runs (both drivers) | 30/30 OK |
| Mutations at re-review (12) | 11 killed, 1 survived (N-1761) |

## Verified

- **Item save, TagRepository::set, import rule.** Unchanged since the first review and verified there: create through a Ref, update re-checks the item, tags bound by `name_key` with `ON CONFLICT`, and only the unit's own savepoint is allowed inside an import row.
- **QuantityAdjust.** The write is conditional on the quantity that was read, must change exactly one row, and is retried up to 16 times with a short back-off capped at 50 ms (about 0.4 s in total), then answers 409. The loop is bounded, and a retry happens only after the failed unit has rolled back. Under parallel load:
  - Six +1s reach 16 with six history rows. The history rows form an unbroken from→to chain that ends at the stored value.
  - Six -1s from 3 stop at 0 with three history rows.
  - 24 parallel +1s all land.
  - With the retries cut to one, the requests that don't land return 409, and the history still matches the row.
- **Loans.** The unit lock alone serialises the item; the row lock and outer transaction are gone. A lend/return/+1 mix gives no 503 and no request over 2 s on either DB or mode. giveBack writes only while the item is still lent, so parallel returns log exactly one "returned" row.
- **createUnder.**
  - The parent-exists guard is inside the unit.
  - The depth check is a recursive walk up from the parent inside the unit. It allows a child only while the parent's chain is at most MAX_DEPTH long, which matches the earlier 0-based tree depth; both off-by-one mutants are killed. The walk stops after 50 steps, so damaged parent data can't make it loop.
  - Name races return the stored row with `created: false`.
- **create.** `created` is true only when this call inserted the row.
- **Shared units.** A test now checks that the item and tag services share one UnitsOfWork.

## Findings

### High
None.

### Medium
None open. **M-1752: closed** (conditional write with bounded retry; probes above).

### Low
None open. **L-1753: closed** (unit lock only; no 503 under the mixed probe).

### Note

- **N-1754: closed.** The four earlier survivors are now killed, except lend expect-1 (N-1761). The row-hold mutant no longer applies because the hold is gone.
- **N-1755, N-1756, N-1757: closed** (shared-units test, ON CONFLICT plus depth check in the unit, accurate `created` flag).
- **N-1758: open (backlog, noted in HANDOFF).** In batch mode, cover-photo renumbering uses a photo list read outside the lock.
- **N-1761.** The lend write's expect-1 mutant survives. It matters only when the item is deleted between the read and the batch. Connection mode serialises that case through the unit lock; a batch-mode race test would cover it.
- **N-1762.** giveBack and createUnder recognise their own conflict by its code and message. Inside a caller's open unit, such a conflict still fails the caller's unit. No current caller nests these calls.
- **N-1763.** A location whose parent chain is damaged (for example, it points to itself) is now refused as too deep instead of being treated as a top-level location. This affects only damaged data.

## Security

- No secrets or identifiers; gitleaks is clean.
- No routes, permissions, migrations or dependencies change.
- The retry loop is bounded in attempts and time and holds no lock while it waits.

## Verdict

**PASS.** No open High or Medium findings. N-1758, N-1761 and N-1762 can be handled in follow-ups.
