# QA: apotheke PR #64, CI4 settings core, branding routes and settings CLI (plan PR 6)

- **PR:** [#64](https://github.com/thordin9/apotheke/pull/64), "feat(ci4): settings core, branding routes and settings CLI (plan PR 6)", branch `ci4/settings-core` into `feature/ci4`, by Coder2. Draft.
- **Base:** `feature/ci4` at `a5cdc2f` (#65's merge). The PR is 0 behind it after its merge commit.
- **Head tested:** `8b0b4772e1aa93c23320a15f01ca018cbdc27097`. That's 17 commits from `b6b713e`:
  - plan PR 6 (`3dd9895` … `ff26844`);
  - merge `a5dd80b` of `f0956fa`;
  - `47751a3`, `6f4be87`, `5f80788`, then the low fixes `c7f4c1c` (hard assert, wiring doc) and `1fe31c9` (CHANGELOG links);
  - `8b0b477`, a plain merge of `origin/feature/ci4` (`a5cdc2f`).
- **Head history:** the PR was at `5f80788` when the review was requested. `1fe31c9` and then `8b0b477` landed before I started. I reviewed `8b0b477` in full and the `5f80788..8b0b477` delta separately (2.1). No force-push.
- **Changes vs `feature/ci4`:** 42 files, +3663 / −278.
  - The Settings module: `SettingsSchema`, `SettingsRepository`, `SettingsService`, `BrandStore` / `LocalBrandStore`, `Branding`, `BrandingController`, `SettingsCli` / `SettingsCommand`.
  - `Migrations/BootMigration` and `Config/Services`.
  - Core filters and `WiredCommands`.
  - `bin/settings.php`, `deptrac.yaml`, the CI4 module tests, root goldens, `CHANGELOG.md` and docs.
- **CI:** run 40065131618 at `8b0b477`, **22/22 `success`** (08:09–08:22 Athens time, UTC+3). A run at `1fe31c9` was still in progress at 08:07 and was superseded by `8b0b477`.
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only.
  - Sources: codeload tarballs of `5f80788`, `8b0b477`, `a5cdc2f` and #66's `168800f`, the GitHub API (PR, commits, check runs, job logs) and `main`'s `scripts/release.php`.
  - Local runs only in scratch copies against a throwaway PostgreSQL 17 with scram-sha-256.
  - No comment, review, push or merge was made.
- **Verdict: PASS.** There are no High or Medium findings.
  - The Cloudflare flags are handled: `BrandStore` interface, no static `settings_cache`, and BootMigration migrate-once keyed on the DSN and marked only after success.
  - The four deviations match the list. There are 63 keys, identical to legacy.
  - The hard assert, the comment fix and the links are in.
  - One Low (L-1): no test pins that a different `DB_SCHEMA` (or user) on the same DSN is migrated on its own.

## 1. Test plan

### 1.1 Environment

PHP **8.4.27** CLI (pdo_pgsql, pgsql), PHPUnit and CI4 vendor from the locked `composer.lock` / `ci4/composer.lock`, PostgreSQL **17.11** with `scram-sha-256`, and gitleaks **8.30.1**.

### 1.2 Acceptance checks

1. **Delta `5f80788..8b0b477`.** The `Ci4SettingsSchemaTest` hard assert (no skip at the old :112–113), the `FrameworkWiring` comment, CHANGELOG links, and the merge resolution.
2. **Keys.** `SettingsSchema::definitions()` against legacy `settings_schema()`: count, names and every row, including `autofill_max_image_bytes`. Check that the PR body matches.
3. **Cloudflare flags.**
   - `BrandStore` behind an interface.
   - No static `settings_cache`.
   - `BootMigration` keyed on the DSN and marked only after success, with mutations.
   - Per-request state under CI4's `WorkerMode`.
   - DSN-driven DB config.
4. **Deviations.** Run `bin/settings.php` on legacy and `APOTHEKE_APP=ci4` side by side. Check that secrets are write-only, an unknown key exits 1, the import of a missing file drops the PHP warning, and `list` is read-only.
5. **Suites.** Full PHPUnit (ci4 on pgsql and sqlite), the CI4 module suite, and the tree that `feature/ci4` will have after #66 and then #64 merge.
6. **CHANGELOG and links.** Every #64 bullet linked, and `main`'s `check-links`.
7. **Secrets.** gitleaks and a manual grep of the added lines.

## 2. Results

### 2.1 Delta `5f80788..8b0b477` (item 1)

- **`c7f4c1c`, `Ci4SettingsSchemaTest`.** `testAutofillMaxImageBytesWhenTheKeyExists` is now `testAutofillMaxImageBytes`. The `if (!$schema->has(…)) markTestSkipped(…)` at the old lines 112–113 is gone, replaced by `assertTrue($schema->has('autofill_max_image_bytes'), 'migration 0026 key is missing from the schema copy')`. With the key removed from `SettingsSchema` (mutation), the test **fails** with that message, along with three golden tests. No skip.
- **`c7f4c1c`, `FrameworkWiring`.** The comment no longer says "Idempotent". It now says each call overwrites the default group, so `Services::apothekeDb` calls it only under spark (`PHP_SAPI === 'cli'`). That matches the code: `array_merge` into `config(Database::class)->default`, and the `PHP_SAPI` check in `Services::apothekeDb`.
- **`1fe31c9`, CHANGELOG.** The 5 #64 bullets link #64 (2.6).
- **`8b0b477`, merge of `a5cdc2f`.**
  - It brings in #65's 7 commits. `Ci4HttpTest.php` keeps `feature/ci4`'s version, byte-identical to `a5cdc2f`.
  - In `CHANGELOG.md`, #65's linked bullets and the 8.3.36 / 8.4.27 wording come in.
  - No other file differs from the expected side.

**PASS**

### 2.2 Keys (item 2)

| Check | Result |
|---|---|
| `SettingsSchema::definitions()` | **63** keys |
| legacy `settings_schema()` (`src/settings.php` at the head) | **63** keys |
| keys only on one side | none |
| rows that differ (group, label, type, default, help, options) | **0** |
| `autofill_max_image_bytes` | `['autofill', 'Maximum image size (bytes)', 'af_bytes', 'auto', …]`, live, no skip |

- **CLI behaviour of the 63rd key.**
  - `get` gives `"auto"`.
  - `set … AUTO` gives `autofill_max_image_bytes = "auto"`.
  - `set … 1000` exits 1 with "auto, or a whole number of bytes from 65536 to 10485760." That's byte-identical on legacy and CI4.
  - The mis-cased `get Autofill_max_image_bytes` is an unknown key (deviation 2).
- **PR body.** It says 63 keys, with `autofill_max_image_bytes` live as `af_bytes` and a hard assert. That matches.

**PASS**

### 2.3 Cloudflare flags (item 3)

- **`BrandStore` behind an interface.**
  - `Apotheke\Settings\BrandStore` covers `has`, `read`, `size`, `modifiedAt`, `put` and `clear`, plus the legacy `FILES` allowlist.
  - `LocalBrandStore` (`DATA_DIR/brand`) is the only implementation, built in `Config\Services::brandStore()`.
  - Nothing else in the Settings module touches the filesystem: the grep for `file_put_contents`, `fopen`, `unlink`, `is_file`, `scandir` and `dataDir` outside `LocalBrandStore` is empty.
- **No static `settings_cache`.**
  - `settings_cache()` in `LegacyFunctions.php` is now stateless (`return $clear ? null : $set;`).
  - `SettingsService` is a shared service from `Config\Services::settings()`.
  - A grep for `static $`, typed static properties, `$GLOBALS` and `global` in `ci4/modules/Settings`, `ci4/modules/Core` and `Config/Services.php` finds nothing.
  - `Config\WorkerMode::$persistentServices` (autoloader, locator, exceptions, commands, codeigniter, superglobals, routes, cache) doesn't include `settings`, `apothekeDb`, `apothekeConfig` or `brandStore`, so CI4 resets them per worker request.
  - The persistent `commands` service holds only a closure that calls `static::settings()` when it runs.
- **`BootMigration`.**
  - The memo `private static array $done` is keyed on `sha256(driver \0 pdoDsn \0 schema \0 user)`. The password isn't in the key, and the key is never logged.
  - `ensure()` connects, runs `migrate()` in `try/finally` (which closes the PDO) and sets `$done[$key]` only after both return.
  - A connect or migrate exception leaves the memo unset, so the next request retries.
  - `forget()` exists for tests and a future restore.
  - Mutations (CI4 module suite, PostgreSQL, 46 tests):

| # | Mutation | Result |
|---|---|---|
| B1 | mark the memo before connect | **killed**: `ApothekeDbServiceTest::testAFailedFirstConnectStillMigratesOnTheNextRequest`, `BootMigrationTest::testAFailedMigrationIsRetried` |
| B2 | constant key (not keyed on the DSN) | **killed**: `BootMigrationTest::testMigratesOncePerDatabase` |
| B2b | key without `DB_SCHEMA` | **survives**: 46 tests OK (L-1) |

- **DB config DSN-driven.** `BootMigration` and `apothekeDb` both use `ApothekeConfig::database()` through `DsnTranslator` (`pdoDsn()` for the key, `translate()` for the CI4 group). Nothing reads host or credentials elsewhere.

**PASS**, with L-1.

### 2.4 Deviations (item 4)

Fresh SQLite data dirs, `php bin/settings.php …`, legacy vs `APOTHEKE_APP=ci4`:

| Form | Legacy (exit, stderr) | CI4 (exit, stderr) | Matches the deviation list |
|---|---|---|---|
| `get autofill_token` | 0, prints the stored value | **1**, `“autofill_token” is write-only.` | 1 ✓ |
| `get password_hash` | 0, prints it | **1**, `“password_hash” is write-only.` | 1 ✓ |
| `get nosuchkey` | **255**, `PHP Fatal error: Uncaught LogicException…` | **1**, `Unknown setting “nosuchkey”.` | 2 ✓ |
| `import /missing.json` | 1, a `PHP Warning: file_get_contents(…)` line, then the message | **1**, `That is not an Apotheke settings file.` only | 3 ✓ |
| `set autofill_token x` | 1, "Unknown setting … (use `user` / `auth` …)" | identical | unchanged ✓ |
| `frob` | 2, "Unknown command…" | identical | unchanged ✓ |

- **Deviation 4 (`list` read-only).** It's pinned by `Ci4SettingsCliTest::testIntentionalDeviationsFromLegacy`. With sign-in on, accounts deleted and `APP_PASSWORD` set, legacy `list` shows `users 1` and creates the `admin` row, and CI4 shows `users 0` and creates no row. Everything else in the output is identical.
- **Fresh database.** On a fresh database with `APP_PASSWORD`, both sides create `admin` during migration (users 1 on both), so the deviation is limited to the documented case. The Auth PR must remove it (PR body, "Required in the Auth PR").
- **CHANGELOG.** The four numbered deviations are under `[Unreleased]`, with the security upgrade note for the token.

**PASS**

### 2.5 Suites (item 5)

All runs used `CI=true` and PostgreSQL 17 scram.

| Tree | Leg | Result |
|---|---|---|
| `8b0b477` | ci4 @ 8.4.27, PostgreSQL | **OK**, 458 tests, 11751 assertions, 2 skipped (php-cgi not installed; `HttpSuiteTest`) |
| `8b0b477` | ci4 @ 8.4.27, SQLite | **OK**, 458 tests, 11738 assertions, 5 skipped |
| `8b0b477` | CI4 module suite (`ci4/phpunit.xml.dist`), PostgreSQL | **OK**, 46 tests, 429 assertions |
| `8b0b477` | `Ci4SettingsSchemaTest` | **OK**, 6 tests, 5854 assertions |
| `feature/ci4` + #66 (`168800f`) + #64 (`8b0b477`), merged locally | ci4 @ 8.4.27, PostgreSQL | **OK**, 474 tests, 12268 assertions, 2 skipped |
| CI `8b0b477` | ci4 @ 8.4, pgsql (job 120786766462) | `OK … Tests: 458, Assertions: 11762, Skipped: 1` |

- **Merge order.** After #66 merges, #64 merges into it with **no conflicts** (local three-way merge), and the combined tree is green. So the planned order (#66, then #64) needs no further resolution commit.
- **Links.** `node scripts/check-links.mjs` at `8b0b477` checks 233 links in 60 files and finds 0 broken.

### 2.6 CHANGELOG and links (item 6)

- **#64's bullets.** The 5 `- ` bullets are the security upgrade note, the Settings module, two Fixed and one test. All link #64.
- **Deviations section.** The `### Intentional deviations` section is a numbered list. `main`'s `check-links` only checks `- ` lines, so these pass without a link (N-2).
- **`main`'s `release.php check-links`.** It still finds the 12 older unlinked ci4 bullets at `8b0b477` (11 after #66). That's #65's L-1, which DevOps owns.

**PASS**

### 2.7 Secrets (item 7)

- **gitleaks 8.30.1** with the repo's `.gitleaks.toml`, run on the 3663 added lines: **no leaks**.
- **Manual grep.** URLs are `github.com/thordin9/apotheke/pull/…`, documentation-range or private test IPs in validation tests, and placeholders. There are no tokens, keys, hostnames or account IDs. Test secrets are the literals `placeholder-token` and `placeholder-password`.

**PASS**

### 2.8 Other Cloudflare-phase notes

The PR body's own list is accurate:

- `LegacyFunctions` statics are migration-only.
- File sessions go to the Auth PR.
- `StoragePreflight` and the storage interface go to the Media PR.
- Hyperdrive `postgres://` is deferred.
- `ApothekeConfig::fromEnvironment()` runs about four times per request.
- Logging to files instead of stderr comes later.

Under FrankenPHP worker mode, BootMigration's memo deliberately persists, so migrations run once per worker and database. That's fine, because a deploy restarts workers. A restore must call `BootMigration::forget()`, which is listed as a Backup PR follow-up (I-2).

### 2.9 CI

- **Run 40065131618** (`8b0b477`, `pull_request`): **22/22 `success`**, 08:09–08:22 Athens time (UTC+3).
  - Lint, mobile checks, Docker build + smoke test, and Visual E2E shards 1–3: each on PHP 8.3 and 8.4.
  - Tests: legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql.
  - deptrac, DI rule, Conventional Commits and Secret scan.
- **Commit subjects.** The 15 non-merge subjects are 37–69 characters. The merges (`a5dd80b`, `8b0b477`) are skipped by `check-commits.sh`.

## 3. Findings

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| L-1 | Low | `BootMigration`'s key includes `DB_SCHEMA` and the user, and the PR body says "another database or schema in the same process is checked on its own". No test pins the schema or user part: dropping `schema` from the key (B2b) leaves all 46 module tests green. `testMigratesOncePerDatabase` only varies the SQLite path. In a worker serving two schemas on one DSN, the second schema would silently skip migration. | Add a `BootMigrationTest` case on PostgreSQL (or SQLite with two configs that share the DSN) with the same DSN and two `DB_SCHEMA` values, expecting `ensure()` to return true for each. Optionally do the same for the user. |
| N-1 | Nit | The PR body's local test table is stale ("424 tests"). CI now runs 458, and the trust-auth local failure note is from before #65. | Refresh the numbers, or point to the CI run. |
| N-2 | Nit | The four numbered items under `### Intentional deviations` carry no PR link. `check-links` only checks `- ` bullets, so they pass, but they're the only #64 lines without one. | Optional: add `([#64](…/pull/64))` to the section's lead line. |
| I-1 | Info | The head moved from `5f80788` to `1fe31c9` and then `8b0b477` before the review. I reviewed `8b0b477` in full and the delta separately (2.1). The merge resolution is correct, and #64 merges cleanly after #66. | None. |
| I-2 | Info | Cloudflare phase: `BootMigration::$done` is process state on purpose (once per worker and database under FrankenPHP). A restore in a live worker must call `forget()`, which is already a Backup PR follow-up. | Keep it on the Backup PR checklist. |

No High or Medium findings.

## 4. Verdict

**PASS at `8b0b4772e1aa93c23320a15f01ca018cbdc27097`.**

- **Delta:** `Ci4SettingsSchemaTest` hard-asserts the 0026 key and fails, rather than skips, without it. The `FrameworkWiring` comment matches the code, and the #64 bullets are linked.
- **Keys:** 63, identical to legacy row for row, and the PR body matches.
- **Cloudflare flags:**
  - `BrandStore` is an interface.
  - There's no static `settings_cache`, and the settings service is per request.
  - `BootMigration` is keyed on the DSN and marked only after connect and migrate succeed, which mutations confirm.
- **Deviations:** the four match the signed-off list exactly.
- **Tests and CI:** suites are green locally and in CI (22/22), the post-#66 merge is clean and green, and gitleaks is clean.
- **Open items:** L-1 (a schema test for the migrate-once key) and nits N-1 and N-2, as follow-ups.
