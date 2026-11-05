# QA review: PR 229, staged restore follow-ups and 2.6.26 parity (P4-2b, part 2b)

- PR: thordin9/apotheke#229, `feat(backup): staged restore follow-ups and 2.6.26 parity`
- Head reviewed: `866f3718f961a20128c37448ca1c5b01fd404588` (0 behind main `bf9c7a1`, CLEAN), 15 commits on the branch (12 files, +519/-42 against main)
- Gate: full, security and data integrity focus
- Finding IDs: 1712..1715 used

## Design-B conditions and the #227 findings

- [x] **Conditions 1 and 5, the 2.6.26 round trip:**
  - Upgrade gate step 5b takes the legacy backup through the staged path in both modes. It stops before the swap (nothing changed, not marked), sweeps, stops after the swap (rows, marked applied, parity), then runs a full restore.
  - It checks ids, sequences and a `parent_id` pointing at a later row.
  - The CI logs for the head show all 18 step-5b checks passing on SQLite and on PostgreSQL.
  - QA ran the gate locally on both drivers with every step equal. PHP 8.4 stood in for the 8.3 legacy runtime, so the CI legs remain the authoritative 8.3 run.
- [x] **Conditions 2 to 6:** no regressions.
  - Copies stay out of backups, the reserved prefix holds, and the pre-flight still answers 413.
  - The one-batch swap is still pinned, and parity holds in both modes on both drivers.
  - ADR-002 and `docs/database.md` are current.
- [x] **N-1704 (column check):**
  - Columns must exist in the live table, each once, and the list must be non-empty. Upper case, quoted, padded and injection-shaped names are refused before any SQL is built.
  - Booleans become 0/1, and arrays and objects are refused.
  - Restorer's scan also refuses a duplicated column. Both checks are pinned by mutants.
- [x] **L-1707 (retention):**
  - Retention is bounded at 20 runs. A pruned or unknown run resolves as interrupted ("may have been applied"), never as "Nothing was changed".
  - Run ids are 128-bit random upload ids, read only after the owner check, and each install has its own marker.
  - One ordering limit remains (L-1713).
- [~] **L-1708 (lease tests):** renewal and takeover are pinned. The lease length is not (L-1712).
- [x] **N-1710 (sweep):**
  - The sweep reads first and writes only for copies or a stale lock. It clears a stale lock together with its copies.
  - It never drops copies while a live lease is held. A restore that lost its lock cannot stage or swap afterwards.
  - Each chunk renews the 120 s lease. The swap's lock check is the first statement of its batch, so the swap's own duration does not count against the lease.
- [~] **N-1711 (DB clock):** every lease read and write uses the database clock (`datetime('now', …)` on SQLite and D1, `now()` in UTC on PostgreSQL), and the probes showed a 120 s lease on both drivers. Run timestamps still use the app clock (L-1713).
- [ ] **N-1709:** remains in the backlog.
- [ ] **Real D1 timing** at 100k rows: not measured.

## Checklist

- [x] Root suite: SQLite 463 OK, PostgreSQL 17 (own scratch cluster) 463 OK
- [x] Module suite: SQLite 974 OK, both drivers 1,335 OK
- [x] `--filter 'Backup|Restor'`: 137 OK on both drivers
- [x] Upgrade/rollback gate: local run on SQLite and PostgreSQL, all steps equal; step 5b also passes in CI on both
- [x] Mutation run: 18 mutants, 15 killed, 3 survived (L-1712, N-1714)
- [x] Lint, deptrac (0 violations), `apotheke:routes --check`, gitleaks: clean
- [x] CI on the head: 20/20 green, CLEAN

## Findings

### High

None.

### Medium

None.

### Low

- **L-1712: the lease length is not pinned.** Changing `LEASE` to 1 s or to about two weeks survives the suite. A very short lease would make long restores lose their lock between chunks, and a very long one would delay recovery after a crash. A test with the test clock at LEASE-1 and LEASE+1 would pin it.
- **L-1713: run order uses the app clock, at second resolution.** Each run's `applied_at` comes from the PHP clock, unlike the lease.
  - Two swaps in the same second both report "replaced", including the current one.
  - A later run stamped earlier by a skewed instance makes the earlier run read as applied.
  - Retention prunes by the same order.

  Stamping runs with the database clock and breaking ties by insertion order would remove these effects. The docs' "every instance agrees" covers only the lease today.

### Note

- **N-1714: copies with no lock row have no sweep test.** If a drop fails after the lock is released, the copies are left with no lock row. The code clears them today, but a mutant that skips this case survives.
- **N-1715: the upgrade gate needs PHP 8.3** for the legacy steps, so QA's local run substituted 8.4.

## Security

- Identifiers: tables are allow-listed. Columns pass a strict pattern and a live-schema check before any SQL is built. Values are bound as one JSON parameter, and non-scalar values are refused.
- The lock uses a random token per stage. Release and renewal are limited to the holder. Expiry and takeover use the database clock.
- Run ids are random upload ids, read only on the owner's own upload. Outcomes reveal nothing across accounts or installs.
- Copies never enter app backups, and the sweep does not drop copies under a live lease.
- There are no new routes, and self-hosted restores are unchanged, apart from now refusing a duplicated column.
- gitleaks: no leaks.

## Verdict

**PASS.** No High or Medium findings, and conditions 1 to 6 hold, including the 2.6.26 round trip in CI on both drivers. L-1712 and L-1713 can follow up.
