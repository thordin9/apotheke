# QA: PR #158 – CI4 DB-connect errors in backup and restore

- **Head:** `690af543dd4c38967a6af6f5c3b58f70b656dbfd` on `feature/ci4` `35cb0e6` (0 behind, not cross-repo, 5 commits; CI 25/25)
- **Date:** 2026-10-29 (EET)
- **Scope (delta only):**
  - `BackupCommand` and `RestoreCommand` connect-error handling;
  - `DbConnectErrorTest`;
  - `DataDirOwnerGroupsTest` (N-880);
  - the thumbnail cleanup-order test (N-881);
  - the `docs/architecture.md` note (N-883);
  - HANDOFF and CHANGELOG.

## Verdict

**PASS (APPROVE).** There are no H, M or L findings, and two N notes.

## Checklist

- [x] **Code.** Both commands wrap only the service build (where the database connects) in a `catch (DatabaseUnavailable)`. They write the exception's sanitized line to stderr and return 1. Nothing else changed in the production code.
- [x] **Refused PostgreSQL (closed local port).** `bin/backup.php`, `bin/restore.php --dry-run` and `bin/restore.php` give byte-identical stdout, stderr and exit code (1) on legacy and on `APOTHEKE_APP=ci4`. The same holds for:
  - an auth failure on a live server (unknown role and database);
  - a configured schema.
- [x] **SQLite file that cannot be opened.**
  - CI4: empty stdout, the single line `Could not connect to the database (sqlite – <path>).`, exit 1.
  - Legacy: an uncaught engine error with exit 255. This difference is deliberate and documented.
- [x] **No leak.** The line names only the driver, host, port, dbname, the schema (if set), or the SQLite path. That is legacy's allowlisted line. With canary user names and passwords, none appear in stdout, stderr or the CI4 log, including with credentials embedded in `DB_DSN`. No exception class, trace or "Server error" text is printed.
- [x] **Tests.**
  - `DbConnectErrorTest`: 12 OK, including the 4 new CI4 cases, none skipped.
  - CI4 module suite on PG: 769 OK, including `DataDirOwnerGroupsTest` (3) and `RestorerFatalCleanupTest`.
- [x] **Mutations: 7 of 7 killed.**
  - backup catch disabled;
  - restore returns 0;
  - exception class added to the line;
  - group check removed;
  - root-group test removed;
  - unreadable groups accepted;
  - `create()` noting its file after the write (N-881, which survived on #157).
- [x] **N-880 closed.** A child process replaces the posix calls in `DataDirOwner`'s namespace, without root. It covers three cases: the owner's groups only (switch allowed), root's group still present (refused), and groups that cannot be read (refused). The refusal message is pinned.
- [x] **N-881: closed in part, rest won't-hook (accepted).** The `create()` ordering is now pinned. The `.part` note-before-open ordering would need a test-only hook in production code, which is not worth it for an N-grade item. The code is correct as written.
- [x] **N-883 closed.** `docs/architecture.md` says spark started as root runs as the data folder's owner, so `apotheke:routes` must be run as the checkout's owner.
- [x] **Secrets.** `.gitleaks.toml` is unchanged. Pinned gitleaks 8.30.1 `--all`: 0 findings.
- [x] **Cloudflare phase.** Command-line only; no impact.

## Security

- **Connect failures.** A failed connect in the CI4 backup and restore commands now ends with the same sanitized one-line message as legacy and exit 1. Before, it ended with the framework's generic error output. This narrows what an operator's terminal or log captures.
- **Credentials.** They never appear in the message, including credentials embedded in the DSN.
- **SQLite.** The case prints only the configured file path, which legacy's uncaught error also shows (together with more detail).

## Findings

### N-900: an extra notice line when `DB_DSN` embeds credentials
When `DB_DSN` carries a user or password that differs from `DB_USER` / `DB_PASSWORD`, CI4 prints its existing notice ("… DB_USER and DB_PASSWORD are used …") before the connect line. Legacy on PHP 8.4 prints only the connect line, so the outputs are not byte-identical in that setup.
- The notice is pre-existing and contains no secret.
- With separate `DB_*` variables, the outputs are identical.
- Informational: decide whether the notice belongs only on `migrate` and the web log, or stays everywhere.

### N-901: the CI4 accounts form connects before checking its arguments
Outside this PR's scope. With an unreachable database, a malformed `bin/settings.php user …` form gives the connect line on CI4. Legacy gives the usage line. Both exit 1, and no data is exposed. Consider checking the arguments before connecting when the accounts form is next touched.

## Notes

- I ran the delta-scoped suites only, as briefed.
- All the comparisons used my own scratch data folder, a closed port and canary values. No real database was reached.
