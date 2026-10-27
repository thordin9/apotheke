# QA: main PR #130 (CSV import lock, port of CI4 #127)

PR thordin9/apotheke #130 → main. Head `4d506aa`, base `c6b6ff7` (v2.6.21, also the merge-base). QA date 2026-10-26. Finding IDs start at 390.

CI run 40396751582 is 10/10 green at the head. In attempt 1, Playwright shard 1/3 failed; the re-run passed, and every job's latest attempt succeeded (read-only check).

**Verdict: PASS** (0 H / 0 M / 0 L / 3 N). Nothing blocks the merge.

## Checklist
- [x] Lint is clean on 8.3 and 8.4.
- [x] gitleaks on `c6b6ff7..4d506aa` finds no leaks; `.gitleaks.toml` is unchanged.
- [x] No migration or schema change. The CHANGELOG has one hardening line with the PR link.
- [x] The full suite passes 518 tests on all four legs: 8.3 SQLite (13 skipped), 8.3 PG17 (3), 8.4 SQLite (13), 8.4 PG17 (3). The 2 new ImportTest tests pass on both DBs; PG probes report the `pgsql` driver.
- [x] Driver semantics for the first write, `status = status … status <> 'done'`, on 8.3 and 8.4:
  - **rowCount counts matched rows, not changed values**, on SQLite and PG. A no-op match gives 1, a done or missing row gives 0, and a same-value UPDATE gives 1, so the check never refuses a valid commit.
  - **SQLite:** the no-op UPDATE takes the write lock even when no row matches. A second writer gets "database is locked" with busy_timeout 0.
  - **PG:** a matched no-op UPDATE row-locks the row. A concurrent one blocks (it hit lock_timeout at 300 ms), while other rows and plain reads stay free. After the holder commits `done`, the waiter re-checks and gets rowCount 0, about 307 ms later.
- [x] Mid-file errors, base = head:
  - A row error without "skip" gives 422, imports nothing, leaves the import not done and keeps the file.
  - With "skip", the commit gives 303 and is done.
  - A database error on row 3 gives 500 and rolls back with 0 rows, not done, file kept. The retry imports all 4 rows once.
- [x] A preview writes nothing: items, tags, locations, categories, activity and item tags are unchanged (with "create" on), and the status stays `uploaded`.
- [x] Permissions, CSRF and responses are unchanged against base on 8.3 and 8.4 for both drivers:

| Request | Status |
|---|---|
| Viewer upload, map, preview or commit | 403 |
| Missing or bad CSRF | 419 |
| Editor commit | 303 to the done page |
| Commit again | 303 |
| Preview or GET of a done import | 303 |
| Unknown import | 404 |

## HTTP race matrix
`php -S` with PHP_CLI_SERVER_WORKERS=4, 30 rounds per scenario, 40-row file. Each cell: rounds imported exactly once / other outcome.

| Scenario | base SQLite 8.3 / 8.4 | base PG 8.3 / 8.4 | head, all 4 legs |
|---|---|---|---|
| 2 commits | 30/0, 30/0 (9 and 5 × 500) | 13/30 and 13/30 twice | 30/30 once, commits all 303 |
| 3 commits | 28/30, 28/30 (2 twice; 11 and 8 × 500) | 5/30 and 8/30 (rest twice) | 30/30 once, commits all 303 |
| commit + preview | 26/30 (4 none, 500s) | 30/30 | 30/30 once |
| preview + commit | 26–27/30 (none, 500s) | 30/30 | 30/30 once |

- **Head:** no partial rows, no 500s, no lock errors, and every commit answered 303 to the done page.
- **Previews at the head** answer 200, 303 or 410. 410 is N-373, accepted. One preview answered 422 (N-391).
- A 5000-row file (the maximum) imports in under 1 s, well inside SQLite's 5 s busy_timeout.

## Mutants
11 mutants on 8.3 SQLite and 8.3 PG, plus a 10-round race probe for each.
- **Killed by the PR tests on both DBs:** lock removed, condition inverted, rowCount check dropped, file read before the lock, preview without the lock, `done_before` ignored, rollback missing on the done path, unconditional first write. The race probe confirms all of them except the missing-rollback mutant.
- **Survives on both DBs:** done mark written after COMMIT but still inside `import_run` (N-392). The author's variant, which moves the mark back to `import_commit`, is killed on 8.3 and 8.4.
- **Equivalent:** the done path not clearing the queue, and a preview writing `done` (that write is rolled back anyway).
- **Score:** 16 of 18 non-equivalent legs killed.

## Findings
- **N-390 (N):** the route still reads the file and saves the mapping before the run takes its lock. A commit racing a just-finished commit could therefore still answer 410 or 422 instead of 303, and it re-saves the stored mapping of an import that is already done. It imports nothing either way. This was seen on base but not in 480 commit answers at the head. Hardening: when the file is gone, re-check for done and redirect, or move the mapping save into the locked run.
- **N-391 (N, same family as N-373):** a preview racing a commit answered 422 once (1 of about 360 previews at the head), when the file disappeared between the existence check and the read. Hardening: treat a failed read the same as a missing file (410), or answer a preview of a done import with the done redirect.
- **N-392 (N, test gap):** a done mark written just after COMMIT inside `import_run` passes the suite. The new test only checks visibility when the queued check drains, which is after both writes. Hardening: check from a second connection between COMMIT and the drain, or assert the done mark is part of the run's own transaction.

## Security
- Concurrent commits import a file exactly once on both drivers. A run that finds the import done imports nothing and goes to the done page.
- The done mark commits with the rows.
- The lock relies only on matched-row counts, which both drivers report.
- Permissions, CSRF and status codes are unchanged. No schema change, no new state, no secrets.

## Verdict
**PASS** (0 H / 0 M / 0 L / 3 N). N-390 to N-392 are optional follow-ups.
