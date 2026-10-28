# QA: PR #145, legacy CLI restore runs as the data-dir owner (L-610)

Full gate. Verified head: ceaac4144157da3122b36bcd2f8ff3914f9677de (as expected, not moved). Base main a69c852, 0 commits behind, merges cleanly. CI: 10/10 green. Wed 28 Oct 2026, 16:53 to 17:55 EET.

## Verdict

**Approve.**
- `bin/restore.php` started as root switches to the data-dir owner before bootstrap, and the switch cannot be undone.
- Every refusal exits 1 with nothing changed.
- L-610 is closed for the map write: no ownership or mode fix-up by path is left.
- L-590's goal holds: every restored file, folder and map entry belongs to the data-dir owner.
- Notes only: N-621, L-622 (batch), N-623, N-625.

## Checklist

- [x] **Switch order (`cli_run_as_data_owner`).** setgid, initgroups, setuid, then the ids are checked. Afterwards uid, euid, saved and fs ids are the owner's, capabilities are empty, and getting root back fails. There is no DB, lock or file work before the switch (syscall trace).
- [x] **Refusals, exit 1, nothing changed** (rows, file listing and modes are the same, no lock, no root-owned file, a missing data dir is not created):
  - data dir owned by root;
  - data dir missing;
  - owner with no account entry;
  - posix functions missing;
  - the switch itself fails.
  - --dry-run refuses the same way.
- [x] **--dry-run as root.** It switches first; nothing is written.
- [x] **sudo restore** on SQLite and PostgreSQL 17 (own DB and role): exit 0, everything under the data dir belongs to the owner, the map entry is 0640, no temp file is left.
- [x] **Container restore** (image built from this head, root exec): posix is present; restore and --dry-run exit 0; nothing under /data is root's. With /data owned by root, both exit 1 and nothing changes.
- [x] **Map temp file** is created with its mode set at creation (umask); chown, chgrp and chmod are removed.
- [x] **Sudo tests really run.** Locally: `testARootCliRestoreRunsAsTheDataFolderOwner`, `testARootCliRestoreRefusesARootOwnedDataFolder` and `testTheOwnerCheckLetsOtherUsersThrough` pass on SQLite (3 tests, 49 assertions) and PostgreSQL (3 tests, 46 assertions). On CI a missing sudo makes them fail, not skip, so green CI means they ran.
- [x] **Suites.** PHPUnit: 545 tests, 0 failures, on PHP 8.3 and 8.4 × SQLite and PostgreSQL (skips are environment-only, none are root tests). integration.php: 1765 passed on SQLite, 1436 passed on PostgreSQL, 0 failed. CI: 545 tests, 2 skips.
- [x] **Lint and commits.** `scripts/lint.sh` passes; 3 Conventional Commits.
- [x] **Docs** (deployment, architecture, getting started, AGENTS, CHANGELOG) say to run the CLI as the data-dir owner and describe the refusal.

## Camera file mode fix-up (Coder2's note)

**Acceptable, not a finding.** `backup_restore_link_originals` still sets the restored camera file's mode by path after a plain-file check. That now runs as the data-dir owner, so it can only change files the owner can already change. Nothing is gained over the owner's own rights. `originals/` is 0750, so the only account that can write there is the same one. A descriptor-based change would be tidier, but it is optional.

## Findings

| ID | Sev | Status | Finding |
|---|---|---|---|
| N-621 | N | New | If the data dir's group is root's group, the process keeps that group. Consider refusing it, or using the owner's primary group. |
| L-622 | L | Pre-existing, batch | The other CLI entry points (for example the documented container `backup.php`) still run as root and leave root-owned files, such as the backup zip. Extend the owner switch to all CLI entry points. |
| N-623 | N | Pre-existing | The config file is read before the switch. Document that it must not be writable by the web user. |
| N-625 | N | New | If neither posix nor /proc can tell the user, `cli_effective_uid()` treats it as not root. CI4 treats that case as root and refuses. Align with CI4 so both fail safe. |

## Security

- **L-610 (restore map write):** closed. The process runs as the data-dir owner and the temp file gets its mode at creation.
- **L-622, N-623, N-625:** hardening for the batch; none blocks this PR.
- When merged forward, this also covers the legacy app on feature/ci4 (N-624 in the #146 review).
- No secrets, tokens or personal data in the diff.
