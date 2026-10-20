# QA: apotheke PR #38, Feature 12 PR 4, `feat(ci4): skeleton + Core` (into `feature/ci4`)

> **Status: FINAL.** Final head `be464283cf4ff8d594025528e704cf321bd75cbb`, CI run 39454238792 22/22. Every medium and
> low finding is closed (M-1, M-2 and L-1 in §7; M-3 and L-2 in §8). The open items are nits and info, tracked as follow-ups.

- **PR:** [#38](https://github.com/thordin9/apotheke/pull/38) (draft, into `feature/ci4`, mergeable `clean`)
- **Head:** first round `5c1c6d9f7041371d0db250cf00642fc6fe12721d`, final `be464283cf4ff8d594025528e704cf321bd75cbb`. Base: `feature/ci4` at `d94465fb578ca8f5af5c08badae5fc3fd59c8a55`.
- **Commits:**
  - `026073f` feat(ci4): add the skeleton and Core
  - `4e282b2` docs: describe the CI4 skeleton and CI legs
  - `5d2cc81` ci: cache both lockfiles and document the audit gate
  - `5c1c6d9` fix: quiet shellcheck SC2016 in ci4-leg.sh
- **CI:** run 39432652114 (first round). Delta round 1: head `5d829faca21b51d2b0eda73c6dbd4b2205448393`, run 39439097802 (§7).
  Delta round 2: head `be464283cf4ff8d594025528e704cf321bd75cbb`, run 39454238792 (§8).
- **Scope:** CodeIgniter 4.7.5 skeleton and Core, plus #31's L-1, N-1, N-2 and N-3. Issue #33 is excluded (it is #37 on `main`).
- **Date:** 2026-10-20 (Europe/Athens). No live services. PostgreSQL was a throwaway local container.

## Test plan

1. **#31 follow-ups.** Read L-1, N-1, N-2 and N-3 in [ci4-pr02-qa.md](ci4-pr02-qa.md). For each, prove closure with a
   mutant or a direct run of `scripts/ci4-leg.sh` / `scripts/ci4-arch.php`.
2. **Scope against PLAN row 4.** Check the per-commit file lists (`release.yml` and `Dockerfile` unchanged). Then
   review the skeleton:
   - **Pins and audit:** the 4.7.5 pin in `ci4/composer.json` and `ci4/composer.lock`, and `composer audit`.
   - **Config:** secrets, `baseURL`, the encryption key, CSRF and security defaults, environment and error display,
     and `writable/`.
   - **Code:** routes, filters, `DsnTranslator`, `TrustProxyReader`, `RequestScheme`, `StoragePreflight`, the
     DevServer router, and the `s3cret` fixtures.
3. **Suites on PHP 8.4.26.**
   - `composer install` for the root and `ci4/` in a copy under the QA folder.
   - PHPUnit with `APOTHEKE_APP` unset and with `APOTHEKE_APP=ci4`, then `tests/run.sh`.
   - Mutants on the key Core tests.
   - Run CI4 against a real PostgreSQL 17 (DSN, schema, `trust_proxy`), because the host has no `pdo_pgsql` by default.
4. **CI run 39432652114.** Head SHA and 22/22 green. Read the Lint, deptrac, DI (audit), both Docker legs
   (Imagick/HEIC) and ci4 leg logs (not skipped). Check that the SC2016 disable covers one command only.
5. **Docs.** Accuracy, the link checker, actionlint, shellcheck, and gitleaks on `d94465f..5c1c6d9` and on the tree.
6. **Sync preview.** Trial merge of `main`'s open fix #36 (`b954a5e`) into this head, resolved per the PM's I-3
   guidance.

## Results

### 1. #31 follow-ups (all closed)

| #31 item | What changed | Verification | Result |
|---|---|---|---|
| **L-1**: the guard was a `grep` for `APOTHEKE_APP` | `ci4-leg.sh` runs `tests/lib.php` and asserts `apotheke_app()` selects `ci4` and `legacy`, and `apotheke_front_controller()` points at `ci4/public/router.php` (and the legacy one does not). The CI4 server sets `APOTHEKE_HARNESS=1`, `HarnessMark` logs `apotheke-harness app=ci4`, and `Ci4HttpTest` asserts that line on the CI4 server and not on legacy. | Mutants: (a) `apotheke_app()` ignores the env, keeping an `APOTHEKE_APP` comment → exit 1, "did not select ci4". (b) The ci4 front controller is commented out → exit 1. (c) Both functions replaced by a comment naming `APOTHEKE_APP` → exit 1, "does not switch servers". (d) `HarnessMark` silent → `Ci4HttpTest` fails. | **Closed** (for SQLite, see L-1 below) |
| **N-1**: any value other than `ci4` counted as legacy | `case` accepts only `legacy` and `ci4`, and exits 2 otherwise | `bogus`, `Legacy`, `CI4`, `ci4x`, `legacy;` → exit 2. Empty → exit 1 (usage). | **Closed** |
| **N-2**: a missing `index.php` gave `run=false` (green) | The `run=false` branch is gone. A missing `ci4/public/index.php` exits 1. | Deleted it in a copy → exit 1, "cannot pass without the front controller" | **Closed** |
| **N-3**: tools were looked up on `PATH` only, with no install step | `ci4_tool_path()` checks `vendor/bin`, then `ci4/vendor/bin`, then `PATH`. The deptrac and DI jobs run `composer install`, and DI also installs `ci4/` and runs `composer audit`. | deptrac: 0 violations, 2 allowed. DI: PHPStan level 6 `[OK]`. With `vendor/bin/{deptrac,phpstan}` removed and a clean `PATH`, both exit 1, "not on PATH". In CI the deptrac job installs deptrac 2.0.7, and DI reports "No security vulnerability advisories found" and `[OK] No errors`. | **Closed** |

### 2. Scope and skeleton review

- **Files:**
  - `026073f` adds `ci4/` (113 files) and changes `ci.yml`, `.gitignore`, the root `composer.json`/`.lock` (deptrac and
    phpstan as dev dependencies), `deptrac.yaml`, `phpstan-di.neon`, `scripts/ci4-arch.php`, `ci4-leg.sh`, `lint.sh`,
    `tests/lib.php`, `tests/parity/recorder.php`, `HttpSuiteTest.php`, and nine new unit tests plus two updated ones.
  - `4e282b2` changes only docs, `5d2cc81` only `ci.yml` and `docs/ci.md`, and `5c1c6d9` only one line in `ci4-leg.sh`.
  - `Dockerfile` and `release.yml` are unchanged.
  - The image copies only `public`, `src`, `migrations` and `bin`, so `ci4/` is not shipped.
  - The tree rebuilt from the four tarballs (each checked with `git get-tar-commit-id`) is identical to the head.
- **PLAN row 4 deliverables:** all present. That is appstarter 4.7.5, `ApothekeConfig` (same env map as
  `src/config.php`), `DsnTranslator`, `DatabaseFactory`, `RequestScheme`, `OriginalsGuard` (front controller, router
  and filter), the security filters, `StoragePreflight`, the DevServer router, `/healthz` and `/health`.
- **Pins:**
  - `ci4/composer.json` requires `codeigniter4/framework` `4.7.5` exactly, plus `php ^8.4` (platform 8.4.26), `ext-intl`,
    `ext-mbstring` and `ext-sqlite3`.
  - The lock has `codeigniter4/framework v4.7.5`, `laminas/laminas-escaper 2.18.0` and `psr/log 3.0.2`, and the install
    resolves v4.7.5.
  - `composer validate` only warns about the exact pin, which is intended.
  - `composer audit` finds no advisories, in `ci4/` and at the root.
- **Config review:**
  - **Secrets:** none. `Encryption::$key` is empty and nothing uses encryption yet. No `.env` is committed.
  - **`baseURL`:** taken from `PUBLIC_URL`, or else built from the scheme and a `Host` limited to
    `[A-Za-z0-9.\-\[\]:]`. This matches legacy's own `HTTP_HOST` fallback. `proxyIPs` is empty, as the plan says (M-2).
  - **CSRF and security:** CI4's CSRF filter is not enabled (the plan says CI4 CSRF is off, and the app's 419 filter
    comes in PR 7). `Security.php` keeps appstarter defaults.
  - **Filters:** the required filters are `headasget`, `harness`, `originalsguard`, `posttoolarge` and `session`, and
    `securityheaders` runs globally after. Toolbar, `forcehttps` and `pagecache` are removed.
  - **Routing:** `autoRoute` is off, and only `healthz` and `health` are routed.
  - **Errors:** with no `CI_ENVIRONMENT` set, the environment is production (`display_errors=0`, `CI_DEBUG` false).
    `PlainHandler` answers in plain text: `UserError` keeps its status, 404 gives "Not found", and anything else gives 500
    "Server error" with the detail only in `error_log`.
  - **`writable/`:** only the `index.html` placeholders and `.htaccess`, and `.gitignore` ignores everything else.
    Originals get 0750 and the deny file 0640, both asserted.
  - **Blocked paths on the dev server:** `/.env`, `/spark`, `/app/Config/App.php`, `/writable/logs/index.html` and
    `/vendor/autoload.php` all give 404.
- **`DsnTranslator`:**
  - `sqlite:<path>` maps to SQLite3.
  - `pgsql:host;port;dbname[;…]` maps to Postgre plus a `pg_connect` string. A missing host, port or dbname, `mysql:`,
    an empty `sqlite:` or an empty `pgsql:` fails at boot.
  - `password=` is redacted as `***` in the message.
  - **The connect string only escapes `'` (M-1).**
- **`TrustProxyReader`:** reads `trust_proxy` from `settings` through its own SQLite3 or `pg_connect` connection.
  **On PostgreSQL it ignores `DB_SCHEMA` (M-2).**
- **`s3cret`:** used in `DsnTranslatorTest` (a `mysql:` DSN must be rejected and redacted) and in
  `Ci4HttpTest::testBadDsnFailsAtBootAndRedactsThePassword` (the boot page must not contain it). Both are dummy test
  values, like the existing `s3cret` fixtures in `tests/integration.php` and the autofill tests.

### 3. Suites, mutants and PostgreSQL (PostgreSQL see M-1, M-2, L-1)

| Run (PHP 8.4.26, SQLite) | Result |
|---|---|
| PHPUnit, legacy (`APOTHEKE_APP` unset) | `Tests: 326, Assertions: 2288, Skipped: 8` (pgsql ×2, zbarimg ×6), OK |
| PHPUnit, `APOTHEKE_APP=ci4` | `Tests: 326, Assertions: 2286, Skipped: 9` (plus the catalog HTTP suite), OK |
| `tests/run.sh` | lint OK, **1423 passed, 0 failed** |

| Mutant | Killed by |
|---|---|
| Password redaction removed | `DsnTranslatorTest` and `testBadDsn…` |
| `RequestScheme` uses the last `X-Forwarded-Proto` value | `RequestSchemeTest` and `Ci4HttpTest` (cookie matrix) |
| `StoragePreflight` without the realpath (symlink) check | `testSymlink…` (404 instead of 500) and `StoragePreflightTest` |
| Originals directories 0775 | `Ci4HttpTest` and `StoragePreflightTest` |
| `OriginalsGuard` case-sensitive | `DevServerRouterTest`. **`Ci4HttpTest` alone passes (N-3).** |

**PostgreSQL 17 (local container):**

- **Default credentials:** CI4 `/healthz` and `/health` return 200 with the legacy bodies, and the `DB_SCHEMA` schema is
  created.
- **`DB_PASSWORD` with a backslash** (role password `pa\ss`):
  - legacy `/healthz`: 200
  - CI4: **500 "Server error"**, and the log shows "password authentication failed"
  - With `\` escaped before `'` in `DsnTranslator`, CI4 returns 200 and `DsnTranslatorTest` still passes (M-1).
- **`trust_proxy` on, `X-Forwarded-Proto: https`:**

  | | `DB_SCHEMA` set | no schema (`public`) |
  |---|---|---|
  | legacy | `apothekesid` **Secure** | Secure |
  | CI4 | **not Secure** | Secure |

  See M-2.
- **What the ci4@pgsql CI cell runs:** `Ci4HttpTest` with `TEST_DB_DRIVER=pgsql` fails at its SQLite-only WAL
  assertion. In CI, the PHPUnit process has no `TEST_DB_DRIVER`, so `test_db_env()` starts every CI4 server on
  SQLite. The ci4@pgsql cell never runs CI4 against PostgreSQL (L-1).

### 4. CI run 39432652114 (PASS)

- **Run:** a `pull_request` run on `5c1c6d9` (merge ref `eefeebd` = `5c1c6d9` into `d94465f`), from 16:41:55 to
  16:52:47 (Europe/Athens). **22/22 success.**
- **ci4 @ 8.4 sqlite and pgsql:**
  - `ci4-leg.sh` gave `run=true`, `codeigniter4/framework (v4.7.5)` was installed, and PHPUnit ran.
  - sqlite: `Tests: 326, Assertions: 2295, Skipped: 1`. pgsql: `326, 2296, Skipped: 1`.
  - The one skip is the catalog HTTP suite.
- **legacy @ 8.4 sqlite:** `OK (326 tests, 2297 assertions)`. The legacy @ 8.3 and pgsql cells also passed.
- **Lint 8.3 and 8.4:** PHP lint (now including `ci4/`, excluding `ci4/vendor` and `ci4/writable`), JS, links 228 in
  35 files with 0 broken, shellcheck, actionlint and pipefail greps are all ok.
- **deptrac and DI:** both installed from Composer. The DI job ran the `ci4/` audit (no advisories) and returned
  `[OK] No errors`.
- **Docker 8.3 and 8.4:**
  - `PHP_VERSION=8.3` and `8.4`, with cache scopes `php8.3` and `php8.4`.
  - The Imagick layer was **cached**, since the Dockerfile is unchanged.
  - `docker-heic.sh` gave `ok HEIC`, `ok heic round-trip` and `ok refusal`, and smoke and `@smoke` Playwright passed.
- **Other jobs:** Playwright ×6, mobile ×2, Conventional Commits and Secret scan all succeeded.
- **SC2016:** `# shellcheck disable=SC2016` sits directly above the single `php -r '…'` command, so it covers that one
  command. Without it, shellcheck reports SC2016 on that command only. `shellcheck scripts/*.sh` is clean.

### 5. Docs, links, gitleaks (PASS, see N-1 and N-4)

- **Accurate:**
  - `docs/ci.md`: the leg rules, the ci4 cell skipping `tests/integration.php`, the `ci4/` install on 8.4 and the
    audit gate wording.
  - `docs/testing.md`, `docs/architecture.md` and `docs/getting-started.md` (the CI4 dev server needs PHP 8.4 and
    `ci4/vendor`).
  - The PLAN changelog entry, and the cache key with both lockfiles.
- **Stale:** the README CI table rows for deptrac and the DI rule still say "empty config" (N-1). The CHANGELOG line
  says "same messages" for a bad DSN (N-4).
- **Checks:** link checker 228 links in 35 files, 0 broken. actionlint is clean.
- **gitleaks 8.30.1:**
  - The four PR commits: **no leaks**.
  - The full head tree: no findings. The `s3cret` fixtures are not flagged.

### 6. Sync preview: #36 `b954a5e` into this head (see I-3)

- **Conflicts in 6 files:** `AGENTS.md`, `README.md`, `docs/architecture.md`, `docs/getting-started.md`, `src/qr.php`
  (2 hunks) and `tests/Unit/QrCodeTest.php` (2 hunks). `CHANGELOG.md` now merges cleanly, because #36 empties
  `[Unreleased]`.
- **Resolution per the PM:**
  - `qr.php` and `QrCodeTest.php` take #36's side, and the result is byte-identical to `b954a5e`.
  - The doc hunks take #36's wording, and getting-started keeps the `PHP_VERSION` sentences and adds "JIT is left off".
  - QrCode, Ci4*, LinkCheck, ParityRecorder, DsnTranslator and RequestScheme pass (49 tests, 6 skipped). Links: 228 in
    35 files, 0 broken.
- **Caution:** resolve per hunk. Taking whole files from `main` drops CI4 lines: `AGENTS.md#ci4-module-rules` breaks
  two links, and the N-4 wording test fails.

### 7. Delta recheck `5c1c6d9..5d829fa` (M-1, M-2, L-1 closed; M-3 and L-2 raised, closed in §8)

Delta commits: `6ba33bf` (L-1), `c3373c6` (M-1), `e00884c` (M-2), `6479c2c` (N-1, N-4), `5d829fa` (N-3). N-2 is accepted with
no change. 16 files changed, +336/−25. PostgreSQL 17 was a throwaway local container. `php8.4-pgsql` was installed for the
check and removed afterwards.

**Scope and M-3.** The Postgre-driver assert and the probe write are not test-only.
`FrameworkWiring::apply()` runs on `pre_system` for every request and calls `assertPgsqlLeg()` whenever
`APOTHEKE_TEST_DB=pgsql`. Nothing else gates it. A production-mode CI4 boot on PostgreSQL (`CI_ENVIRONMENT=production`,
`DB_SCHEMA` set) gave these results:

| `APOTHEKE_TEST_DB` | `/healthz`, `/health` | normal page | `public.apotheke_ci4_pgsql_probe` |
|---|---|---|---|
| unset | 200, 200 | 404 (no catalog routes yet) | never created |
| `sqlite` | 200, 200 | 404 | never created |
| `pgsql` | 200, 200 | 404 | created, **one row per request** (3 requests, 3 rows), in `public` even though `DB_SCHEMA` was set |

If the role can't create or insert into that table, **every request returns 500** ("could not record
public.apotheke_ci4_pgsql_probe"). That happens on PG15+, where PUBLIC has no CREATE on `public`, or when another role owns the
table. This showed up first in the M-1 multi-role run. This is **M-3**. The coder confirmed it, and the PM rated it Medium.

**M-1 (closed).** Every libpq value is now quoted, with `\` escaped before `'`. A real PostgreSQL run used one role per case,
with `/healthz` on legacy and CI4 (probe disabled, so M-3 doesn't mix in):

| Case (password unless noted) | legacy | CI4 `5d829fa` | CI4 `5c1c6d9` (control) |
|---|---|---|---|
| `pa\ss` | 200 | 200 | 500 |
| trailing `\` | 200 | 200 | 500 |
| `it's` | 200 | 200 | 200 |
| `'a b'` | 200 | 200 | 200 |
| `a=b` | 200 | 200 | 200 |
| user `qa us'er\x` | 200 | 200 | 500 |

**M-2 (closed).** `TrustProxyReader` now sets `search_path` to the quoted `DB_SCHEMA` and reports "not trusted" if that
fails. Setup: `trust_proxy` on, `X-Forwarded-Proto: https`.

| | legacy, schema | legacy, no schema | CI4, schema | CI4, no schema |
|---|---|---|---|---|
| `5d829fa` | Secure | Secure | Secure | Secure |
| `5c1c6d9` | Secure | Secure | NOT secure | NOT secure (the backslash CI password also hits M-1) |

Run with the probe off (`APOTHEKE_TEST_DB` empty), the head is still Secure, and the head with `applySchema()` removed is
NOT secure. The fix works without the probe.

**Mutants** against the new tests, on PostgreSQL with the CI password `apotheke\test`:

| Mutant | Result |
|---|---|
| Baseline (DsnTranslator, Ci4Http and Ci4BranchCi tests) | OK, 28 tests |
| Old escaping (only the password quoted, only `'` escaped) | killed: `testPgsqlHostPortDbnameAndSslmode`, `testHealthzAcceptsABackslashPasswordOnPostgres` (500) |
| `'` escaped but not `\` | killed: `testPgsqlQuotesEveryValueForLibpq`, backslash `/healthz` (500) |
| L-1 reverted (servers get `DB_DRIVER=sqlite`) | killed: `testServerEnvPassesApothekeTestDbExplicitly`, `testTrustProxyFollowsTheSchemaOnPostgres` |
| N-3: case-sensitive `OriginalsGuard` | killed: `testHealthOriginalsCookiesAndRouter` (cookie on the guard path) |
| **`applySchema()` removed** | **survives** (L-2) |
| `applySchema()` removed, `TrustProxyReader` using `PGSQL_CONNECT_FORCE_NEW` | killed: `testTrustProxyFollowsTheSchemaOnPostgres` |

Why the M-2 mutant survives: PHP's `pg_connect()` hands back the existing link when the connection string is the same.
On the pgsql leg, `assertPgsqlLeg()` has already connected through CI4 (and `DatabaseFactory` set `search_path`), so
`TrustProxyReader` reuses that link and inherits the schema whether or not `applySchema()` runs. A CLI check also showed that
`TrustProxyReader`'s `pg_close()` then closes CI4's own link ("PostgreSQL connection has already been closed"). That is
latent today, because no route touches the database after the trust read. See L-2.

**L-1 (closed).** The local fallback simulation:

| Case | Result |
|---|---|
| CI4 servers silently on SQLite while the job is pgsql | `ci4-pgsql-proof.php`: "apotheke_ci4_pgsql_probe is missing", exit 1 |
| `APOTHEKE_TEST_DB=pgsql` with server `DB_DRIVER=sqlite` | `/healthz` 500, log "CI4 pgsql leg connected with SQLite3, expected Postgre". The same server with the variable empty gives 200. |
| Table present, 0 rows | `rows=0`, exit 1 |
| Good pgsql leg | `rows=10`, exit 0 |

In CI, the ci4@pgsql job logs `APOTHEKE_TEST_DB: pgsql`, PHPUnit OK (330 tests, 1 skipped), then "Prove CI4 used
PostgreSQL" prints `apotheke_ci4_pgsql_probe rows=12`. The ci4@sqlite job has `APOTHEKE_TEST_DB: sqlite` and no proof step.

**Suites** at `5d829fa` (PHP 8.4, local PG17):

| Suite | Result |
|---|---|
| PHPUnit legacy@sqlite | OK, 330 tests, 10 skipped |
| PHPUnit legacy@pgsql | OK, 330 tests, 6 skipped |
| PHPUnit ci4@sqlite | OK, 330 tests, 11 skipped |
| PHPUnit ci4@pgsql | OK, 330 tests, 7 skipped |
| `tests/run.sh` | 1423 passed, 0 failed |

Local skips are environment-only (no CI flag, no Playwright or Docker).

**CI run 39439097802.** Attempt 2 has 22 of 22 jobs successful, all on `5d829fa`. Attempt 1 had one cancellation: Visual E2E
(Playwright, PHP 8.4, shard 2/3) hit the job's `timeout-minutes: 30` inside "Run Playwright" (17:25:44 to 18:00:44 Europe/Athens).
Its sibling shards took 7 to 10 minutes, and the rerun of that shard passed in 9 minutes. The delta touches no e2e code or
config (only docs mention Playwright), and the GitHub log blob for the cancelled job isn't stored. So this is a hang or
runner issue, not caused by the delta.

**Docs and scans.**
- N-1: the README CI table now describes Core/DevServer, PHPStan level 6 and the `ci4/` audit gate.
- N-4: the CHANGELOG says a bad DSN "stops boot and redacts the password", and only the web-root text matches legacy.
- `docs/ci.md`, `docs/testing.md` and `docs/configuration.md` describe `APOTHEKE_TEST_DB`, the probe and `trust_proxy`
  following `DB_SCHEMA`.
- Links: 228 checked in 35 files, 0 broken. shellcheck and actionlint are clean.
- gitleaks on a replay of the 5 delta commits finds no leaks. A directory scan of the head tree finds no leaks either.
- `apotheke\test` is a deliberate, test-only CI password for the throwaway PostgreSQL service, in `ci.yml`,
  `Ci4BranchCiTest` and `docs/ci.md`. It is not a secret. Its backslash is what keeps M-1 covered in CI. The PM should know
  it exists and must never be reused anywhere real.

### 8. Delta recheck `5d829fa..be46428` (M-3 and L-2 closed)

Delta commits:
- `5c81c07` fix: move the CI4 pgsql probe into the test router
- `541926b` ci: report SQLSTATE from the pgsql proof script
- `2e8e0a0` test: production boot does not create the probe table
- `5a7969a` fix: read trust_proxy on its own postgres connection
- `be46428` test: use the postgres service for the probe check

The compare API shows 12 files, +417/−48, which matches a local diff of the tarballs (each checked with
`git get-tar-commit-id`). PostgreSQL 17 was a throwaway local container. `php8.4-pgsql` was installed for the check and
removed afterwards.

**Scope.**
- `FrameworkWiring.php` only loses the probe (−28 lines). `ci4/` has no remaining reference to the probe or to
  `APOTHEKE_TEST_DB`.
- The probe now lives in `tests/ci4-router.php`. It registers a low-priority `pre_system` listener, so it runs after the
  wiring, only when `APOTHEKE_TEST_DB=pgsql`. It then requires `ci4/public/router.php`.
- `tests/lib.php` starts CI4 through that router by default. It takes an explicit router argument for a shipped boot, and
  a `null` env value unsets a variable.
- `TrustProxyReader` changes one line: `pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW)`.
- `ci4-pgsql-proof.php` adds the SQLSTATE split and `connect_timeout=5`.
- `DsnTranslator.php` and `.github/workflows/ci.yml` are byte-identical to `5d829fa`.

**M-3 (closed): production boot on the shipped router.** Setup:
- `CI_ENVIRONMENT=production`, PostgreSQL user without CREATE on `public` or on the database, password with a backslash,
  `DB_SCHEMA` set to a schema that user owns.
- `/healthz`, `/health` and `/` were requested, plus 7 attempts to reach the test router: `/tests/ci4-router.php`,
  `/../tests/…`, `/%2e%2e/tests/…`, `/..%2ftests/…`, `/ci4-router.php`, `/index.php/../../tests/…` and
  `/assets/../../../tests/…`. curl used `--path-as-is`, so dot segments reached the server unchanged.

| App, `APOTHEKE_TEST_DB` | `/healthz`, `/health` | test-router paths | `public.apotheke_ci4_pgsql_probe` |
|---|---|---|---|
| CI4 shipped router, unset / `sqlite` / `pgsql` | 200, 200 | all 404 | never created |
| Legacy `public/index.php`, unset / `sqlite` / `pgsql` (as the DB owner) | 200, 200 | all 404 | never created |

The test router itself still writes rows. On a fresh database, the ci4@pgsql cell gives `rows=11`, and the proof exits 0.
The test router is not gated on `CI_ENVIRONMENT=testing` as first suggested. It doesn't need to be: no shipped entry
reaches it (next paragraph). See I-6.

**The test router is not shipped and not reachable.**
- **Dockerfile:** it copies only `docker/*` config, `public`, `src`, `migrations`, `bin`, `config.example.php` and
  `VERSION`. It copies neither `tests/` nor `ci4/`.
- **`.dockerignore`:** it also lists `tests/`.
- **nginx:** the root is `public/`, a deny rule 404s `src|data|bin|migrations|docker|tests|vendor|config.php`, and
  FastCGI always runs `index.php`.
- **CI4 dev router:** `ci4/public/router.php` serves only real non-`.php` files inside `ci4/public` (realpath check). The
  docroot holds only `index.php`, `router.php` and `assets/`.
- **Built image:** I built `be46428` locally (PHP 8.3 default) and checked it:
  - The file list has `/var/www/app` = `VERSION bin config.example.php migrations public src`. The only PHP file in
    `public/` is `index.php`.
  - `ci4-router.php`, `ci4-pgsql-proof.php`, and any `tests/` or `ci4/` directory under `/var/www` all appear 0 times in
    the whole filesystem.
  - Run with `APOTHEKE_TEST_DB=pgsql`: `/healthz` gives 200. `/tests/ci4-router.php`, `/ci4-router.php`, `/tests/`,
    `/ci4/public/router.php` and `/src/db.php` give 404. The dot-segment and encoded traversal paths give 400.

This confirms DevOps's statement independently.

**L-2 (closed): shared connection after the trust read.** In a CLI check, I opened a link first, as CI4 does, with
`search_path=qa_shared`. Then `TrustProxyReader` read `trust_proxy` from a different schema.
- At `be46428`: `trusted=true`, the first link is still alive, and its `search_path` is unchanged.
- At `5d829fa`: `trusted=true`, but the first link was closed ("PostgreSQL connection has already been closed").

**Mutants** (Coder's claims plus my own), on PostgreSQL 17 with `apotheke\test`. Baseline is OK, 33 tests.

| Mutant | Result |
|---|---|
| `applySchema()` removed (Coder) | killed: `testTrustProxyFollowsTheSchemaOnPostgres`, `testPostgresReaderLeavesTheOpenConnectionUsable` (and the PHPStan gate) |
| `PGSQL_CONNECT_FORCE_NEW` removed (Coder) | killed: `testPostgresReaderLeavesTheOpenConnectionUsable` |
| Probe back in shipped boot, using the `5d829fa` `FrameworkWiring` (Coder) | killed: `testShippedBootDoesNotWriteThePgsqlProbe` (500 for the user without CREATE), plus a `Ci4BranchCiTest` guard |
| Proof reports every failure as "missing" | killed: `testPgsqlProofSeparatesMissingTableFromOtherFailures` |
| Harness ignores the shipped-router argument | killed: `testShippedBootDoesNotWriteThePgsqlProbe` |
| Test-router probe disarmed | fresh database (as in CI): killed (`did not write apotheke_ci4_pgsql_probe`), and the proof says `missing (SQLSTATE 42P01)`, exit 1. Reused local database: survives (N-6). |
| `null` env value not unset | survives. Equivalent today, because shipped boot ignores the variable and the `pgsql` case is tested anyway (N-5). |

**Proof script.** It reports a closed port and a bad password with their SQLSTATE and doesn't say "missing". A dropped
table prints `42P01`. The test asserts that neither the password nor the DSN is printed.

**Suites** at `be46428` (PHP 8.4, local PG17):

| Suite | Result |
|---|---|
| PHPUnit legacy@sqlite | OK, 333 tests, 11 skipped, 1 incomplete |
| PHPUnit legacy@pgsql | OK, 333 tests, 6 skipped |
| PHPUnit ci4@sqlite | OK, 333 tests, 12 skipped, 1 incomplete |
| PHPUnit ci4@pgsql | OK, 333 tests, 7 skipped |
| `tests/run.sh` | 1423 passed, 0 failed |

The incomplete test is the proof test's PostgreSQL half. It needs a reachable PostgreSQL, which CI's sqlite cells have and
my local sqlite runs didn't.

**CI run 39454238792** (via the API): attempt 1, 22 of 22 jobs successful, all on `be46428`, 19:04:47 to 19:15:22
Europe/Athens.
- ci4@pgsql: `Tests: 333`, OK (1 skipped), then the proof prints `apotheke_ci4_pgsql_probe rows=13`.
- ci4@sqlite: OK (4 skipped), no proof step.
- The PostgreSQL service's error noise (`gin_trgm_ops`, missing `activity`) is the same as on `5d829fa` (418 and 4 lines),
  plus 3 lines expected from the new missing-table checks.
- `ci.yml` is unchanged: same sha256 as `5d829fa`.

**Docs and scans.**
- `CHANGELOG.md`, `docs/ci.md`, `docs/testing.md` and `docs/configuration.md` describe the test router, the SQLSTATE
  split, the separate trust connection, and that `APOTHEKE_TEST_DB` on a production install creates nothing. All of that
  matches the measurements.
- Links: 228 checked in 35 files, 0 broken. shellcheck and actionlint are clean.
- gitleaks on a replay of the 5 commits (`5d829fa..be46428`) finds no leaks. A directory scan of the head tree finds no
  leaks either.
- The `apotheke\test` note from §7 still holds. It is the only password-like value the delta adds or keeps, and the test
  user password `nocreatepass` is created and dropped by the test itself.

## Findings

| ID | Sev | Finding | Fix |
|---|---|---|---|
| M-1 | **Closed** at `5d829fa` (was Medium, blocks). Verified in §7 | `DsnTranslator::pgsql()` builds the `pg_connect` string with `password='…'` and escapes only `'`. libpq also treats `\` as an escape inside quotes, so a password with a backslash is changed, and a trailing `\` leaves the quote open. Measured: `DB_PASSWORD` `pa\ss` gives 200 on legacy and **500 on CI4** ("password authentication failed"). `user` and the extra DSN keys go in unquoted, so a value with a space or quote also breaks. The same string feeds `TrustProxyReader`. Row 4 requires the DSN translator for pgsql. | Quote every value with `'…'`, escaping `\` before `'` (`str_replace(['\\', "'"], ['\\\\', "\\'"], $v)`). QA checked that this fixes the measured case and keeps `DsnTranslatorTest` green. Add table cases (`\`, trailing `\`, a space in `user`) and a PostgreSQL runtime case (see L-1). |
| M-2 | **Closed** at `5d829fa` (was Medium, blocks). Verified in §7 | `TrustProxyReader::postgres()` connects without `search_path` and runs an unqualified `SELECT … FROM settings`. With `DB_SCHEMA` set, it reads `public` (or finds no table) and reports "not trusted". Measured with `trust_proxy` on and `X-Forwarded-Proto: https`: legacy sets `apothekesid` **Secure** and CI4 does **not**. This is the row 4 Secure-cookie matrix, on PostgreSQL with a schema (the per-test setup in CI uses one). | Apply the schema: `SET search_path` after connecting, add `options='-c search_path=…'`, or schema-qualify the quoted identifier. Add a PostgreSQL test with `DB_SCHEMA` set and `trust_proxy` on and off. |
| L-1 | **Closed** at `5d829fa` (was Low). Verified in §7 | The ci4@pgsql cells never run CI4 against PostgreSQL. `Ci4HttpTest` starts its servers through `test_db_env()`, which keys on `TEST_DB_DRIVER`, and the PHPUnit process doesn't set it, so both CI4 cells use SQLite. The WAL and `settings` checks are SQLite-only. A green ci4@pgsql cell claims driver coverage it doesn't have, the same class of problem as #31 L-1, and it is why M-1 and M-2 got through. | Let `Ci4HttpTest` follow `DB_DRIVER` in the pgsql cell, assert WAL only on SQLite, and add the M-1 and M-2 cases there. |
| M-3 | **Closed** at `be46428` (was Medium, blocks). Verified in §8 | `FrameworkWiring::apply()` runs `assertPgsqlLeg()` on every request (`pre_system`) when `APOTHEKE_TEST_DB=pgsql`, with no environment gate. A production boot (`CI_ENVIRONMENT=production`) with that variable set creates `public.apotheke_ci4_pgsql_probe` and writes one row per request into the production database, ignoring `DB_SCHEMA`. If the role can't create or insert into it (PG15+ default, or another owner), every request returns 500. With the variable unset or `sqlite`, the table is never touched (§7). | Move the assert and write into the test-only server entry, gated on the test variable **and** `CI_ENVIRONMENT=testing`. Add a test that a production boot never touches the table. `ci4-pgsql-proof.php` should print the SQLSTATE (DevOps nit). Coder is on it. |
| L-2 | **Closed** at `be46428` (was Low). Verified in §8 | `testTrustProxyFollowsTheSchemaOnPostgres` doesn't kill the `applySchema()`-removed mutant. `pg_connect()` reuses CI4's link (opened by the probe, `search_path` already set), so the schema is inherited. `TrustProxyReader`'s `pg_close()` then closes that shared link, which is latent today because nothing queries after the trust read. The M-2 fix itself is verified with the probe off. | Use `pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW)` in `TrustProxyReader`, which also stops it closing CI4's link. With it, the mutant is killed. Or make the test servers that check trust skip the probe. |
| N-1 | Closed (was Nit, docs) | The README CI table still says "deptrac: empty module-graph config until CI4 modules exist" and "DI rule: empty PHPStan config until CI4 paths exist". | Describe Core/DevServer and level 6 plus the `ci4/` audit, as `docs/ci.md` does. |
| N-2 | Accepted (Nit, no change) | `5c1c6d9` is typed `fix:` for a lint-only change, the same as #31 N-7. It shows up as a Fixed item when the branch reaches `main`. | Use `ci:`/`chore:` going forward. No rewrite needed. |
| N-3 | Closed (was Nit, test). Mutant now killed | `Ci4HttpTest::originals()` can't tell the guard apart from CI4's generic 404 (same status, body and headers). With a case-sensitive `OriginalsGuard`, that test still passes. `DevServerRouterTest` catches the mutant, so the suite as a whole is fine. | Optional: also assert the guard path, for example that the harness line is absent for `/originals/…`, or use a route that would otherwise answer. |
| N-4 | Closed (was Nit, docs) | The CHANGELOG `Added` line says a bad DSN "stops boot with the same messages". Legacy has no DSN boot message, and only the web-root refusal text is the same as legacy's. | Say "stops boot with a clear message (web-root refusal text identical to legacy)". |
| N-5 | Nit (test, follow-up) | `Server`'s new `null` env value ("unset this variable") isn't asserted. A mutant that ignores it survives. Today that is equivalent, because shipped boot ignores `APOTHEKE_TEST_DB` and the `pgsql` case is tested too. | Assert the child environment lacks the variable, for example with a router that echoes `getenv()`. |
| N-6 | Nit (test, follow-up) | `Ci4HttpTest::probeRows()` counts all rows. On a reused local database, a disarmed probe still passes. CI's fresh service database and the proof step catch it. | Compare the row count before and after the request. |
| I-1 | Info | `TrustProxyReader` opens its own database connection twice per request (in `App` config and in `SessionCookie`). | Consider reusing the CI4 connection when the catalog routes land. |
| I-2 | Info | Unknown routes return `PlainHandler`'s 404 without the four security headers. "CSP on every response" is a later plan item. | Track for the routes PRs. |
| I-3 | Info (sync) | The next `main` → `feature/ci4` sync conflicts in 6 files (§6). The PM's resolution works. | Resolve per hunk. |
| I-4 | Info | The Docker legs reused the cached Imagick layer. `docker-heic.sh` passes on both. | DevOps keeps watching the uncached 8.4 build. |
| I-5 | Info (pre-existing, legacy) | With `DB_SCHEMA` set, legacy `src/db.php` runs `CREATE SCHEMA IF NOT EXISTS`, so a PostgreSQL user without CREATE on the database gets 500 even when the schema exists. CI4 boots as that user. The file is unchanged by this PR. | Track for the parity list. |
| I-6 | Info | `tests/ci4-router.php` is gated only on `APOTHEKE_TEST_DB=pgsql`, not on `CI_ENVIRONMENT=testing`. It doesn't ship and no web path reaches it (§8). | None needed. |

Final (`be46428`): **nothing blocks**. M-1, M-2, M-3, L-1 and L-2 are closed. N-5, N-6, I-5 and I-6 are follow-ups or info. First round: no high findings. **M-1 and M-2 blocked.** L-1 should land with them, because their regression tests need a CI4 server on
PostgreSQL. The N items can be follow-ups.

## Verdict

**PASS (final) at `be464283cf4ff8d594025528e704cf321bd75cbb`.** No high or medium findings are open.

- **Round 1 (`5c1c6d9`):** M-1 and M-2 blocked, and L-1 explained why CI missed them.
- **Round 2 (`5d829fa`):** M-1, M-2 and L-1 are fixed and verified on real PostgreSQL 17 with mutants. N-1, N-3 and N-4
  are closed, and N-2 is accepted. M-3 (probe in the real boot path) and L-2 (M-2 test masked by link reuse) were raised.
- **Round 3 (`be46428`):** M-3 and L-2 are fixed.
  - The probe lives only in `tests/ci4-router.php`. It doesn't ship in the image, no web path reaches it, and a production
    boot never creates the table, including as a PostgreSQL user without CREATE.
  - `TrustProxyReader` leaves CI4's connection open.
  - Coder's three mutation claims hold, and my extra mutants are killed or are documented as nits (N-5, N-6).
  - Suites, CI 39454238792 (22/22, `rows=13`), links, shellcheck, actionlint and gitleaks are clean. `ci.yml` and
    `DsnTranslator` are unchanged.
- **Follow-ups:** N-5 and N-6 (test strength), I-1 to I-6 (info).
- **For the PM:** `apotheke\test` is a deliberate, test-only CI password. It is not a secret, and it must not be used
  anywhere real.
- **Committing:** this file can be committed unchanged as its own `docs(qa):` commit.

**Evidence** (QA box, `/workspace/apotheke-qa/pr38/`):

- Inputs: tarballs and `t/` (base, head and each commit), `repo/` (commit replay), `w/` (working copy with vendor).
- Mutants: `mut/`, `logs/mutants*.txt`.
- PostgreSQL probes: `pgprobe.php`, `pgtrust.php`, `pgdbg*.php`, `logs/pg-*.txt`.
- Suites and CI: `logs/suites-84.txt`, the CI job logs (`logs/ci-*.log`).
- Sync preview: `trial/`, `logs/trial-*.txt`.
- Scans: `logs/gitleaks.txt`, `logs/gl-*.json`, `logs/audit-*.txt`.
- Delta round: `delta/` (`commits.json`, `run.json`, `jobs.json`, `t/head`, `delta.diff`, `w/`). Probes are `m1probe.php`,
  `m2probe.php`, `m2ci4.php` and `roles.*`. Logs are `logs/m3-prod-boot.txt`, `logs/m1-repro-*.txt`, `logs/m2-repro.txt`,
  `logs/mutants.txt`, `logs/l1-sim.txt`, `logs/suites.txt`, `logs/gitleaks.txt` and `logs/baseline-sha256.txt`.
- Delta round 2: `d2/` (`compare.json`, `jobs.json`, `tars/`, `t/head`, `delta.diff`, `w/`). Scripts are `prodboot.sh`,
  `prodboot-legacy.sh` and `sharedconn.php`. Logs are `logs/prod-boot.txt`, `logs/image-files.txt`,
  `logs/shared-conn.txt`, `logs/mutants.txt`, `logs/suites.txt`, `logs/ci-ci4-*.log`, `logs/gitleaks.txt`, `logs/sha.txt`,
  `logs/tarballs.txt` and `logs/build.txt`.
