# QA: apotheke PR #69, CI4 Autofill module

- **PR:** [#69](https://github.com/thordin9/apotheke/pull/69), "feat(ci4): port the autofill client", into `feature/ci4`, by Coder.
- **Base:** `feature/ci4` at `3146170` (#74). At first review the branch was 6 commits, 6 ahead and 0 behind. `ee82efe`, `c70e616` and `6d9142c` sit on `8863a5c`; `d38e9f9` is a plain merge of `3146170`; `8d47865` and `4e3db09` follow it. No force-push.
- **Head tested:** `614cb1bce2691ea5ed5a2da28e2e2be26c5ca6af` (re-check, section 5), 3 commits on `4e3db09`. It was the same at the start (14:41) and the end (14:55) of the re-check. The first review was of `4e3db098168b518d04bfa8b6ae2f0847bb2538b1`, unchanged from 13:56 to 14:22 (Athens time, UTC+3).
- **Changes vs `feature/ci4`:** 31 files, +3239 / −11.
  - Runtime: `ci4/modules/Autofill/` (13 files: `AutofillService`, `AutofillClient`, `AnalysisCopyBuilder`, `ResponseClassifier`, `ImageLimit`, `InfoCache`, `LogRedactor`, `TokenPolicy`, `TempFile`, `StoredPhoto`, `CatalogContext`, `Controllers/{Suggest,SettingsAutofill}Controller`), `ci4/modules/Core/Storage/{Storage,LocalStorage}.php`, `ci4/app/Config/Routes.php` (+4 POST routes), `ci4/app/Config/Services.php` (+`autofill()` only).
  - Tests: `ci4/tests/Autofill/AutofillTest.php`, `ci4/tests/Core/LocalStorageTest.php`.
  - Config and docs: `deptrac.yaml`, `config.example.php` (comment only), `CHANGELOG.md`, `README.md`, `AGENTS.md`, `docs/{autofill,autofill-admin-guide,ci,configuration,contributing,user-guide}.md`, `docs/plans/feature12-ci4/PLAN.md`.
  - `Filters.php`, `App.php` and `SettingsService` are **not** in the diff (claim holds). No legacy runtime file (`src/`, `public/`) changed.
- **CI:** run 40090776197 at `614cb1b` (`pull_request`), **22/22 `success`** (14:39–14:50 Athens time, UTC+3). Check runs on the head SHA: 22 `success`. First review: run 40086963840 at `4e3db09`, 22/22.
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only.
  - Sources: codeload tarballs of `4e3db09`, `3146170`, `6d9142c` (pre-merge), `main` (`1dbe986`, v2.6.3) and #72's head `a29e1ee`; the GitHub API (PR, commits, files, check runs).
  - Local runs only in scratch copies, against PostgreSQL 17 in private databases (`qa2_69_*`), on ports 9601–9603.
  - No comment, review, push or merge was made.
- **Verdict at `614cb1b`: PASS.** No High or Medium finding remains. Open: L-2 (deferred to #72), L-3 (CSRF only until #76 and the CI4 login PR), L-4 (test gaps, reduced) and N-3 (wording). See section 5.
  - **M-1 fixed:** the plain-form token save and clear now answer 303 to `token-saved` / `token-cleared`, the write and the clear land, and CI4 logs no `ini_set` error. The new HTTP test fails when the fix is reverted.
  - **L-1, N-1, N-2 fixed.** The typed test token is redacted. The docs name the right fields. `LocalStorage` refuses keys through a symlink and replaces files by temp file plus `rename`.
- **Verdict at `4e3db09` (first review): FAIL** on M-1. Redaction, the caps, the single 413 retry, the storage keys and the stored original behaved as claimed. 18 of 35 mutations were caught. (The first issue of this doc said 19 of 35 and 16 survivors. A recount of the table gives 18 killed and 17 surviving, and the tables below are correct.)

## 1. Test plan

### 1.1 Environment

- **Docker isn't available on this box.** `git` to GitHub doesn't work here, so trees came from codeload tarballs. The mutation tree was `git init`ed locally to track and revert mutations.
- **Suites:** the box's PHP **8.4.27** CLI (pdo_pgsql, pgsql, pdo_sqlite, gd, imagick, curl, exif), with Composer dev dependencies from the locked `composer.lock` and `ci4/composer.lock` (CodeIgniter **4.7.5**). Each leg ran in its own copy of the tree.
- PostgreSQL **17.11**, scram-sha-256 on TCP, test-only superuser `apotheke` with CI's public test password (written as `<CI test password>` here). Databases: `qa2_69_c84` (ci4 pgsql leg), `qa2_69_mod` (ci4 sqlite leg's PG data sets), `qa2_69_mut` (module suite and mutations).
- **Legacy legs were not run:** no shared runtime code changed (`config.example.php` gains comments only; `deptrac.yaml` and docs are not runtime). CI's four legacy legs are green.
- **Live repros:** `php8.4 -S` with `ci4/public/router.php` (the shipped dev boot), SQLite, `DATA_DIR` in scratch. `:9601` ran the head, `:9602` a **QA-only hostile service** (echoes the bearer token in 500/503/non-JSON bodies, always-413, slow, 302, 400 KB body, busy with `Retry-After`), `:9603` a head copy with the one-line M-1 fix.
- **Fake credentials only:** stored token `<stored fake token>`, typed token `<typed fake token>`.
- gitleaks **8.30.1**, its tarball checked against the sha256 pinned in `ci.yml` (`551f6fc8…f2470eb`).
- Every suite run used `CI=true`.

### 1.2 Acceptance checks

1. **CI** on the head SHA, commit subjects, and the `d38e9f9` merge.
2. **Token redaction** (logs, exceptions, responses, stored rows, debug output; error, timeout and 413 paths).
3. **Caps and the 413 retry**, plus the decode path against main and #72.
4. **Storage interface:** keys, traversal, stored original unchanged, writes inside the root.
5. **Cloudflare phase:** storage behind the interface, no per-request or static state, DSN-driven DB.
6. **Routes:** auth, permission and CSRF; SSRF; upstream errors not leaked to the client.
7. **Suites and static checks:** ci4 @ 8.4 on SQLite and PostgreSQL 17, the module suite, PHPStan DI rule, deptrac, `lint.sh`, `composer audit`.
8. **Mutations** on the focus areas.
9. **Docs and CHANGELOG**, Security, and gitleaks.

## 2. Results

### 2.1 CI, commits and the merge (item 1)

- **Run 40086963840** (`4e3db09`, `pull_request`): **22/22 `success`**, 13:42–13:53 Athens time (UTC+3). Nothing failed and nothing is pending.
- **Commit subjects:** all 6 pass `scripts/check-commits.sh --message`, 39–50 characters: `feat(autofill)` ×2, `test(autofill)` ×2, `chore(ci4)` ×1, `docs(autofill)` ×1.
- **Merge `d38e9f9`** (parents `6d9142c`, `3146170`): clean.
  - The only files touched by both sides are `ci4/app/Config/Routes.php` and `Services.php`. At the head, each is `3146170`'s file plus one appended block (4 POST routes; the `autofill()` factory). Nothing from #74 was dropped or edited.
  - Every other file differs from `3146170` only where the PR's own commits change it (`diff -r` of the two trees).
  - `8d47865` (after the merge) only adds the session CSRF token to the existing HTTP photo test.

**PASS**

### 2.2 Token redaction (item 2)

Live on `:9601` with the stored token `<stored fake token>`, against the hostile stub. The token was re-saved after each address change, because saving a new address clears it (as in legacy).

| Path | Client response | Log line (`error_log`) |
|---|---|---|
| `/v1/suggest` 500, `text/plain`, body echoes the token | `200` `unavailable`, fixed message | `suggest: HTTP 500 (upstream failed for key [redacted] at /internal/path)` |
| 500, JSON body echoing the token | `unavailable` | `HTTP 500 ({"detail":"bad key [redacted]"})` |
| 503, non-API body `key=<token>` | `unavailable` | `HTTP 503 (key=[redacted])` |
| 200, non-JSON body with the token | `unavailable` | `unexpected body (not json [redacted])` |
| 503 `backend_unavailable` + `Retry-After: 2`, message contains the token | `busy`, "Try again in 2 s." | none (the message is used only to pick warming vs busy) |
| 400 KB body | `unavailable` | `response too large` |
| 302 to another host | `bad_response` "HTTP 302" | `HTTP 302` (not followed) |
| timeout (3 s, service sleeps 10 s) | `unavailable` after ~3 s | `suggest: timeout` |
| `/v1/info` 500 echoing the token | (not logged) | none |
| 413 | `too_large` with the byte cap | `HTTP 413, refetching image limit` |

- **Responses:** no response on any path carried the token, an upstream body or an upstream error message. `bad_response` reports only the status code.
- **Stored rows and files:** after all runs, `grep -r` over `DATA_DIR` (excluding the catalog database, which holds the token in `settings` by design), `ci4/writable/` and the server logs found the stored token **nowhere**. `autofill/info.json` holds the saved address, the parsed `/v1/info` and the clamp pair only.
- **Exceptions:** `suggestBytes()`/`testPhoto()` log only the exception **class**, and answer the fixed `UNAVAILABLE` text. CI4's own CRITICAL log for M-1 shows a stack trace of framework frames with no request body.
- **Typed token (test routes):** `test-photo` with a typed address and typed token `<typed fake token>`, against the echoing stub, logged `suggest: HTTP 500 (upstream failed for key <typed fake token> …)` and `HTTP 503 (key=<typed fake token>)`. The redactor knows only the **stored** token and `Bearer …` (L-1). Legacy `autofill_log_snippet()` has the same gap.
- **Stored token to a typed address:** not sent. With no typed token, the stub saw an empty `Authorization` (`key ` in the log). Pinned by `testStoredTokenIsSentOnlyToTheSavedAddress` (T1, T2).

**PASS** with L-1.

### 2.3 Caps and the 413 retry (item 3)

- **Order:** `enforced_image_bytes`, then `autofill_max_image_bytes` (`auto` → cached `max_image_bytes`, else 983040), then an explicit value clamped by the advertised max. The cap is the JPEG, not the multipart wrapper. Matches main's v2.6.1 behaviour and the CHANGELOG. Pinned by C2–C6 and C9.
- **Before sending:** `AnalysisCopyBuilder::open()` steps quality 80 → 70 → 60, then the long edge by 4/5 (at most 24 sides, never below 64), and stores the first copy at or under the cap. Over the cap after all steps → `too_large`, nothing sent. `suggestAt()` checks again before the POST (C1: redundant, harmless).
- **413 live (always-413 stub):** exactly **POST, GET `/v1/info`, POST**, then `200` JSON `too_large` "(max 0.9 MiB / 983040 bytes)". No third POST, no loop, no 500. `autofill/tmp/` was empty afterwards.
- **Upload cap:** an upload over `max_upload_mb` answers `too_large` before any decode (as legacy). Not pinned (C8).
- **Busy:** the suggest route doesn't sleep or retry on `Retry-After`; `test-photo` sleeps once (≤ 60 s and ≤ the timeout) and retries once, as legacy.
- **Decode path vs main and #72.** Direct calls of the analysis builder on the same files (peak RSS of the PHP process):

| Input | Head CI4 (`gd,imagick`) | main `1dbe986` legacy | #72 `a29e1ee` legacy |
|---|---|---|---|
| 800×600 JPEG | 59,326 B copy, 55 MB | same | same |
| 9000×7000 PNG (63 MP) | GD refuses (> 60 MP), **Imagick decodes the full frame**, then refuses: `null`, 0.77 s, **292 MB** | same, 295 MB | refused from the header: `null`, 0.05 s, 54 MB |
| 9000×7000 JPEG (63 MP) | full Imagick decode, then `null` → `unsupported`, 293 MB | same | decoded already reduced: 7,961 B copy, 0.19 s, 80 MB |

  - The port matches **main** as released (v2.6.3). It does **not** have #72's header precheck (JPEG > 250 MP or > 30,000 px; HEIC > 128 MP or > 16384 px → refused before decode), its HEIC ping, or its reduced JPEG decode. HEIC goes to `Imagick::readImageBlob()` with no header check (L-2).
  - #72 isn't merged yet, so this is a port follow-up, not a regression.

**PASS** with L-2.

### 2.4 Storage interface (item 4)

- **Keys:** only two, both built in code: `autofill/info.json` and `autofill/tmp/autofill-<16 hex>.jpg` (`random_bytes(8)`). No key comes from the request.
- **`LocalStorage` probes** (scratch root):

| Key | Result |
|---|---|
| `../x`, `a/../../x`, `/etc/passwd`, `a\..\x`, `a<NUL>b`, `""`, `.`, `..`, `a/..`, `./a`, `a/./b`, `a//b` | **refused** (`InvalidArgumentException` on write; `read`/`exists` false) |
| `%2e%2e/x` | written as a literal directory `%2e%2e` inside the root (no decoding) |
| `link/f.txt`, where `link` is a **pre-existing symlink** in the root to `/tmp` | written to `/tmp/f.txt` (N-2) |

- **`TempFile::release()`** deletes only `autofill/tmp/autofill-<16 hex>.jpg`. The analysis copy is released in `finally` on every path; a 413 rebuild releases the first copy (F4).
- **Stored original unchanged (live):** an item photo with a kept original (1200×900 PNG behind `originals/map/<name>`) and a served 800×600 JPEG. `POST /autofill/suggest` with `photo_id` sent a **1024×768** copy, so the original was analysed, not the served file. sha256 and mtime of the served JPEG, the original and the map file were identical before and after. `photo_id` values `9001x`, `-1`, `../9001` and an unknown id → `no_image`.
- **Writes outside the root:** none seen. Directories are created `0700`. The module never names `writable/` (`testAutofillSourcesDoNotNameWritable`).

**PASS** with N-2.

### 2.5 Cloudflare phase (item 5)

- **PR body:** "Cloudflare phase" section present. It matches the code.
- **Storage behind the interface:** the warn-once pair and the temporary JPEG go through `Storage`. Photo reads (`StoredPhoto`) and the sample photo still read local paths, which the PR body and PLAN mark as Media's job.
- **Per-request and static state:** none added. No `static $` and no static property in `ci4/modules/Autofill` or `Core/Storage`; the statics in the diff are `static fn` closures only.
  - `Services::autofill()` is shared but not in `WorkerMode::$persistentServices`, so CI4 drops it after each worker request. Its `LogRedactor` closure reads the token through `SettingsService` on every call.
  - The warn-once pair lives in the storage file, and `testWarnOnceIsStoredInTheFileAndNotOnTheInstance` pins it (I3 killed).
  - #74's L-1 gap now covers this factory too: a function-level `static` holding `SettingsService` in `autofill()` (W1), or a method-level `static` memoising the token in `AutofillService` (W2, shared across instances in a worker), passes every test (L-4).
- **DSN-driven DB:** `CatalogContext` and `StoredPhoto` use the injected `apothekeDb()` connection. Both statements are portable SQL; the photo test ran on SQLite and PostgreSQL.
- **Environment:** `AUTOFILL_UPLOAD_MAX_SIDE` and `AUTOFILL_UPLOAD_JPEG_QUALITY` are read with `getenv()` on each call (I-4).

**PASS** with L-4 (W1, W2).

### 2.6 Routes: auth, CSRF, SSRF and errors (item 6)

| Route | POST, no token | POST, valid `csrf` | GET |
|---|---|---|---|
| `/autofill/suggest` | **419** | 200 JSON | 404 |
| `/settings/autofill/token` | **419** | 422 JSON (empty token) / see M-1 | 404 |
| `/settings/autofill/test` | **419** | 200 JSON | 404 |
| `/settings/autofill/test-photo` | **419** | 200 JSON | 404 |

- **CSRF:** #74's global `sessioncsrf` filter applies to all four routes. No exemption was added.
- **Permission:** none. The routes carry no `perm:` filter, and CI4 has no SEC-M3 same-origin or Host check yet. Legacy requires `create` (suggest) and `settings` (the other three) when sign-in is on, and same-origin when it is off (L-3).
- **M-1 (token route, non-fetch):** `POST token=…` and `POST action=clear` answer **500 `Server error`**, `text/plain` body, with a stray `Location: /settings/autofill?done=token-saved`. The token **is** saved or cleared first. CI4's log shows `ErrorException: ini_set(): Session ini settings cannot be changed when a session is active` from `CodeIgniter::storePreviousURL()` → `session()` → `FileHandler::__construct()`. The 303 has no `Content-Type`, so CI4 treats it as HTML and tries to start its own session next to #74's native session. The fetch variant (`X-Requested-With: fetch`) returns `{"ok":true,"redirect":…}` correctly.
  - **Fix verified on `:9603`:** adding `Content-Type: text/plain; charset=UTF-8` to the 303 in `done()` gives `303` with `Location` for both save and clear. `redirect()->to($to, 303)` (a `RedirectResponse`, which CI4 skips) should work too.
- **SSRF:**
  - Suggest uses only the saved `autofill_url`. The request can't choose the host.
  - `test` and `test-photo` accept a typed `autofill_url` by design, as legacy does. Only `http`/`https` (`file://`, `gopher://` and `user:pw@host` → `invalid_url`). No redirects, `CURLOPT_PROTOCOLS` http/https, 3 s connect timeout, 256 KiB response cap.
  - Typed loopback addresses still give an oracle: an open HTTP port answers `bad_response` "HTTP 404", a closed or non-HTTP port `unreachable`. Legacy behaves the same, and the admin guide says the Settings permission must be trusted with it. Here, with no permission gate, any session holder can use it (L-3).
- **Upstream errors:** never returned to the client (2.2). The service `name` from `/v1/info` is shown after `Text::cleanLine()` (80 chars), as legacy.
- **Non-image upload** (`%PDF`): `unsupported`, nothing sent.

**FAIL** (M-1), with L-3.

### 2.7 Suites and static checks (item 7)

| Leg | Command | Result |
|---|---|---|
| ci4 @ 8.4.27, SQLite | `CI=true APOTHEKE_APP=ci4 APOTHEKE_TEST_DB=sqlite DB_DRIVER=sqlite php8.4 vendor/bin/phpunit` (PG data sets in `qa2_69_mod`) | **OK**, 479 tests, 12328 assertions, 5 skipped (PostgreSQL only ×3, php-cgi, `HttpSuiteTest`) |
| ci4 @ 8.4.27, PostgreSQL 17 | same with `pgsql`, `TEST_PG_DB=qa2_69_c84`, `TEST_PG_PASSWORD=<CI test password>` | **OK**, 479 tests, 12342 assertions, 2 skipped. `php scripts/ci4-pgsql-proof.php`: exit 0 |
| both legs | CLI migrations as in CI (`bin/migrate.php`, idempotent re-run, `bin/seed.php --demo` twice, `--status`) | OK |
| CI4 module suite | `php8.4 vendor/bin/phpunit -c ci4/phpunit.xml.dist` | **OK**, 90 tests, 613 assertions (SQLite); **106 tests, 815 assertions** with PostgreSQL. Matches the claim exactly |
| Autofill + Storage only | `--filter 'AutofillTest\|LocalStorageTest'` | OK, 23 tests, 191 assertions |
| PHPStan DI rule | `php scripts/ci4-arch.php di` | `[OK] No errors` |
| deptrac | `php scripts/ci4-arch.php deptrac` | 0 violations, 0 errors (Autofill → Core, Settings) |
| lint | `bash scripts/lint.sh` on 8.4 | exit 0 |
| CI4 dependencies | `composer audit --working-dir=ci4` | "No security vulnerability advisories found" |

- A first SQLite run was disturbed by QA itself (two legs briefly shared a PostgreSQL database, then a stub was killed mid-run). It was discarded, and the clean re-run above is the result.

**PASS**

### 2.8 Mutations (item 8)

Scratch copy only. Each mutation was reverted (`git checkout`, `git status` clean) before the next. Suite = `ci4/phpunit.xml.dist` on SQLite; survivors were re-run with PostgreSQL (106 tests).

| # | Mutation | Caught by | Result |
|---|---|---|---|
| R1 | redactor: exact-token replace removed | `testTokenInAResponseBodyIsRedacted` | **killed** |
| R2 | redactor: `Bearer \S+` rule removed | none | survives (L-4) |
| R3 | snippet cut to 80 chars **before** redacting | none | survives (L-4) |
| R4 | 5xx: upstream body returned as the client message | none | survives (L-4) |
| R5 | exception message logged and returned instead of the class | none | survives (L-4) |
| T1 | stored token sent to any typed test address | `testStoredTokenIsSentOnlyToTheSavedAddress` | **killed** |
| T2 | saved-address match by prefix, not normalised equality | same | **killed** |
| C1 | pre-send cap check in `suggestAt()` removed | none | survives, harmless: `open()` already enforces the cap (I-1) |
| C2 | exact fit refused (`<=` → `<`) | 4 tests (exact fit, 413, 60-first, JPEG-not-wrapper) | **killed** |
| C3 | cap measured on the multipart body | same 4 | **killed** |
| C4 | `enforced_image_bytes` ignored | 3 tests | **killed** |
| C5 | advertised max ignored for an explicit cap | `testExplicitCapClampsToAdvertisedMaxWhenEnforcedIsAbsent` | **killed** |
| C6 | quality 60 not tried | 2 tests | **killed** |
| C7 | GD 60 MP header check removed | none | survives (L-4) |
| C8 | upload `max_upload_mb` check removed | none | survives (L-4) |
| C9 | fallback 983040 → 10485760 | `testAutoUsesCachedMaxOrTheFallbackAndIgnoresTheEnvironment` | **killed** |
| F1 | 413: no retry | 2 tests | **killed** |
| F2 | 413: no `/v1/info` refetch | 2 tests | **killed** |
| F3 | 413: up to 3 retries | `testASecond413IsNotRetried` | **killed** |
| F4 | 413: first temp copy not released | `testHttp413RefetchesOnceAndRetriesAnExactFitJpeg` | **killed** |
| F5 | 413 mapped as `bad_response` | 2 tests | **killed** |
| S1 | `TempFile::release()` deletes any key | none | survives (L-4) |
| S2 | `release()` without the name check | none | survives (L-4) |
| S3 | temp copy never released | 4 tests | **killed** |
| S4 | `LocalStorage` allows `..` | `testKeysCannotLeaveTheRoot` | **killed** |
| S5 | `LocalStorage` allows absolute keys | none (`..` cases only) | survives (L-4) |
| S6 | served JPEG analysed instead of the kept original | none | survives (L-4) |
| S7 | stored photo overwritten | `testOriginalStaysByteIdenticalAndOnlyTheCopyIsSent` | **killed** |
| I1 | info cache returned for another address | none | survives (L-4) |
| I2 | `test()` caches `/v1/info` of a typed address | none | survives (L-4) |
| I3 | warn-once kept in a static, not the file | `testWarnOnceIsStoredInTheFileAndNotOnTheInstance` | **killed** |
| W1 | `autofill()` factory holds `SettingsService` in a function `static` | none | survives (L-4, as #74 L-1) |
| W2 | `AutofillService` memoises the token in a method `static` | none | survives (L-4) |
| H1 | client follows redirects | none | survives (L-4) |
| H2 | client 256 KiB response cap removed | none | survives (L-4) |

- **18 of 35 caught** at `4e3db09`; 17 survive, 16 test gaps plus the harmless C1. (The first issue of this doc said 19 of 35.) The 413 retry and the stored original are pinned well. 11 of the 14 cap and 413 mutants are killed (all 5 of the 413 mutants). The survivors are C1 (harmless), and C7 and C8, the GD 60 MP and upload checks. (The first issue said 15 of 16, which was a miscount.)
- Redaction is pinned only for the exact stored token (R1). Most other focus-area guards are correct in the code but have no test (L-4).

**PASS** with L-4.

### 2.9 Docs and CHANGELOG (item 9)

- **Commits:** 6 Conventional Commits (2.1).
- **CHANGELOG:** one bullet under `## [Unreleased]` → `### Added`, `**ci4:** Autofill client. … ([#69](https://github.com/thordin9/apotheke/pull/69))`. It is accurate about the cap order, the single 413 retry, the quality steps, the stored photo and Storage. Wording is feature-level, with no exploit detail. `lint.sh`'s link check passes.
- **PR body:** "Deviations from legacy" states that permission checks aren't applied. It says "same as legacy with sign-in off", but legacy with sign-in off now also has #70's same-origin check (L-3).
- **`docs/autofill.md` CI4 table:** the field names are wrong, and so is the clear instruction (N-1).
- **`docs/user-guide.md`:** "tries a smaller copy a single time". The retry is built to the refetched limit. If the service doesn't lower it, the same size is resent. Live: two 59,746 B POSTs (N-3).
- **AGENTS.md, README, `docs/ci.md`, `docs/contributing.md`, PLAN, `docs/configuration.md`, admin guide:** consistent with the code and `deptrac.yaml`. The PLAN status note says plainly that the live Neuron check wasn't run.
- **Placeholders:** docs use `https://autofill.example`. Tests use `http://autofill.test`, `127.0.0.1` and the test token `sekret-token-xyz`.

**PASS** with N-1, N-3.

### 2.10 Security

| Check | Result |
|---|---|
| **Secrets** | gitleaks 8.30.1 with the repo `.gitleaks.toml`: the 31 changed files at `4e3db09` have **no leaks**. The `4e3db09` tree has 1 repo hit, the known `MigrateDsnTest` fake (`SecretMissingEq99`), unchanged from the base. (The other hits were in locally installed `vendor/phpstan.phar`, not in the repo.) A git-history scan wasn't possible on this box. CI's history scan ("Secret scan") is green |
| **Token handling** | Read only through `SettingsService`. Sent as `Authorization: Bearer` only to the saved address, or a typed token to a typed address. Never in a response, `info.json` or an exception message. Log lines are redacted for the stored token and `Bearer …`, but not for a typed token (L-1) |
| **Outbound requests** | http/https only, no redirects, 3 s connect timeout, request timeout from settings (3–60 s), response capped at 256 KiB. Suggest goes only to the saved address. The test routes go to a typed address, as legacy (L-3) |
| **Error responses** | Fixed messages and codes. No upstream body or error text reaches the client. The token route's 500 (M-1) is CI4's generic `Server error` |
| **Images** | Only a stripped, resized JPEG is sent. The stored original is never written. Decode limits match main, not #72 (L-2) |
| **Storage** | Constant keys, traversal refused, `0700` directories. Symlinks inside the root are followed (N-2) |
| **Access control** | Session CSRF on all four routes. No permission or same-origin gate yet (L-3) |
| **Dependencies** | `composer audit` (ci4): no advisories |

**Nothing High.** The access-control and decode items are graded Low because CI4 isn't in the shipped Docker image yet, and both are tracked port work. They must be closed before CI4 serves real traffic.

### 2.11 Secrets in this doc

No real secret appears here. The CI test password is written as `<CI test password>`. QA fake tokens are written only as placeholders (`<stored fake token>`, `<typed fake token>`), and no session token is reproduced.

## 3. Findings

| Id | Sev | Status at `614cb1b` | Finding | Suggestion |
|---|---|---|---|---|
| M-1 | Medium | **Fixed** (`1a5636a`) | `POST /settings/autofill/token` without a fetch header (save **and** `action=clear`) always answers **500 `Server error`**, after the write has happened, instead of the documented 303. The 303 has no `Content-Type`, so CI4's `storePreviousURL()` treats it as HTML and builds its own session handler while #74's native session is active (`ini_set()` `ErrorException`). No test covers the non-fetch path. | Set `Content-Type: text/plain; charset=UTF-8` on the 303 in `done()` (verified: 303 for save and clear), or return `redirect()->to($to, 303)`. Add an HTTP test for both form posts expecting 303 and `Location`. |
| L-1 | Low | **Fixed** (`1a5636a`) | A **typed** test token is not redacted from log snippets. `test-photo` with a typed address and token logged it in full when the service echoed it in a 500 or 503 body. `LogRedactor` knows only the stored token and `Bearer …`. Legacy `autofill_log_snippet()` has the same gap. | Pass the request's token into `outcome()`'s snippets (for example `snippet($body, $token)`), and test it with a typed token. Worth back-porting to main as a fix. |
| L-2 | Low | Open, deferred until #72 is on `feature/ci4` (PR body says so) | The decode path matches main but not #72's hardening. Imagick decodes the full frame before its 60 MP check (63 MP PNG: 292 MB, 0.77 s vs 54 MB, 0.05 s on #72). HEIC has no header ping or 128 MP / 16384 px precheck. A JPEG over 60 MP is refused instead of decoded reduced. | When #72 merges, port its header precheck (JPEG 250 MP / 30,000 px, HEIC 128 MP / 16384 px), the HEIC ping and the reduced JPEG decode into `AnalysisCopyBuilder`. Track it in PLAN. |
| L-3 | Low | Open, CSRF only; Host and same-origin via #76, permission via the CI4 login PR (PR body says so) | The four routes have CSRF but **no permission and no same-origin/Host gate**. Any session holder can replace or clear the service token, and use the test routes to make the server contact any http(s) host (open-port oracle: "HTTP 404" vs `unreachable`). Legacy requires `settings` (`create` for suggest) with sign-in on, and #70's same-origin check with it off. CI4 isn't in the Docker image, so this can't be reached in a release today. | Before CI4 serves traffic: either fail closed now (403 on these routes while `SettingsService::authEnabled()` is true), or have Coder2's `ci4/auth-2` add `perm:settings` / `perm:create` and the M3 same-origin filter to these routes. Fix the PR body's "same as legacy with sign-in off". |
| L-4 | Low | Partly fixed: R4 now caught; 16 of 35 still survive, 15 gaps plus C1 (5.4) | At `4e3db09`, 17 of 35 mutations survived: 16 gaps plus C1. Most focus-area guards are right in the code but have no test: `Bearer` redaction and redact-before-truncate (R2, R3), upstream body or exception text reaching the client (R4, R5), the GD 60 MP and upload checks (C7, C8), `TempFile` key checks (S1, S2), absolute keys (S5), original-over-served (S6), info-cache scoping (I1, I2), no redirects and the response cap (H1, H2), and #74's L-1 gap extended to the `autofill()` factory and a method-level static token memo (W1, W2). | Add focused tests: a 5xx stub body containing a marker that must not appear in the JSON; a token placed across the 80-char boundary; `release('images/x.jpg')` leaves the file; `/abs` key refused; a kept original with different dimensions; a typed-address test leaves `info.json` alone; a 302 stub is not followed. Fold W1 and W2 into Coder2's L-1 fix (`getStaticVariables()` over the factory and module classes). |
| N-1 | Nit | **Fixed** (`614cb1b`) | The `docs/autofill.md` CI4 table names the fields `url`, `timeout`, `token`, `model` and `size`. The code reads `autofill_url`, `autofill_timeout`, `autofill_token`, `autofill_model` and `autofill_image_size`. "`token` empty to clear" answers 422; clearing needs `action=clear`. | Correct the table. |
| N-2 | Nit | **Fixed** (`70e5e88`) | `LocalStorage` follows a symlink that already exists inside the root (a write through `link/…` landed outside the root). Writes aren't atomic (a concurrent reader of `info.json` gets `[]`, which is safe). Keys are constants today. | Before Media adopts the interface for user-derived keys: check `realpath(dirname())` stays under the root, and write through a temp file plus `rename()`. |
| N-3 | Nit | Open (wording still says "smaller copy", 5.5) | The user guide says a 413 "tries a smaller copy a single time". The retry is rebuilt to the refetched limit, so an unchanged limit resends the same size (live: 59,746 B twice). Legacy does the same. | Wording: "rebuilds the copy to the service's current limit and tries once more". |
| I-1 | Info | Unchanged | C1 survives because `open()` already guarantees the copy is under the cap. `suggestAt()`'s check is defence in depth. | None. |
| I-2 | Info | Unchanged | `test-photo` may `sleep()` up to 60 s on a busy answer, holding a worker, as legacy does. | Revisit for Workers. |
| I-3 | Info | Unchanged | `AUTOFILL_UPLOAD_*` are read with `getenv()` on each call: no state, but on Workers they'd come from bindings. | Note for the Cloudflare phase. |
| I-4 | Info | Unchanged | gitleaks' only repo hit is the known `MigrateDsnTest` fake, unchanged from the base. | None. |

No High findings. One Medium at `4e3db09` (M-1), fixed at `614cb1b`. I-5 (new) is in section 5.

## 4. Verdict

**PASS at `614cb1bce2691ea5ed5a2da28e2e2be26c5ca6af`** (re-check, section 5). No High or Medium remains.

First review: **FAIL at `4e3db098168b518d04bfa8b6ae2f0847bb2538b1`** because of M-1. That review's summary follows.

- **CI:** run 40086963840, 22/22 `success`. 6 Conventional Commit subjects. The `d38e9f9` merge is clean.
- **Claims checked:** `Filters.php`, `App.php` and `SettingsService` are untouched. `Services.php` adds only `autofill()`. 90 / 106 module tests. deptrac and the DI rule are clean.
- **Redaction:** the stored token never appeared in a response, a file or a log line across the 500, 503, non-JSON, 413, timeout, oversize-body and redirect paths. A typed test token can still reach the log (L-1).
- **Caps and 413:** enforced before sending. One refetch and one retry, no loop, no 500. 11 of 14 cap and 413 mutants are caught, all 5 for the 413 retry.
- **Storage and originals:** traversal is refused, and the kept original is analysed and left byte-identical.
- **Cloudflare phase:** no statics, the shared service is dropped per worker request, and the DB is DSN-driven. The static-holding gap is the same one as #74's L-1 (L-4).
- **Suites:** ci4 @ 8.4 is green on SQLite and PostgreSQL 17 under `CI=true`. Lint, the DI rule, deptrac and `composer audit` pass.
- **To pass:** fix M-1 (one line plus an HTTP test). L-1 and the R4/S6 tests from L-4 are recommended in the same push. L-2 and L-3 can be tracked to #72's port and Coder2's `ci4/auth-2`, but must land before CI4 serves real traffic.

## 5. Re-check at `614cb1b`

Delta only, `4e3db09..614cb1b`, 14:41–14:55 (Athens time, UTC+3). Same environment as 1.1: PHP 8.4.27, PostgreSQL 17.11 in private `qa2_69_*` databases, ports 9601–9602, and the same hostile QA stub.

### 5.1 CI, commits and files

- **Run 40090776197** (`614cb1b`, `pull_request`): **22/22 `success`**, 14:39–14:50 Athens time (UTC+3). Polled through the API every 2 minutes. The head was `614cb1b` at the start and at the end.
- **Commits** (3, linear on `4e3db09`, 0 behind `feature/ci4`). All pass `scripts/check-commits.sh --message`:
  - `1a5636a` fix(autofill): redirect token forms and redact typed tokens (59 characters)
  - `70e5e88` fix(storage): refuse symlinks and write files atomically (56)
  - `614cb1b` docs(autofill): name the form fields and the single 413 retry (61)
- **Files** (10, +273 / −31):
  - Runtime: `SettingsAutofillController.php`, `LogRedactor.php`, `ResponseClassifier.php` (four `snippet()` calls), `Core/Storage/LocalStorage.php`.
  - Tests: `AutofillTest.php` (+148), `LocalStorageTest.php` (+50).
  - Tooling: `ci4/tools/PhpStan/bootstrap.php`, which adds analysis-only stubs for `helper()` and `redirect()`.
  - Docs: `docs/autofill.md`, `docs/user-guide.md`, `CHANGELOG.md`.
  - `Filters.php`, `App.php`, `SettingsService` and `Services.php` are not touched.

### 5.2 M-1: plain-form token save and clear

Live on `:9601` (head), session cookie plus the session CSRF field, no fetch header:

| Request | Before | Result | After |
|---|---|---|---|
| `token=<fake>` | `autofill_token` = `""` | **303**, `Location: http://127.0.0.1:9601/settings/autofill?done=token-saved`, `Cache-Control: no-store` | token stored (the fake value) |
| `action=clear` | fake value | **303**, `…?done=token-cleared` | `""` |
| same with `X-Requested-With: fetch` | | 200 `{"ok":true,"redirect":"/settings/autofill?done=token-cleared"}` | |

- No 500 anywhere. CI4's `writable/logs` has no log file and PHP's `error_log` has no `ini_set` line. `redirect()->to($to, 303)` returns a `RedirectResponse`, which CI4's `storePreviousURL()` skips.
- **Revert check:** with the old `done()` restored (N1), `testPlainFormSavesAndClearsTheTokenWith303` fails (error) on SQLite. The test posts save and clear, expects 303 with `done=token-saved` / `token-cleared` and `no-store`, checks the token isn't echoed in the body, and checks that the token was stored and then cleared.

**Fixed.** See I-5 on the absolute `Location`.

### 5.3 L-1: typed test token in log lines

`test-photo` with a typed address on the hostile stub and a typed token, then a suggest with the stored token:

| Stub answer to `/v1/suggest` | Log line |
|---|---|
| 500 `text/plain`, body echoes the typed token | `suggest: HTTP 500 (upstream failed for key [redacted] at /internal/path)` |
| 503 non-API body echoing it | `suggest: HTTP 503 (key=[redacted])` |
| 200 non-JSON body echoing it | `suggest: unexpected body (not json [redacted])` |
| 502 body with `Bearer <typed>` | `suggest: HTTP 502 (auth was Bearer [redacted]` |
| 500 echoing the **stored** token (saved address) | `… key [redacted] …` |

- The typed token occurs 0 times in the log, and so does the stored one.
- `snippet($body, $token)` now redacts the request's token as well as the stored one, in all four `outcome()` snippet calls. Pinned by `testTypedPhotoTokenIsRedactedFromServerErrorLogs`: N2 (the extra token ignored), N3 (the 5xx call) and N4 (the 503 call) are each caught.

**Fixed.**

### 5.4 L-4: mutations

The 35 mutations from 2.8 were re-run on `614cb1b`, with anchors moved to the new `redact()`. Then 12 more target the new code. Suite: the ci4 module suite on SQLite (now 96 tests). Survivors were re-run with PostgreSQL (112 tests). `git status` was clean after each.

- **Original 35: 19 caught** (18 at `4e3db09`). R4 (upstream 5xx body returned to the client) is now caught by `testTypedPhotoTokenIsRedactedFromServerErrorLogs`. Two new 413 tests also catch F1, F4 and F5 on more paths.
- **Still surviving (16):** R2, R3, R5, C1 (harmless), C7, C8, S1, S2, S5, S6, I1, I2, W1, W2, H1, H2. As before, the code is correct in each case, and these are test gaps.
- **New code (12): 7 caught.**

| # | Mutation | Result |
|---|---|---|
| N1 | M-1 revert (old `done()`) | **killed** (`testPlainFormSavesAndClearsTheTokenWith303`) |
| N2 | snippet ignores the request's token | **killed** |
| N3 | 5xx snippet without the request's token | **killed** |
| N4 | 503 snippet without the request's token | **killed** |
| N5 | symlink check removed | **killed** (`testASymlinkIsNotFollowed`) |
| N6 | symlink checked on the last component only | **killed** (`testASymlinkIsNotFollowed`, directory link) |
| N7 | second key check in `write()` after `mkdir` removed | survives: defence in depth against a link created between checks |
| N8 | direct `file_put_contents` instead of temp + `rename` | **killed** (`testRewriteReplacesAReadOnlyFileWithoutATempLeftBehind`) |
| N9 | temp file in the system temp dir | survives: no test pins "same directory" |
| N10 | temp not removed when `rename` fails | survives |
| N11 | `fopen` `x` → `w` | survives |
| N12 | temp not removed when a write fails | survives |

- **Total: 26 of 47 caught.**
- The fixes themselves (M-1, L-1 and the symlink refusal) are fully pinned. The temp-file placement and failure clean-up (N9–N12) are correct in the code (5.5) but untested. They stay under L-4.

### 5.5 Nits and storage

- **N-1, fixed:** the `docs/autofill.md` table now names `autofill_url`, `autofill_timeout`, `autofill_token`, `autofill_model` and `autofill_image_size`. It says `action=clear` clears the token, an empty `token` is 422, and gives the two `done=` targets. This matches the controller.
- **N-3, still open (Nit):** the user guide now says "fetches the limit once more and sends one smaller copy. It does not keep retrying." The single retry is now right. "Smaller" still isn't guaranteed: the retry is rebuilt to the refetched limit, and the rebuild code didn't change in this push, so an unchanged limit resends the same size (2.3: 59,746 B twice). Suggest "sends one copy built to that limit".
- **N-2, fixed.** `LocalStorage` probes on a scratch root:

| Key | Result |
|---|---|
| `link/x.txt`, `link/t.txt` (`link` → a directory outside the root) | refused (`InvalidArgumentException`); `read`/`exists` false |
| `sub/flink.txt` (a file symlink to outside) | refused on write; `delete()` leaves both the link and its target untouched |
| `inlink/ok.txt` (`inlink` → a directory **inside** the root) | refused too (every component is checked with `is_link()`) |
| `sub/ok.txt`, `../x`, `/etc/x` | written / refused / refused, as before |

  - **Atomic write:**
    - The temp file is `<dir>/.<name>.tmp-<16 hex>`, in the target's own directory, opened with `x` (exclusive create), then `rename()`d over the target.
    - A read-only target file was replaced (`v1` → `v2`).
    - A write into a read-only directory throws `RuntimeException` "Could not store the file." and leaves nothing behind.
    - A failed `rename` (target is a directory) throws, and its temp file was removed.
    - No `.tmp-` file remained in any case.
  - The storage root itself may be a symlink (only components below the root are checked). That's fine for a `DATA_DIR` that is a link.
- **Docs and PR body for L-2 and L-3:** the PR body ("Deviations") says the four POST routes stay CSRF-only, that Host and same-origin checks arrive with #76 and the permission check with the CI4 login PR, and that #72's header pre-check is deferred until it is on `feature/ci4`. `docs/autofill.md` says the same. Both stay **Low** and don't block this PR.

### 5.6 Suites, static checks, CHANGELOG and secrets

| Check | Result |
|---|---|
| ci4 @ 8.4.27, SQLite (`qa2_69_mod` for PG data sets) | **OK**, 479 tests, 12328 assertions, 5 skipped; CLI migrations OK |
| ci4 @ 8.4.27, PostgreSQL 17 (`qa2_69_c84`, `TEST_PG_PASSWORD=<CI test password>`) | **OK**, 479 tests, 12342 assertions, 2 skipped; `ci4-pgsql-proof.php` exit 0; CLI migrations OK |
| CI4 module suite | **OK**, 96 tests, 655 assertions (SQLite); 112 tests, 857 assertions with PostgreSQL |
| PHPStan DI rule | `[OK] No errors` |
| deptrac | 0 violations, 0 errors |
| `lint.sh` (8.4) | exit 0 |
| gitleaks 8.30.1 on the 10 changed files | **no leaks**. The test literals are placeholders (`form-token-placeholder`, `csrf-test-token`) |

- **CHANGELOG:** one new bullet under `## [Unreleased]` → `### Added`, linked to [#69](https://github.com/thordin9/apotheke/pull/69). It covers the 303 for plain-form save and clear, the typed token removed from the 500 and 503 log lines, the symlink refusal, and temp-file-plus-rename. The wording is hardening only, with no exploit detail. It sits under Added rather than Fixed, which suits an unreleased feature.

### 5.7 New note

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| I-5 | Info | The 303 `Location` is now absolute, built from CI4's `baseURL`, which #74's `Config\App` takes from `PUBLIC_URL` or the request's Host. A request with another Host header gets that host in `Location`. Legacy sends a relative `Location`. A cross-site page can't set the victim's Host header, and #76's Host allow-list will bound it. | Optional: once #76 lands, keep a test that `Location` stays on an allowed host, or send the relative path, as legacy does. |

### 5.8 Re-check verdict

**PASS at `614cb1bce2691ea5ed5a2da28e2e2be26c5ca6af`.**

- **Fixed:** M-1, L-1, N-1 and N-2. Each was checked live, and the M-1, L-1 and symlink fixes are pinned by tests that fail when the fix is reverted.
- **Open, non-blocking:**
  - L-2: #72's header pre-check, deferred until #72 is on `feature/ci4`.
  - L-3: CSRF only; Host and same-origin come via #76, permissions via the CI4 login PR.
  - L-4: 16 of the original 35 mutations plus N7 and N9–N12 survive. All are test gaps.
  - N-3: "smaller copy" wording.
  - I-5: absolute `Location`.
- **CI:** 22/22. Local ci4 legs on SQLite and PostgreSQL 17, the module suite, the DI rule, deptrac, lint and gitleaks are all green.
