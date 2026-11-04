# QA review: PR 223, restore staging and one-batch swap (P4-2b, design B part 1)

- PR: thordin9/apotheke#223, `feat(backup): stage restores and swap them in one batch`
- Head reviewed: `9136671d66377264c1c64a9088367abaa23f824b` (0 behind main `05ffb6a`), 7 commits, 14 files, +652/-23
- Gate: full, security and data integrity focus
- Finding IDs: 1700..1706 used

## Design-B conditions

No separate design-B document was found in the repo. The review used the plan in HANDOFF (staged copies plus an atomic swap in batch mode, in two PRs), ADR-002 (edge limits, `defer_foreign_keys`, restore as compensating work) and the PR body.

- [x] Staged copies, live tables untouched until the swap: verified, both modes, both drivers
- [x] Swap is one batch and all or nothing: verified in code and by probes. Not pinned by a test (L-1701)
- [x] Two PRs, deferred items named: wiring into the restore API, `applied()` for interrupted uploads, the sweep and the 2.6.26 round trip are listed for part 2. The pre-flight call site is not listed (N-1703)
- [x] D1 limits: one bind per staged statement, at most 512 KB. The 100k-row run used 20 chunks on SQLite and 31 on PostgreSQL, with a largest bind of 524,278 B
- [ ] ADR-002 updated for design B: not done (L-1702)

## Checklist

- [x] Root suite: SQLite 459 OK, PostgreSQL 17 (own scratch cluster) 459 OK
- [x] Module suite: SQLite 915 OK, both drivers 1,223 OK
- [x] `RestoreStageTest` 5x on both drivers: green, no flakes; config and migration unit tests green
- [x] Migration 0032 is CI4 only: it is in `UNCARRIED_MIGRATIONS` and `upg_ci4_only()`. The CI upgrade/rollback job seeds `restore_marker` and keeps it through every step on SQLite and PostgreSQL
- [x] Copies: only carried tables (allow-list), prefixed, absent from backups, removed by `begin()` and `drop()`; no leftovers after `drop()` in probes
- [x] Pre-flight: 413 at the documented boundary; the limit and status mutants are killed
- [x] Parity: rows, ids, sequences (SQLite text form), child before parent, history past the last item, stop before and after the swap
- [x] Mutation run: 23 mutants on `RestoreStage`, 20 killed, 3 survived (N-1706, L-1701)
- [x] Lint, deptrac (0 violations), `apotheke:routes --check`, gitleaks on the PR range: clean
- [x] CHANGELOG, HANDOFF, `docs/database.md`, `docs/configuration.md` and the PR body checked
- [x] CI on the head: 20/20 green

## Findings

### High

None.

### Medium

None. Nothing calls the stage yet, so no finding below can be reached from a request today.

### Low

- **L-1700: concurrent restores can mix their copies.** The copies are shared by name and carry no run id. A probe with two runs of equal row counts showed the first run's swap applying the second run's rows and marking the first run as applied. A mismatch in counts is refused by the guard, and a dropped copy fails the batch, so nothing is half applied. Condition for part 2: serialise staged restores (one restore at a time, behind the maintenance flag or a lock), or tie the copies to the run.
- **L-1701: the swap's single batch has no test.** Splitting the swap into two batches survives the suite. The 100k test counts the staging batches but not the swap. A test that asserts the swap is exactly one `run()` would pin the core all-or-nothing claim.
- **L-1702: ADR-002 still describes the earlier D1 restore.** It names a Time Travel bookmark and batches of about 1,000 rows. `docs/database.md` describes design B. The ADR should be updated to match, or point to the new section.

### Note

- **N-1703: the pre-flight has no caller.** `RESTORE_DB_MAX_MB` is read into config and `preflight()` exists, but nothing calls it, and nothing measures the staged size yet. The configuration docs say the limit is checked before staging. Part 2 should name the call site and how staged bytes are estimated, including index overhead after the swap.
- **N-1704: column lists are checked for form, not membership.** A plain name not in the table fails as a write error. A duplicate column list (`id, id`) is accepted on SQLite. A PHP boolean for an integer column fails on PostgreSQL but not on SQLite. Part 2 should check columns against the live table and normalise values as `Restorer` does.
- **N-1705: leftover copies hold restored data until the next `begin()` or `drop()`.** They stay out of app backups, but they remain in the database and in database-level dumps. The planned sweep in part 2 covers this.
- **N-1706: the two survivors claimed in the PR are equivalent.** They are dropping the deferred foreign keys and deleting parents first. Every foreign key into carried tables is `ON DELETE CASCADE` or `SET NULL`. Copies are inserted parents first, one statement per table. SQLite and D1 check immediate foreign keys at the end of each statement, so a child staged before its parent still passes. Keeping the deferral as a safeguard is reasonable.

## Security

- Injection: table names are allow-listed against the carried tables. Column names must match a plain lower-case pattern. All values are bound as one JSON parameter. Probes with quotes, reserved words and invalid UTF-8 were refused or stored verbatim.
- No new endpoint, route or permission. The 413 message names no sizes.
- Copies never enter app backups. Leftovers are covered by N-1705.
- Edge-phase blockers: no filesystem use and no per-request global or static state (constants only). The one-statement-per-table swap on D1 has not been measured against D1's per-query time limit at 100k rows, so part 2 should measure it on a preview D1.
- gitleaks: no leaks.

## Verdict

**PASS.** No High or Medium findings. L-1700 is a condition for part 2, before the stage is wired into the restore API. L-1701 and L-1702 can follow up.
