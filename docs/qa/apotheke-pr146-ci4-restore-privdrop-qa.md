# QA: PR #146, CI4 CLI restore runs as the data-dir owner (L-610)

Full gate (round 1, head ed977f4), then a delta check (round 2). Verified head: ce3d549d93a52ba1a87c8656e3e340964389ddef. CI: 25/25 green, 0 commits behind feature/ci4 (537684b). Round 2: Wed 28 Oct 2026, 18:17 to 18:22 EET.

## Verdict

**Approve.**
- M-620 is fixed: `spark apotheke:restore` now switches to the data-dir owner, or refuses, before CodeIgniter boots.
- N-626 is fixed.
- N-621, L-622 and N-623 go to batch 4.
- N-624 closes when #145 is merged forward into feature/ci4.
- Through both `bin/restore.php` and `spark`, the switch comes before any work and cannot be undone; every refusal exits 1 with nothing changed. L-610 is closed and L-590's ownership goal holds, on SQLite and on PostgreSQL 17.

## Checklist (round 2, ed977f4..ce3d549)

- [x] **M-620, spark as root** (`--dry-run` and `--yes`, SQLite and PostgreSQL):
  - exit 0;
  - nothing under a fresh or existing data dir is root's, including the folders and the `.htaccess` file that boot prepares;
  - a syscall trace shows setgid, setgroups and setuid before any write, mode change, owner change, folder creation or database connection.
- [x] **Refusals through spark**, exit 1 with legacy's message, rows, file listing and modes unchanged, no root-owned file: data dir owned by root, data dir missing (not created), owner with no account entry, posix missing, and the switch itself fails.
- [x] **Second line.** With the early switch disabled, `RestoreCommand` still refuses a root-owned data dir with exit 1 and changes nothing.
- [x] **Command-name spellings.** The early check matches the exact command name in any position, and the framework runs only exact command names. Option order, `--`, other case, abbreviations, trailing characters and an `=value` suffix either take the early switch or do not reach the restore command. With PHP's argument list turned off, neither the check nor the framework sees a command, so no restore runs. `help apotheke:restore` is harmless.
- [x] **Other spark commands** behave as before (unchanged root behaviour is L-622).
- [x] **New tests run.** `testASparkRestoreAsRootRunsAsTheDataDirOwnerBeforeBoot` and `testASparkRestoreAsRootRefusesARootOwnedDataDir` pass on SQLite and PostgreSQL. With the two earlier root tests: 4 tests and 104 assertions on each database, none skipped. On CI a missing sudo fails rather than skips.
- [x] **Mutation.** Disabling the early switch makes both new tests fail on both databases. Restored afterwards.
- [x] **Restore check suite** (`Ci4BackupRestoreChecksTest`): 22 tests, 0 failures, on both databases; the only skips are environment ones.
- [x] **Lint and commits.** `scripts/lint.sh` passes; 3 Conventional Commits.
- [x] **#142 doc.** Compared with the reviewed copy and the ed977f4 version:
  - every verdict line is unchanged;
  - the finding IDs are the same set (N-547, L-590, N-591, N-592, N-593, L-610, N-611);
  - L-610's status reads "Fixed in #146" (N-626);
  - the link and concurrency test methods are now plain pass verdicts that name the tests.
- [x] **Diff read.** Besides `ci4/spark`, the tests and the #142 doc, only CHANGELOG, HANDOFF and the deployment doc changed, and they describe the spark behaviour. Nothing outside the scope changed.

## Round 1 summary (ed977f4)

- **Switch order (`DataDirOwner::become`):** setgid, initgroups, setuid, then all four ids are checked. Afterwards no capabilities remain and getting root back fails.
- **`bin/restore.php`:** the refusals, `--dry-run`, a sudo restore and a container root-exec restore all pass on both databases. Restored files, folders and map entries belong to the data-dir owner.
- **Temp files** get their mode at creation, with no owner or mode change afterwards.
- **Suites:** CI4 and legacy, 852 tests on SQLite and on PostgreSQL; module suite, 536 tests; 0 real failures.
- **Camera file mode fix-up (Coder2's note):** acceptable, not a finding. It runs as the data-dir owner, so it can only change files that owner could already change.

## Findings

| ID | Sev | Status | Finding |
|---|---|---|---|
| M-620 | M | **Fixed** (b97bec5) | `spark apotheke:restore` started as root now switches or refuses before boot. |
| N-621 | N | Batch 4 | A data dir whose group is root's group keeps that group; consider refusing it. |
| L-622 | L | Batch 4 | Other CLI entry points still run as root. Extend the owner switch to them. |
| N-623 | N | Batch 4 | The config file is read before the switch. Document that it must not be writable by the web user. |
| N-624 | N | Closes on the main sync | The legacy app on feature/ci4 gets the switch when #145 is merged forward. |
| N-626 | N | **Fixed** (ce3d549) | The L-610 status in the #142 doc. |

No new findings in round 2.

## Security

- **L-610 and M-620:** closed. Every restore entry point started as root runs as the data-dir owner before any work, or refuses and changes nothing.
- **L-622, N-623:** hardening of the remaining root CLI entry points, for batch 4.
- No secrets, tokens or personal data in the diff.
