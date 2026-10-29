# QA: PR #148, main QA notes batch 4 (CLI owner, links, warnings)

Full gate and merge read (round 1, head 61bed38), then a delta check (round 2). Verified head: 7f9dd72fab02600a69269cb678bd1b410aa10c75. CI: 10/10 green, 0 commits behind main, merges cleanly. A local merge followed by the release plan gives a patch bump to **2.6.26**. Round 2: Wed 28 Oct 2026, 21:41 to 21:48 EET.

## Verdict

**Approve.**
- L-680, N-681, N-682 and N-683 are fixed.
- N-684 goes to the next `main` follow-up.
- N-730 is a note only.
- Round 1 closed L-622, N-621, N-625, N-623, N-565, N-566, N-567, N-580, N-581, N-582, N-593 and N-563. N-563's kept-owner half is obsolete.

## Checklist (round 2, 61bed38..7f9dd72)

- [x] **L-680.** `bin/settings.php key generate` answers before it reads the configuration or switches users. Run as root against a fresh data dir, it prints a 32-byte key, touches nothing in the data dir and creates nothing root-owned. A syscall trace shows no file access in the data dir and no mode or owner change. Every other `settings.php` command now goes through the owner switch.
- [x] **N-681.**
  - With both group functions disabled, the tools refuse before any switch: exit 1, nothing changed.
  - Without `posix_initgroups`, the check after the switch refuses.
  - Without `posix_getgroups`, the groups are replaced and the tool runs as the owner.
- [x] **N-682.** A camera file a restore creates keeps the 0640 it was created with whatever the umask; only kept older files are changed afterwards. Dropping the creation mode now makes `testNewCameraFilesAre0640WhateverTheUmask` fail.
- [x] **N-683.**
  - The upgrade note leads the generated 2.6.26 notes and the CHANGELOG section.
  - It is accurate: root-run data tools refuse a root-owned data folder, and a backup needs a target the owner can write to.
  - The release plan still gives a patch bump to 2.6.26, and the link check passes.
- [x] **Mutations.** Each of these fails its test: moving `key generate` back after the switch, dropping the N-681 check, and dropping the N-682 creation mode.
- [x] **Tests.**
  - The four new tests pass, and none are skipped.
  - The filtered backup, settings and release suite: 103 tests on SQLite and on PostgreSQL 17, 0 failures. The two skips on SQLite are cross-database cases.
  - `integration.php`: 1765 passed, 0 failed; lint passes; 10 Conventional Commits.
- [x] **Diff read.** Besides `bin/settings.php`, `src/cli-owner.php`, `src/backup.php`, the tests and the CHANGELOG, only `.gitleaks.toml` changed (N-730). Nothing outside the scope changed.
- [x] **`.gitleaks.toml`.**
  - One `[[allowlists]]` entry is added, with a description, `condition = "AND"`, one `paths` entry for `tests/Unit/Ci4ControlPanelParityTest.php` and one anchored exact-value `regexes` entry.
  - It is identical to the file on the CI4 Control Panel branch, where that test's placeholder lives.
  - The file's sha256 is feb764d3aa1682921b339f896506abd9481cd977ddbcbfd25f5b31aebc89bc58.

## Round 1 summary (61bed38)

- Every data tool started as root runs as the data-folder owner before any work, or refuses with exit 1 and changes nothing. This was checked by syscall trace and on both databases.
- A data folder whose group is root's uses the owner's own group. An unknown user counts as root.
- A link at a new photo, thumbnail or attachment name stops the restore. A failed upload is removed, and a 409 keeps it.
- The restore prints its own warnings.
- PHPUnit: 550 tests on each database. `integration.php`: 1765 passed.
- The merge read against main is clean. The next `main` → feature/ci4 sync will need conflict resolution in `bin/`, the CHANGELOG and the deployment doc, because the branches have diverged.

## Findings

| ID | Sev | Status | Finding |
|---|---|---|---|
| L-680 | L | **Fixed** (25420e7) | `key generate` no longer loads the bootstrap. |
| N-681 | N | **Fixed** (25420e7) | Without either group function the switch refuses. |
| N-682 | N | **Fixed** (25420e7) | A test now covers the creation mode. |
| N-683 | N | **Fixed** (c442d5e) | The upgrade note leads the 2.6.26 notes. |
| N-684 | N | Next `main` follow-up | The backup zip's mode follows the umask on `main`; CI4 writes it owner-only (N-446). |
| N-730 | N | Note | The release PR also carries the secret-scan allowlist entry for a CI4 branch's test placeholder. It is narrow (path and exact value), so it is acceptable. |

## Security

- Every data CLI tool on `main` runs as the data-folder owner before any work, or refuses and changes nothing.
- `key generate` touches no data.
- The owner switch fails closed when the groups cannot be set or checked.
- The allowlist entry is limited to one test file and one exact value.
- No secrets in the diff.
