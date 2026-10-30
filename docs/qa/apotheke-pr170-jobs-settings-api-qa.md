# QA: apotheke PR #170 — jobs table, runner and settings read API

- **PR / head:** #170 `feat/api-jobs-settings-read` → `main`, same repo, CLEAN. Head `0cc4ecd64cf7ee9ad1eb76f80931ca8c429860f4`. 14 commits (merge of `main` v3.1.1 `6776b6d` included), 0 behind, CI 17/17 on the exact head. 42 files.
- **Scope:** full gate; interim read at `d2e4829` (L-1240..N-1246) plus delta. 2026-10-30 (EET).

## Checklist

| Check | Result |
|---|---|
| Main merge `64735f0` (remerge-diff) | Only CHANGELOG conflicted; resolved correctly (upgrade note under `[Unreleased]`, `[3.1.1]` intact) |
| Released CHANGELOG sections | Byte-identical to `main` from `[3.1.1]` on |
| Delta vs `d2e4829` | `JobQueue`, `JobRunner`, `JobContext`, `SettingsController`, `SettingsSchema`, migrations, `Backups`, `Services`, `Routes` unchanged; `JobsCli` help text + pcntl probe; `JobHandler` rules; rest is #169 from `main`. Race not re-run (queue unchanged; 6 workers × 400 jobs clean on both drivers at `d2e4829`) |
| Suites, SQLite / own PG 17 | Modules 839/839 both. Root 346: OK on both after giving my scratch cluster password auth (7 credential/connect-failure tests need a server that refuses wrong passwords; see N-1301) |
| Lint, deptrac, DI rule, `apotheke:routes --check` | All clean |
| PM 1: `testEveryCi4OnlyMigrationIsRegistered` | Bites: empty `UNCARRIED_MIGRATIONS`, empty `upg_ci4_only()` map, `jobs` not listed — all killed (SQLite + PG) |
| PM 1: `testNoCi4OnlyMigrationChangesATable26Reads` | Bites on an added column on `items` (killed). Blind to an index on a 2.6.x table (L-1300) |
| PM 2: `ci4-only.php` in `upgrade-rollback.sh` | Run here, SQLite and PG: 2 seeded `jobs` rows kept after CI4 backup, rollback to 2.6.26 (served), and both in-place restores; CI4 zip names 28 migrations, none CI4-only, no `jobs` table; all steps equal, all mutation checks caught, schemas/work dir removed |
| PM 3: AGENTS.md | "No shared schema change": 0001–0028 byte-identical (sha-pinned), CI4-only files may only add tables 2.6.x never reads, nothing added/altered/dropped on 2.6.x tables, registration and tests required, else backport rule unchanged. Keeps DB compatibility as the rule; no loosening beyond additive tables (N-1303) |
| PM 4: upgrade note | CHANGELOG `[Unreleased]` note and `docs/upgrade-3.0.md` (jobs table stays after rollback, harmless; backups do not carry it). Accurate against the runs above |
| Migration 0029 up / re-up | SQLite + PG: applies fresh, re-run is a no-op, drop table + row → `--status` PENDING → re-applies. Columns, NOT NULL, defaults, CHECK on status and `idx_jobs_due` equivalent (PG `BIGINT` identity / SQLite `INTEGER` autoincrement). No down step by design: rollback leaves the table |
| L-1240 | Closed: all 10 sections' keys pinned; an unpinned key added to `data` is killed |
| L-1241 | Closed: `pcntl` in `docker-php-ext-install` plus build-time `php -m` check, CI docker step, `DockerfileTest` (dropping either is killed), `Ci4JobsWorkTest` for both paths. **Image built here** (podman, own storage): `pcntl` present; SIGTERM mid-job under tini as `www-data` → checkpoint saved, job back to queued with its attempt returned, lease cleared, exit 0; next worker finished it |
| N-1242 | Closed: another token keeps the ETag (ETag-over-token mutation killed) |
| N-1243 | Closed: forged-owner and stale-attempt test; dropping either guard is killed |
| N-1244 / N-1245 / N-1246 | Closed: exit codes in `--help` and the ops doc; handler rules in `JobHandler`; Cloudflare note (Cron Trigger / Queue, same schema) |
| N-1200 | Closed: HANDOFF #162 "Merged at f576b00", #168 "Merged at 9f90b9d" |
| gitleaks 8.30.1 | `main..head` clean. New allowlist is commit + path + exact value (AND); the value equals the sha256 of the 2.6.26 `0002` migration file (public, not a credential). Tree scan without the entry: clean; only historical commit `1b5f5c8` needs it. Existing entries unchanged |

## Findings

### H
None.

### M
None.

### L

- **L-1300 — schema-shape test covers columns only.** `testNoCi4OnlyMigrationChangesATable26Reads` compares tables and columns (name, type, null, default, order) of the 2.6.26 tables. An index, a UNIQUE constraint or a trigger that a CI4-only migration adds to a 2.6.x table is not compared. Mutation: `CREATE UNIQUE INDEX … ON items(name)` in 0029 passed both new tests on SQLite and PG. Such a change could make 2.6.x writes fail after a rollback, which is what the rule forbids. 0029 itself only creates `jobs` and its index, so today's behaviour is compatible. Fix: add the indexes, constraints and triggers on 2.6.26 tables to the shape (`sqlite_master` index/trigger rows by `tbl_name`; `pg_indexes`, `pg_constraint`, `pg_trigger`).

### N

- **N-1301 — credential tests need password auth.** Seven root tests (`Ci4DsnCredentialsTest`, `Ci4ConnectFailureLeakTest`, one `Ci4BranchCiTest`) fail against a trust-auth server ("connected without a password"). CI uses password auth, so this is local-environment only; a short note in `docs/testing.md` or a clear skip would save time.
- **N-1302 — orphaned docblock.** In `DockerfileTest` the new `testImageBuildsAndChecksPcntl` was inserted under the existing comment of `testImageCarriesTheOpenApiDocument`, which now sits above the new docblock instead of its own test. Cosmetic.
- **N-1303 — project rule change for sign-off.** AGENTS.md now allows CI4-only migrations that only add tables. The wording keeps DB compatibility and rollback to 2.6.x as the rule and adds enforcement, and the runs above show this PR stays compatible. Whether a new table is acceptable under Stathis's DB-compatibility decision is a PM/Stathis call; PM sign-off is recorded as pending.

## Security (neutral)

The settings read API is limited to the settings permission and returns only the pinned generic-form keys; write-only and internal values are never returned (the token only as a boolean) and the ETag does not reflect secret values. Job rows hold fixed or handler-authored error text. The worker stops cleanly in the shipped image. The new secret-scan exception is narrow and covers a public file hash in one historical commit.

## Verdict

**PASS** with L-1300 and N-1301–N-1303. No H/M; L-1300 is a follow-up to harden the PM's enforcement test.
