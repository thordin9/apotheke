# QA: PR #160 – CI4 seed owner message and N-900

- **Head:** `6f9b0c636a3418d07295dafef6c47e46cec16ffd`, including `feature/ci4` `65d7e21` (#159). 0 behind, not cross-repo, 4 commits, CI 25/25.
- **Date:** 2026-10-29 (EET)
- **Scope (delta only):** `DataDirOwner::COMMANDS` (`apotheke:seed`); the differing-credentials notice in `Config\Services::apothekeDb()` and `PdoMigrationRunner::connect()`; the tests that pin both; the #159 sync merge.

## Verdict

**PASS (APPROVE).** No H, M or L findings. Two N notes.

## Checklist

- [x] **Merge `6f9b0c6`.** The conflicts were only in CHANGELOG and HANDOFF.
  - `--remerge-diff`: both sides' entries are kept, and the #159 lane is marked merged at `65d7e21`.
  - `Services.php` and `PdoMigrationRunner.php` (changed on both sides) merged cleanly. #159's connect timeout and wiring stay intact, and #160's notice now follows the connect in both files.
  - Diff-of-diffs (`57d926d..8da01c1` against `65d7e21..6f9b0c6`, excluding CHANGELOG, HANDOFF and docs/qa) is empty.
- [x] **Seed owner check.** `'apotheke:seed' => 'php bin/seed.php'` is matched by name before boot. `BackupHelpersTest` pins the entry and `example()`. `Ci4BackupRestoreChecksTest` runs `apotheke:seed --demo` as root on a root-owned folder and expects exit 1 with the `bin/seed.php` message.
- [x] **Seed by hand, as root, in scratch.**
  - Owner-owned data folder: `spark apotheke:seed --demo` switches, seeds 8 demo items with thumbnails and originals, and exits 0. No file is owned by root or root's group, including `thumbs/` and `originals/`, in the data folder or the checkout.
  - Root-group folder: runs with the owner's own group.
  - Root-owned folder: exit 1 with the `bin/seed.php` message, and nothing is written.
  - `bin/seed.php` on CI4 behaves the same in both cases.
- [x] **Refused PostgreSQL (closed local port).** `bin/backup.php`, `bin/restore.php`, `bin/restore.php --dry-run` and `bin/migrate.php` give byte-identical stdout, stderr and exit code (1) on legacy and on CI4. This holds with and without credentials embedded in `DB_DSN`.
- [x] **Failed login on a live server.** Wrong password, with `DB_DSN` credentials: byte-identical to legacy. No notice and no secret appear.
- [x] **Successful connection, `DB_DSN` credentials differing.** CI4 logs the notice once and never the values. Canary user and password strings were absent from stdout, stderr, the CI4 log and the data folder in every run.
- [x] **Tests.**
  - CI4 module suite: SQLite 621 OK (3 PG-only skips); PG 770 OK.
  - Named root tests on CI4:
    - `DbConnectErrorTest`, `Ci4BackupRestoreChecksTest` and `Ci4DsnCredentialsTest` on SQLite: OK (PG-only cases skipped).
    - On PG: OK. `Ci4DsnCredentialsTest` needs a role that can create roles. I gave my own test role that right with self-grant only; no roles were left behind.
  - The new `DbConnectErrorTest` case builds its login and password at run time.
- [x] **Mutations: 6 of 6 killed.**
  1. Seed entry removed from `COMMANDS`.
  2. Notice moved back before the connect in `apothekeDb()`.
  3. Notice moved back before the connect in `connect()`.
  4. Notice dropped on success in `apothekeDb()`.
  5. Notice dropped on success in `connect()`.
  6. Notice dropped on success in both.
- [x] **Secrets.** `.gitleaks.toml` is unchanged. Pinned gitleaks 8.30.1 over the PR range and over `--all`: no leaks.
- [x] **N-901** is untouched, as stated. No Cloudflare-phase impact.

## Security

- **Privilege switch.** The seed command now refuses or switches before boot with its own tool's name, like the other data commands. No root-owned files were created in any run.
- **Connect-error output.** A failed or refused connection prints only legacy's allowlisted line. The notice about embedded credentials appears only after a successful connection, never with values.

## Findings

### N-940: no credentials notice after a failed login (accepted trade-off)
With differing `DB_DSN` credentials, a failed login now prints only the connect line, without the hint that `DB_USER` / `DB_PASSWORD` were used. An operator who meant the DSN credentials has to infer that from the docs.
- This keeps the failure output identical to legacy and minimal.
- The hint still appears on every successful connection.
- Accepted. Optionally, `docs/configuration.md` could mention it under `DB_DSN`.

### N-941: differing `DB_DSN` credentials on PHP 8.4 (pre-existing)
With credentials in `DB_DSN` that differ from `DB_USER` / `DB_PASSWORD`, CI4 connects as `DB_USER`, as legacy does on PHP 8.3. Legacy on PHP 8.4 uses the DSN's and fails here.
- This is documented existing behaviour, not changed by this PR.
- It is recorded because the good-connection comparison cannot be byte-identical in that setup.

## Notes

- I ran the delta-scoped suites only, as briefed.
- I used my own scratch folders, a closed port, my own databases and canary values. The root-owned test folders were removed by exact path.
