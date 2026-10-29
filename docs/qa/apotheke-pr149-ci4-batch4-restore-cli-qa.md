# QA: PR #149, CI4 QA notes batch 4 part B (restore and CLI)

Full gate. Verified head: 07925737e1b26a3401d28f332a245e94511ea85e. CI: 25/25 green, 0 commits behind feature/ci4, merges cleanly with #147. Wed 28 Oct 2026, 20:25 to 21:10 EET.

## Verdict

**Approve, with one Low for batch 5 (L-700).** The PR closes the following items, each checked on SQLite and PostgreSQL 17:
- L-622 (CI4 data commands)
- N-621
- N-520, N-521, N-581
- N-565
- N-567
- N-582
- N-592 and N-563
- N-593
- N-611

The deferrals hold (see below). There are three notes (N-701, N-702 and the sync note).

## Checklist

- [x] **Data commands as the owner (L-622, N-621).**
  - Started as root, `apotheke:restore`, `backup`, `migrate`, `settings` and `accounts` switch before boot.
  - With a data folder whose group is root's, the owner's own group is used.
  - Nothing new is root's, and the backup zip is 0600 and the owner's.
  - With a root-owned data dir, each command exits 1 with its own tool's message and nothing changes.
  - By code read, the new commands use the same exact-name match as the M-620 check.
- [x] **Lock (N-520, N-521, N-581).** The lock is made in place when hard links are unavailable. A lock name that is gone or replaced before the open is refused with the lock reason. The name is looked up again for each open.
- [x] **Temp copies (N-611).** After a fatal error during the unpack, no temp copy is left in `tmp/`, and the restored files are removed.
- [x] **N-565.** A link to a real file at a new photo, thumbnail or attachment name stops the restore. The link and its target are unchanged.
- [x] **N-582.** Camera files are created 0640. Only a kept file with another mode is changed, and only while it is a plain file.
- [x] **N-593, N-567.** The CLI prints the restore's own warnings. A failed confirm removes its upload; a 409 keeps it. Both apps behave the same (parity test).
- [x] **Mutations.** Each of these fails a test:
  - dropping a command from the list;
  - reverting the N-621 group choice;
  - reverting the N-521 reason;
  - ignoring the N-520 seam;
  - not noting a temp copy;
  - dropping the warnings;
  - keeping a failed upload.
  - Two N-611 parts survive (N-701).
- [x] **Sudo tests run.** The 8 root and spark tests pass on SQLite (174 assertions) and on PostgreSQL. None are skipped, and CI fails rather than skips without sudo.
- [x] **Suites.**
  - Module suite with PostgreSQL configured: 685 tests, 0 failures.
  - Root suite filtered to the backup, restore, parity and import tests, on CI4 and legacy × SQLite and PostgreSQL: 81 tests each, 0 failures. The skips are PostgreSQL-only cases on the SQLite legs.
- [x] **Lint and checks.** `scripts/lint.sh` passes; 9 Conventional Commits; deptrac reports 0 violations; the `di` check is OK.

## Deferrals

- **N-566 (closed by #145/#146): holds.** No owner fix-up is left, and every CI4 restore and data command runs as the owner.
- **N-580 and N-623 (to CI4 at the next `main` sync): hold.** The sync will conflict in the deployment doc and in `bin/`. Keep both texts there, and name the spark commands in the N-623 note.
- **N-625 (not needed on CI4): holds.** Without posix, an unknown user is treated as root and refused.
- **N-563, kept-owner half obsolete: holds.** The owner fix-up was removed in #146.

## Findings

| ID | Sev | Status | Finding |
|---|---|---|---|
| L-700 | L | New, batch 5 | Spark commands outside the data-command list (the framework's own) still boot as root when started as root. Boot-time storage preparation then runs as root: folders in a fresh data dir and by-path mode fix-ups. They can also leave a root-owned log file under `writable/`. Fix: switch or refuse for every spark command started as root. |
| N-701 | N | New, batch 5 | Two N-611 parts have no killing test: the cleanup for a fatal error while the backup is read, and noting a restored file before it is written. |
| N-702 | N | New | If `posix_initgroups` or `posix_getgroups` is disabled, the switch stops with a PHP fatal error (exit 255) instead of the refusal message and exit 1. It fails closed and changes nothing. |

## Security

- Every CI4 data command started as root now runs as the data-dir owner before boot, and never keeps root's group.
- L-700 is the remaining hardening gap for other spark commands.
- No secrets in the diff.
