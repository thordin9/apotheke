# QA: PR #157 – CI4 L-700 follow-up (root spark commands)

- **Head:** `62ede2a62f40c1082760c766986aa4b389265555` on `feature/ci4` `9f69400` (0 behind, not cross-repo, 7 commits; CI run 40866419198, 25/25)
- **Date:** 2026-10-29 (EET)
- **Scope:** `ci4/spark`, `DataDirOwner`, the CI4 branches of `bin/backup.php`, `bin/migrate.php`, `bin/restore.php` and `bin/settings.php`, the bounded settings import, the N-701 tests, the run-time parity placeholder, the `.gitleaks.toml` comment, and docs.

## Verdict

**PASS (APPROVE).** No H, M or L findings. Four N notes.

## Checklist

- [x] **CI flake.** On attempt 1, the pgsql parity gate failed only on timing: one area was just over the +20 % p95 bar, with 0 differences in all 223 steps. Attempt 2 passed. `DataDirOwner` is loaded only by `ci4/spark` and the `bin/` tools, never by a web request. The only web change is the settings import action, which that area does not use. This is a timing flake, not a regression.
- [x] **L-700: spark commands.** I enumerated every spark command: the six `apotheke:*` commands plus the framework's cache, config, db, env, filter, key, lang, logs, make, migrate, optimize, phpini, routes and serve families. They all pass one check, `DataDirOwner::forCommandLine()`, before CodeIgniter boots. I ran it as root in scratch:
  - Owner-owned data folder: `list`, `apotheke:routes`, `cache:clear`, `migrate:status`, `apotheke:migrate --status`, `apotheke:settings list`, `key:generate` and `db:table` all ran as the owner, and every new file belongs to the owner.
  - Root-owned data folder, or a missing one: exit 1 with the message and the command's own name, and nothing is written.
  - Root-group data folder: runs with the owner's own group (N-621/N-780 behaviour, same rule as the data commands).
  - The message names only a command matching `[a-z0-9:_-]{1,64}`.
- [x] **N-625 and N-681.** Both `BackupFailureTest` CI4 skips are removed. The tests ran and passed on the CI4 SQLite and PG legs, and on the legacy legs.
- [x] **N-702 and N-781.** As root, I disabled `posix_initgroups`, `posix_getgroups`, `posix_getpwuid`, and all posix functions in turn. Each case refused with exit 1 and the message, before any write, with no fatal error. A `bin/` tool on CI4 does the same.
- [x] **N-625.** `bin/backup.php`, `bin/migrate.php`, `bin/settings.php` and `bin/restore.php` on CI4 check the user before starting spark. With a root-owned folder, each exits 1 and writes nothing. A user that cannot be told counts as root.
- [x] **N-701.** `RestorerFatalCleanupTest` covers the read-phase cleanup registration and noting a file before it is written. Removing either one fails the test.
- [x] **L-740.** `readImportFile()` reads at most cap + 1 bytes, with cap = 256 KiB. At cap − 1 and cap it reads the whole file, which is accepted. At cap + 1 it reads cap + 1 and the import refuses it as too large. A 4 × cap file is read only to cap + 1. A missing file reads as `''`.
- [x] **Run-time placeholder.** The parity test builds its made-up token at run time, so no literal value is left in the tree. `gitleaks dir` on the tree is clean even with the entry removed.
- [x] **N-720.** `.gitleaks.toml` changes only in the comment. The entry is unchanged: `condition = "AND"`, an anchored path, and an anchored exact value, with no widening. I ran gitleaks 8.30.1 (pinned, checksum verified) with `git --log-opts=--all`: 0 findings with the entry, and 1 finding at `58f4929` in that test file without it. The entry stays.
- [x] **Mutations.** 9 of 12 were killed:
  - spark guard limited to the data commands;
  - the posix function check removed;
  - the N-621 group fallback removed;
  - the `bin/backup.php` check removed;
  - "no posix" treated as not root;
  - the unbounded import read;
  - the read-phase cleanup removed;
  - a written file noted after its write;
  - the file discard removed from cleanup.

  Three survived: N-880 and N-881.
- [x] **Suites.**
  - Root filtered (backup failure, restore checks, Control Panel parity, page HTTP, CI4 CLI): CI4 SQLite 90 OK (3 PG-only skips); CI4 PG 90 OK; legacy SQLite 90 OK; legacy PG 90 OK.
  - Module suite on PG: 764 OK.
  - Parity gate on SQLite and PG: 223 steps, 0 differences.
- [x] **No regressions.** Restore, backup, migrate, settings and accounts are unchanged. The legacy branches of the `bin/` tools, `src/` and `public/` are unchanged.
- [x] **Cloudflare phase.** These are command-line tools only, with no change on the web path, so nothing blocks it.
- [x] **N-741 deferred.** Test-button rate limiting is not cheap; it stays deferred.

## Security

- **Before boot.** A root start of any CI4 command-line entry point either drops to the data folder's owner and group before the framework boots, or stops with exit 1 and changes nothing.
- **Root group.** It is never kept. A switch that leaves root's group in place is refused.
- **Missing posix functions.** A build without the posix functions the switch needs, or an effective user that cannot be determined, is treated as root and refused cleanly.
- **Settings import.** Memory use is bounded by the cap. The size check is unchanged.
- **Secret scanning.** The allowlist entry stays as narrow as before. No secret-like literal is added to the tree.

## Findings

### N-880: the supplementary-group check has no test
Removing the check that root's group is not among the process's groups after the switch, together with its `getgroups` failure branch, does not fail any test. The switch already uses `initgroups` for a non-root owner, so this is defence in depth. A unit seam for the group list would let a test pin it.

### N-881: two N-611 orderings have no test
These two orderings are not pinned by a test:
- noting a small entry before `create()` writes it;
- noting the restore `.part` temp copy before it is opened.

They are correct as written. The N-701 tests cover the two parts they claim.

### N-882: the web front controller run from the command line as root
Running `ci4/public/index.php` from the command line as root boots as root. This is the same as legacy `public/index.php`, and it is not a spark command, so it is outside L-700. Consider a command-line guard in a later hardening pass.

### N-883: `apotheke:routes` as root now runs as the data folder's owner
This is by design under L-700. A root-owned checkout then cannot write `RoutesCompiled.php`. Docker and CI never run spark as root. A sentence in the developer docs would help.

## Notes

The run-time placeholder keeps the historic value, built from parts, the same approach as the S-L5 keys. The local runs needed one repeat because the shared box ran out of disk space during the first pass; the results above come from clean runs.
