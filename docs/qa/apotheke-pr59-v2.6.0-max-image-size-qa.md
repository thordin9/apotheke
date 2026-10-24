# QA: apotheke PR #59, configurable maximum image size for autofill (v2.6.0)

- **PR:** [#59](https://github.com/thordin9/apotheke/pull/59) into `main`, by Coder. Title: `feat(autofill): add a configurable maximum image size`. Open, not a draft, mergeable state `clean`.
- **Head reviewed:** `8d5686ca2b1a16a6bf4e159b4955fe545d20b5bc` (full review and live check). Base `main` at `7d32e3d` (the v2.5.8 release commit). 10 commits, 50 files, +761/-74.
- **Final head (delta recheck):** `2bb1b1d86b89cc6ab87988139487631bc45a56d6`, 5 plain commits on top of `8d5686c` (`414fca9`, `27a3fb6`, `a57c1b5`, `56cd5f3`, `2bb1b1d`). 15 commits, 50 files against `main`. CI run 40011796059: **success**, 10/10.
- **Commits:** `446b8e4` feat(autofill), `d1d1d47` test(e2e) baselines, `631e220` merge of `main`, `9709ce1` fix(release) body (#58 L-1), `5c631eb` fix(release) blank lines (#58 N-1), `efd2eb9` docs(db) 500 while down (#58 L-2), `ef53021` + `49e9fd8` fix(bootstrap) stderr (#58 N-2), `7c4a48c` docs(changelog) duplicates (#58 N-3), `8d5686c` docs(qa) v2.5.8 release doc.
- **Diff against `main`:** code `src/autofill.php`, `src/settings.php`, `src/settings_views.php`, `src/bootstrap.php`, `scripts/release.php`, `public/index.php` (comment only), new `migrations/0026_autofill_max_image_bytes.php`; tests `AutofillTest`, `DbConnectErrorTest`, `ReleaseTest`, `tests/integration.php`, `tests/stubs/autofill-stub.php`; docs `CHANGELOG.md`, `README.md`, `docs/autofill.md`, `docs/autofill-admin-guide.md`, `docs/configuration.md`, `docs/control-panel.md`, `docs/deployment-and-operations.md`, `docs/releasing.md`, `docs/user-guide.md`, the v2.5.8 QA doc; 28 PNG baselines.
- **CI:** run 39964543959 (`pull_request`, attempt 1) on `8d5686c`: **success**, 10/10 jobs (Lint, Conventional Commits, Secret scan, Tests sqlite, Tests pgsql, Docker build + smoke, Headless mobile, Visual E2E shards 1–3), 16:50 to 17:01 EEST.
- **Verdict (final, at `2bb1b1d`): PASS.** M-1, L-1, L-2 and N-2 are fixed and verified with stubs, tests and mutants (see *Delta recheck at `2bb1b1d`*). There are no open high, medium or low findings. N-1 and N-3 to N-6 are nits, deferred to the agreed follow-up PR. No photos were sent in the recheck.
- **Verdict at `8d5686c` (superseded): CHANGES REQUESTED.** One medium: an explicit size is not clamped to `max_image_bytes` when the service has no `enforced_image_bytes`, so the agreed fallback clamp is missing (M-1, small fix). Everything else passes, including the live check against the paid Worker (5 photos, 29.48 Neurons). Two lows and six nits are follow-ups.

## Test plan

1. **Scope.** Diff the head tree against `main` (codeload tarballs), list commits and CI jobs, and check the merge `631e220` adds nothing beyond the listed commits.
2. **Setting and validation.** `setting_validate()` on 23 strings and 7 non-strings (edges 65535/65536/10485760/10485761, empty, blank, `auto`/`Auto`, non-numeric, negative, `1.5`, `1e6`, `0x10000`, `+65536`, leading zeros, full-width digits, 9 digits). The same edges through the real Control Panel form over `php -S` (HTTP status, error text, stored value, re-shown value). Settings export/import with a bad and a good value. No environment override (`AUTOFILL_MAX_IMAGE_BYTES` set).
3. **Migration 0026.** `bin/migrate.php` from a v2.5.8 database to the head on SQLite and on PostgreSQL 17 (throwaway, loopback): row value, a saved explicit value kept across a second migrate, a fresh install, `bin/settings.php get/set`.
4. **Clamp, fallback, 413, hard max (stubs).** A QA tier stub behind a QA recording proxy on loopback that logs every request (method, path, status, image-part bytes and dimensions, body bytes; never the `Authorization` header). Scenarios: always-413 with and without cached info; free tier (`max 524288`, `enforced 983040`) with explicit 2097152 and `auto`, primed and not; `/v1/info` returning 503; no service at all; a legacy service with `max_image_bytes` only; `enforced_image_bytes` 1000 (can never fit); explicit 65536 on a 1024² and a 3200×2400 random-noise JPEG; a paid-tier rehearsal of the live steps.
5. **Original stays byte-identical.** sha256 of every stored original and served copy before and after each run (stub and live), and the temp directory left empty.
6. **Unit suites.** Full unit suite on PHP 8.4.27 with SQLite and with PostgreSQL 17. 13 mutants against the new code and tests.
7. **#58 follow-ups.** L-1: a throwaway git repo (no remotes): the `7d32e3d` tree tagged `v2.5.8`, then the head tree as one `feat(autofill):` commit; `scripts/release.php plan` and `apply --notes`. N-1: `ReleaseTest`. N-2: `migrate`, `seed`, `settings list` and `/healthz` with an unwritable data directory. N-3 and L-2: CHANGELOG and docs read-through, the new front-controller test.
8. **#58 leak tests still hold.** The #58 canary connect-failure smoke on the head: 6 failure shapes × 2 ini modes × 5 CLI scripts against PostgreSQL 17 with a QA-only role (real `password authentication failed` from libpq). The web routes with the DB down, and the second "error page" log line Coder mentioned.
9. **Visual baselines.** Which PNGs changed, their dimensions, and one crop by eye.
10. **Lint, secrets, commits, hash.** `php -l` and CR check on the 12 changed PHP files; commit subjects against `scripts/check-commits.sh`; gitleaks 8.x on the PR diff (default rules and the repo `.gitleaks.toml`); a grep for hostnames, IPs, tokens and Worker URLs; `cmp` of the committed v2.5.8 QA doc.
11. **Live check** against the paid Worker (below), at most 5 photos.

## Results

### 1. Scope: pass

The head-vs-`main` diff contains only the feature, the #58 follow-ups, the baselines and the QA doc. No unrelated files. The merge `631e220` brings in `main` (v2.5.8) with no extra edits.

### 2. Setting and validation: pass, one nit

- `autofill_max_image_bytes` is in the `settings` table only (schema type `af_bytes`, default `auto`). It is in the autofill section of the generic form, exported and imported, and validated again on read (an invalid row falls back to `auto`). With `AUTOFILL_MAX_IMAGE_BYTES=65536` in the environment the value stays `auto` and the limit stays 983040.
- `setting_validate()`: 65536, 10485760, `00065536` and ` 524288 ` are accepted (as integers). 65535, 10485761, `0`, `-1`, `-65536`, `1.5`, `1e6`, `0x10000`, `+65536`, `abc`, `12ab`, full-width digits, `999999999`, `[]`, `true` and `1.5` (float) are rejected with `Maximum image size (bytes): auto, or a whole number of bytes from 65536 to 10485760.` `auto`, `Auto`, empty and blank all give `auto` (N-2).
- **Form over `php -S`:** 65535, 10485761, `abc`, `-5` and `1.5` return 422 with that message under the field (`aria-invalid="true"`, the typed value re-shown) and nothing stored. 65536, 10485760, 2097152, `auto` and empty return 303 `?done=saved` and are stored.
- **Import:** `"99"` is skipped with the same message (58 of 59 applied); `2097152` is applied.

### 3. Migration 0026: pass (SQLite and PostgreSQL)

| Step | SQLite | PostgreSQL 17 |
| --- | --- | --- |
| v2.5.8 `migrate` | `Done: 25 step(s).` | same |
| head `migrate` | `applied 0026 autofill_max_image_bytes`, `Done: 1 step(s).` | same |
| `settings get` | `"auto"` (row value `"auto"`, JSON like every other setting) | same |
| `set 524288`, `set 65535` | stored / rejected, exit 1 | same |
| second `migrate` | `Already up to date.`, value still 524288 | same |
| fresh install | `Done: 26 step(s).`, `"auto"` | same |

The migration is plain SQL with a literal (`SELECT 1 … WHERE name = …`, then one `INSERT`), with no driver-specific syntax and no schema change.

### 4. Clamp, fallback, 413 and hard max (stubs): pass except M-1

Every row is counted by the recording proxy (requests the app actually sent).

| Scenario | Setting | Requests seen | Result |
| --- | --- | --- | --- |
| Always 413, info cached (`max 1048576`, `enforced 2031616`) | 2097152 | POST 413, GET info, POST 413 | too large "max 1.9 MiB / 2031616 bytes"; **exactly 2 POSTs, 1 refetch** |
| Always 413, no cache | auto | POST 413, GET info, POST 413 | 2 POSTs, 1 info fetch, stops |
| Free tier (`max 524288`, `enforced 983040`), cached | 2097152 | POST 200 | target **983040**, warning `image limit clamped from 2097152 to 983040 bytes (enforced_image_bytes)` |
| Free tier, cached / not cached | auto | POST 200 (then GET info when not cached) | target **524288** |
| `/v1/info` answers 503, no cache | auto | POST 200, GET info 503 | target **983040** (fallback) |
| `/v1/info` 200 but suggest always 413 | auto | POST 413, GET info, POST 413 | 2 POSTs |
| No service (connection refused) | auto | – | `unavailable`, fallback 983040 used for the build |
| Legacy service, `max 1048576`, no `enforced`, accepts ≤ 1 MiB, q90 | 2097152 | POST 413 (1,968,295 B), GET info, POST 413 (same 1,968,295 B) | **fails**: "max 2 MiB / 2097152 bytes" (M-1) |
| Same, `auto` | auto | POST 200 (580,512 B) | target 1048576 |
| `enforced_image_bytes` 1000 | auto | none | `too_large` "max 0 MiB / 1000 bytes", **nothing sent** (N-6) |
| 1024² noise, explicit 65536 | 65536 | POST 200 | 50,539 B at 419×419 (body 50,860 ≤ 65536) |
| 3200×2400 noise (14.4 MB), explicit 65536 | 65536 | POST 200 | 52,153 B at 655×491, built in 2.1 s |
| Paid rehearsal: 1024×830 noise, q95, no cache | 2097152 | POST 413 (2,061,315 B), GET info, POST 200 (471,743 B) | the exact live P5 sequence |

- The warning has the two sizes only. The unit tests assert it never contains the token or `Bearer`, and mutant A13 (token appended) is killed.
- No info cache plus a 413 never leads to more than one refetch. `autofill_post_analysis()` has no loop, and mutant A3 (up to 3 retries) is killed by the always-413 test.
- **M-1:** `autofill_target_image_bytes()` clamps only to `enforced_image_bytes`. When `/v1/info` has `max_image_bytes` but no `enforced_image_bytes` (the Jetson service and any v1.x service before the Worker), an explicit value above `max_image_bytes` is sent unclamped. The 413 refetch then rebuilds against the same cap, so every Analyze makes 2 POSTs, both 413, and the message names the admin's value instead of the service's limit. The agreed spec says to clamp to `enforced_image_bytes` when present, else to `max_image_bytes`. The docs describe the code ("An explicit value is used as stored"), so code and docs both deviate.

### 5. Original stays byte-identical: pass

In every stub and live run the source file, the stored original under `originals/` and the served copy under `images/` have the same sha256 before and after. The temp directory is empty afterwards. Only the analysis JPEG reaches the service: its sha256 never equals the original's, and in the live check all 6 images posted were 1024 px on the long edge, while the originals were 1024–3900 px.

### 6. Unit suites and mutants: pass, one gap

- SQLite, PHP 8.4.27: **OK, 356 tests, 2396 assertions, 23 skipped** (no Imagick or zbar, pgsql-only tests).
- PostgreSQL 17: **OK, 356 tests, 2424 assertions, 13 skipped**. Two `PgsqlSchemaCreateTest` cases need a role that can create roles and `SET ROLE`. They passed once the QA role was a superuser, as in CI.

| Mutant | Killed by |
| --- | --- |
| A1 ignore `enforced_image_bytes` | `testExplicitTargetClamps…`, `testHttp413…` |
| A2 no retry after 413 | `testTooLargeMessage…`, `testHttp413…` |
| A3 retry up to 3 times | `testTooLargeMessage…` (exactly 2 POSTs) |
| A4 no long-edge step-down | `testNoisyPhotoHardMax…` |
| A5 / A6 off-by-one at 65536 / 10485760 | `testMaxImageBytesRejects…` |
| A7 explicit setting ignored | 3 tests |
| A8 front controller logs the whole exception | `testFrontControllerConnectFailure…` (#58 M3 now killed) |
| A9 `apply` writes the notes from the generated section again | **survives** (L-1) |
| A10 migration overwrites a saved value | killed |
| A11 bootstrap message back on stdout | `testDataDirectoryNotWritableExits1` |
| A12 fit measured on the JPEG instead of the multipart body | survives (harmless, N-5) |
| A13 token in the clamp warning | 2 tests |

### 7. #58 follow-ups: pass, with L-1 and L-2

- **L-1 (`9709ce1`):** what-if in a throwaway repo gives `current=2.5.8 bump=minor next=2.6.0`. `apply` leaves `## [Unreleased]` empty, and the notes file now holds the whole 2.6.0 section (generated and hand-written `### Added`, the hand-written `### Fixed` db line) plus `**Full changelog:** …/compare/v2.5.8...v2.6.0`. This is correct end to end. The new test, however, calls `rel_release_notes()` directly, so reverting the `apply` wiring still passes (A9, L-1 below).
- **N-1 (`5c631eb`):** blank lines inside notes, paragraphs and fenced code are kept; only the edges are trimmed. Tested.
- **L-2 (`efd2eb9`):** CHANGELOG Unreleased `### Fixed` and both docs now say every route returns 500 while the DB is down. `testFrontControllerConnectFailureLogsOnlyTheSanitizedLine` requests `/login` with a refused DB and asserts the exact two log lines.
- **N-2 (`ef53021`, `49e9fd8`):** `migrate`, `seed` and `settings list` with an unwritable data dir exit 1. stdout is empty, and stderr is `Data directory …/data is not writable.` plus `\n`. Under `php -S`, `/healthz` and `/` return 500 with the same text in the body, because the non-CLI branch echoes and no longer uses `STDERR`.
- **N-3 (`7c4a48c`):** the terse `docker:` (2.5.7) and `db:` (2.5.8) lines are gone, their PR links moved onto the hand-written lines, and a test pins it. v2.6.0 will repeat the pairing for `autofill:` (N-1 below).

### 8. #58 leak tests on the head: pass

- **CLI: 50 of 60 runs clean** (exit 1, one sanitized stderr line, and none of `canaryuser`, `canarypw`, `password authentication`, `PDOException`, `Stack trace`, `SQLSTATE` in stdout, stderr or the log). The 10 others are the unresolvable-host shape, which hits the harness's 30 s cap because the box's resolver times out. Rerun without the cap, `seed.php` exits 1 after 30.0 s with `Could not connect to the database (pgsql – host=nohost.invalid port=5432 dbname=keepdb).` and no canary. This is the box's environment, as in #58.
- **Web, DB down** (wrong password, refused port, DSN with user and password; `ignore_args` 0 and 1): `/login`, `/theme.css`, `/manifest.webmanifest`, `/healthz`, `/`, `/api/items` and an unknown path all return 500, and the canary grep over log, server log and bodies finds 0 hits.
- **The second line:** HTML requests log `[apotheke] Could not connect to the database (pgsql – host=127.0.0.1 port=55433 dbname=keepdb).` followed by `[apotheke] error page: Could not connect …` with the same sanitized text. The second line comes from `view_error()` in `src/views.php`, which this PR does not change. v2.5.8 logs the same pair. The PR adds the test that pins it. It has no user, password, DSN or token.

### 9. Visual baselines: pass

Exactly 28 PNGs changed, all autofill-settings shots: the five `autofill-settings.spec.ts` shots on chromium-pixel, desktop-chromium, webkit-iphone-se and webkit-iphone13 (20), and `visual-settings.spec.ts/settings-autofill.png` on those four projects and their dark variants (8). Each keeps its width and grows by 178–219 px, the height of the new field. Nothing else under `__screenshots__` changed (512 files on both sides), which is consistent with the 21 renderer-noise PNGs having been left out. The desktop crop shows the field, its help text and *Default: auto* between Timeout and Save. All three Visual E2E shards are green.

### 10. Lint, secrets, commits, hash: pass

- `php -l` is clean on the 12 changed PHP files, and none has a CR.
- The 9 non-merge subjects match `check-commits.sh`'s regex, at 42–58 characters each.
- gitleaks on the PR diff, with default rules and with the repo config: **no leaks**. The grep finds no hostnames, IPs other than loopback, tokens or Worker URLs; the docs contain no service address. The test fixture token `sekret-token-xyz` is synthetic.
- `docs/qa/apotheke-v2.5.8-release-qa.md` at the head is 10,486 B, sha256 `f3d5b7ea12e4d5d5454ebe838b78e7c88a7dc09824c90a54b316cf8cac0e2d07`, **byte-identical** to the QA original.

## Live check (paid Worker)

Run by QA on 2026-10-23, 20:18–20:21 EEST, against `https://autofill.example` (the paid Worker). The head's code ran under `php -S` on loopback with a SQLite data dir in a QA temp dir. The app's service address was a loopback recording proxy that forwarded to the Worker and logged sizes only.

The token was read at runtime into a mode-600 header file (used only for `curl -H @file`) and into the temp app database (mode 600) by a PHP script, so it never appeared on a command line. Both files were shredded afterwards. A scan of every QA file found the token in no log, response or proxy record. It was not rotated.

**`/v1/info` (before):** `tier` `paid`, `max_image_bytes` 1048576, `enforced_image_bytes` 2031616, no `image_sizes` (so the long edge is 1024 px), Neurons used 94.673 of 30000, `requests.used` 16.

**Probes (0 Neurons):** a POST with no token returned 401, and `text/plain` returned 415 `unsupported_media_type`. The 413 came from the app itself in P5.

| # | Setting (info cache) | Photo (original) | Requests the app sent | Analysis copy sent (image part / body) | Target | Neurons |
| --- | --- | --- | --- | --- | --- | --- |
| – | Control Panel save, `auto` | – | GET `/v1/info` 200 | – | auto → **1048576** | 0 |
| P1 | `auto` (cached) | single-board computer, 3,041,375 B, 3900×2605 | POST 200 | 199,553 / 199,874 B, 1024×684, q80 | 1048576 | 6.782 |
| P3 | 524288 (cached) | cordless drill, 1,001,245 B, 1920×1440 | POST 200 | 166,738 / 167,059 B, 1024×768, q80 | 524288 | 5.964 |
| P4 | 524288 (cached) | random noise, 2,536,131 B, 1024×1020 | POST 200 | **495,550 / 495,871 B**, 1024×1020, q60 (q80 700,331 and q70 580,071 did not fit) | 524288 | 5.518 |
| P2 | 2097152 (cached), `AUTOFILL_UPLOAD_JPEG_QUALITY=90` | random noise, 2,537,100 B, 1024×1024 | POST 200 | **1,968,295 / 1,968,616 B**, 1024×1024, q90 | **2031616**, warning `image limit clamped from 2097152 to 2031616 bytes (enforced_image_bytes)` | 5.518 |
| P5 | 2097152, cache deleted, q95 | random noise, 2,061,220 B, 1024×830 | POST **413** `image exceeds 2031616 bytes` (2,061,315 B), GET `/v1/info` 200, POST 200 | 1st 2,061,315 / 2,061,636 B (built for 2097152); retry **471,743 / 472,064 B** q70 | 2097152, then 2031616 after the refetch (with the warning) | 5.700 (the 413 used 0) |

- **Photos: 5 of 5 used** (hard cap reached; nothing else touched the AI). **Neurons: 29.482 in total, 5.90 per photo** (5.52–6.78), against an estimate of about 6. `requests.used` went from 16 to 21, one per photo. The two `/v1/info` calls, the probes and the 413 used 0.
- Every analysis copy that reached the AI was at or under its target. The Worker accepted the 1,968,295 B copy under the 2031616 clamp.
- `auto` resolved to 1048576 from `/v1/info`. Explicit 2 MiB clamped to 2031616 with a warning that has only the two sizes. Explicit 524288 worked, including a hard-to-compress image that had to step down. One live 413 led to exactly one refetch and one retry.
- All 5 originals and served copies were byte-identical afterwards (sha256), and the temp dir was empty.
- **Suggestions:** single-board computer, Electronics, brand Raspberry Pi; cordless drill, Tools, BOSCH (both correct). The three noise images came back as `Of multicolored digital noise` / General with name confidence 0.5 or 0, which is what noise should get. The odd "Of …" name is the Worker's prompt, not this PR.
- The free-tier clamp and the unreachable-info fallback were checked with stubs only (section 4), as agreed.

## Delta recheck at `2bb1b1d`

Run by QA on 2026-10-24, 00:05–00:16 EEST, against head `2bb1b1d86b89cc6ab87988139487631bc45a56d6`, with stubs and unit tests only. There were no Worker calls and no photos went to any real service, because the 5-photo budget was used up at `8d5686c`. The Worker token was not read.

### Plan

1. **Scope.** Compare `8d5686c...2bb1b1d` (unauthenticated GitHub API, codeload tarballs of both trees): commits, parents, files per commit, and `diff -r` of the two trees. CI on the head.
2. **M-1.** Read the new cap order in `autofill_target_image_bytes()`. Rerun the section 4 stub harness on the head: a QA tier stub behind the QA recording proxy on loopback, `php -S`, SQLite in a QA `mktemp -d` dir, PHP 8.4.27. Scenarios: Jetson-style info (`max 1048576`, no `enforced`), paid-style (`enforced 2031616`), free-style (`enforced 983040`), `auto`, `/v1/info` 503, always-413. Run the Jetson case on `8d5686c` too, for contrast. Check the original's sha256 before and after each run. Mutation-check the new test.
3. **L-1.** Mutant A9 again (`apply` writes the notes from the generated section only) against the new `ReleaseTest`.
4. **L-2.** The CHANGELOG Unreleased links, and a what-if `release.php apply` in a throwaway git repo with no remotes and a no-op `gh`.
5. **N-2 and help text.** Read the docs and settings diff. Render Control Panel → AI autofill over `php -S` and read the help text. Rerun `setting_validate()` on the section 2 inputs (head against `8d5686c`) and the real form with the edge values.
6. **Baselines.** Which PNGs changed in `2bb1b1d`, their dimensions against `8d5686c`, where the pixels differ, and one crop by eye.
7. **Suites, lint, secrets.** The full unit suite on SQLite and on a throwaway PostgreSQL 17 (loopback, stopped afterwards). `php -l` and a CR check. Commit subjects against `check-commits.sh`. gitleaks on the delta, with the default rules and with the repo config, plus a grep for URLs, IPs and tokens.

### Results

#### D1. Scope: pass

- The compare shows `ahead_by 5`, `behind_by 0` and merge base `8d5686c`, so the push was plain and `8d5686c` is an ancestor (no force-push). The commits form a linear chain, each with one parent:

| Commit | Subject | Files |
| --- | --- | --- |
| `414fca9` | fix(autofill): cap an explicit size to the service limit | `src/autofill.php`, `src/settings.php` (help text), `tests/Unit/AutofillTest.php`, `tests/stubs/autofill-stub.php`, `CHANGELOG.md`, `README.md`, `docs/autofill.md`, `docs/autofill-admin-guide.md`, `docs/configuration.md`, `docs/control-panel.md`, `docs/user-guide.md` |
| `27a3fb6` | test(release): run apply and check the notes file | `tests/Unit/ReleaseTest.php` |
| `a57c1b5` | docs(changelog): link the image-size note to #59 | `CHANGELOG.md` |
| `56cd5f3` | docs(autofill): say a blank image size saves as auto | `docs/autofill.md`, `docs/autofill-admin-guide.md`, `docs/configuration.md`, `docs/user-guide.md` |
| `2bb1b1d` | test(e2e): update autofill settings baselines | 28 PNGs only |

- In total there are 40 files, +127/−22 in text. `diff -r` of the two trees lists exactly those 40 files. Every commit touches only what its subject says. `414fca9` also updates the README sentence and the settings help text for the new cap order. That belongs with M-1.
- **CI:** run 40011796059 (`pull_request`, attempt 1) on `2bb1b1d` is **success**, 10/10 jobs: Lint, Conventional Commits, Secret scan, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Docker build + smoke test, Headless mobile checks, and Visual E2E shards 1/3, 2/3 and 3/3. It ran 22:29–22:40 EEST on 2026-10-23. The PR is open, not a draft, mergeable state `clean`, with 15 commits and 50 files against `main` `7d32e3d`.

#### D2. M-1 cap order: fixed

- `autofill_target_image_bytes()` sets `$cap = enforced_image_bytes`. When that is null and the setting is not `auto`, it uses `$cap = max_image_bytes` (new `autofill_advertised_image_bytes()`, which reads the same fresh-then-known info cache). It clamps when `$cap < $target` and logs `image limit clamped from <target> to <cap> bytes (<field>)`. With neither field known, the explicit value is used as stored. `auto` is unchanged: it already follows `max_image_bytes`, then the enforced clamp. This matches the agreed spec: enforced, then `max_image_bytes`, then as stored.
- **Stub harness on the head.** Every row is counted by the recording proxy. The image is the 1024×1024 random-noise JPEG from section 4 (2,537,100 B). The quality is 90 unless noted.

| Scenario | Setting | Requests seen | Result |
| --- | --- | --- | --- |
| **Jetson-style** (`max 1048576`, no `enforced`, accepts ≤ 1 MiB), primed | 2097152 | GET info, **POST 200** | **exactly 1 POST**, image 580,512 B / body 580,833 B ≤ 1048576, **no 413**; limit 1048576; warning `image limit clamped from 2097152 to 1048576 bytes (max_image_bytes)` |
| Same, quality 80 | 2097152 | GET info, POST 200 | 1 POST, 700,597 B; same warning |
| Same on `8d5686c` (contrast) | 2097152 | GET info, POST 413 (1,968,295 B), GET info, POST 413 (1,968,295 B) | fails "max 2 MiB / 2097152 bytes" (the M-1 bug, reproduced) |
| Jetson-style, no cache | 2097152 | POST 413 (1,968,295 B, built as stored because nothing is known), GET info, POST 200 (580,512 B) | the refetch now clamps to `max_image_bytes`, so the one retry succeeds |
| Jetson-style, primed | auto | GET info, POST 200 | limit 1048576, no warning (unchanged) |
| Jetson-style, primed | 524288 | GET info, POST 200 (496,016 B) | 524288, no clamp upward |
| **Paid-style** (`max 1048576`, `enforced 2031616`), primed | 2097152 | GET info, POST 200 (1,968,295 B) | **2031616**, warning `… to 2031616 bytes (enforced_image_bytes)` (unchanged) |
| Paid-style, primed | 1500000 | GET info, POST 200 | 1500000: `enforced` is present, so `max_image_bytes` is not used (as specified) |
| Paid-style, primed | auto | GET info, POST 200 | 1048576 (unchanged) |
| **Free-style** (`max 524288`, `enforced 983040`), primed | 2097152 | GET info, POST 200 | **983040**, warning `(enforced_image_bytes)` (unchanged) |
| Free-style, primed / no cache | auto | POST 200 (+ GET info when not cached) | 524288 (unchanged) |
| `/v1/info` 503, no cache | auto | POST 200, GET info 503 | **983040** fallback (unchanged) |
| `/v1/info` 503, no cache | 2097152 | POST 200 (1,968,295 B), GET info 503 | 2097152, used as stored because neither field is known |
| **Always 413**, paid info cached | 2097152 | GET info, POST 413, GET info, POST 413 | **exactly 2 POSTs, 1 refetch**, "max 1.9 MiB / 2031616 bytes" |
| Always 413, Jetson info cached | 2097152 | GET info, POST 413, GET info, POST 413 | 2 POSTs, 1 refetch, "max 1 MiB / 1048576 bytes": the message now names the service's limit |
| Always 413, no cache | auto | POST 413, GET info, POST 413 | 2 POSTs, 1 refetch |

- **Original byte-identical:** the source image's sha256 was the same before and after all 17 runs (`166c1152…324a`). The harness's `orig_same` was true each time, and `DATA_DIR/tmp` was empty after each run.
- **New test** `testExplicitTargetClampsToMaxImageBytesWhenEnforcedIsAbsent`: stub `/b/1048576` (the new stub option, `max_image_bytes` only, larger suggests get 413), setting 2097152, quality 95. It asserts the target is 1048576, exactly 1 suggest, an upload ≤ 1048576, no `http_413`, and a warning that has `max_image_bytes` and both sizes but neither the token nor `Bearer`. It passes on the head.
- **Mutants** (run against `AutofillTest`):

| Mutant | Result |
| --- | --- |
| M1a: remove the `max_image_bytes` fallback (`if (false)`) | **killed** by the new test (`Failed asserting that 2097152 is identical to 1048576`) |
| M1b: prefer `max_image_bytes` over `enforced_image_bytes` | killed by 3 tests (`…ClampsToEnforcedImageBytesAndWarns`, the new test, `testHttp413…`) |
| M1c: clamp only when the field is `enforced_image_bytes` | killed by the new test |

#### D3. L-1 release wiring test: fixed

`testApplyWritesTheFoldedSectionIntoTheNotesFile` copies `scripts/release.php` into a temp git repo with a hand-written Unreleased `### Fixed` line, a `fix(db):` commit and a `gh` shim. It runs `release.php apply --notes=…` and asserts the notes have the generated line, the hand-written line and the compare link, and no older section. Mutant A9 (`apply` writes the notes from the generated `$section` only) is now **killed**: `Failed asserting that '### Fixed … - **db:** generated only … **Full changelog:** …' contains "- kept the handwritten line"`. The test removes its temp dir in `finally`.

#### D4. L-2 CHANGELOG link: fixed

The Unreleased section's only PR link is now `([#59](https://github.com/thordin9/apotheke/pull/59))`. The 7 other `pull/37` links are older, correct entries for the Imagick PR. In a throwaway what-if repo (the `8d5686c` tree tagged `v2.5.8`, then the head tree as a `feat(autofill):` commit, no remotes, a no-op `gh`), `release.php plan` gives `bump=minor next=2.6.0`. `apply` leaves Unreleased empty, and the notes carry the hand-written line with `#59`. N-1, the generated `add a configurable maximum image size` line next to the hand-written one, is still there, as expected for a deferred nit.

#### D5. N-2 and help text: pass

- The admin guide, `docs/autofill.md` and `docs/configuration.md` now say "An empty value is saved as `auto`". The user guide says leaving the field empty saves `auto`. The admin guide's "Anything else is rejected" is now "Anything outside that range is rejected". **N-2 is fixed.**
- All six docs, the README, the CHANGELOG line and the settings help text describe the cap order the same way, and they match the code: `enforced_image_bytes` when present, otherwise `max_image_bytes`, as stored only when neither is known, and a lower cap logs a warning without the token. The old wording ("An explicit value is used as stored") is gone.
- **UI over `php -S`:** the help under the field reads: *Largest JPEG sent to the service. auto uses the service’s limit, or 983040 when the service has not said. A number from 65536 to 10485760 is capped to the service’s enforced limit, otherwise to the limit it advertises, and used as entered only when the service has not said. The stored photo is not changed.* The input still has `placeholder="auto"` and `maxlength="10"`. The help text does not spell out "empty means auto", but the placeholder shows it, and the docs say it.
- **Bounds unchanged:** `setting_validate()` gives identical output on head and `8d5686c` for all 30 inputs from section 2. In the real form, 65535, 10485761, `abc`, `-5` and `1.5` return 422 with *auto, or a whole number of bytes from 65536 to 10485760* under the field (`aria-invalid`), and the stored value is unchanged. 65536, 10485760, 2097152, `auto` and empty return 303 `?done=saved`, and empty is stored as `"auto"`.

#### D6. Baselines: pass

- `2bb1b1d` changes exactly 28 PNGs and nothing else: the five `autofill-settings.spec.ts` shots and `visual-settings.spec.ts/settings-autofill.png` on chromium-pixel, desktop-chromium, webkit-iphone-se and webkit-iphone13 (24), plus the four dark `settings-autofill.png` (4). This is the same set of 28 as `d1d1d47`. There are 512 PNGs on both sides, so none were added or removed.
- Every width is unchanged. The heights grow by **+39/+40 px on the three phone projects** and **+19/+20 px on desktop-chromium**, which is two extra help-text lines on a phone and one on desktop. That matches the ~39 px the PM reported. The pixel rows above the field are identical, and the first differing row is at the help text (row 868 desktop, 986 iPhone 13, 945 Pixel). The rows below are identical again after the shift. The desktop crop shows the five-line help text, then *Default: auto*, Save, and Test connection / Test with a photo, with no other change.
- All three Visual E2E shards are green on `2bb1b1d` (run 40011796059).

#### D7. Suites, lint, secrets: pass

- **SQLite**, PHP 8.4.27: **OK, 359 tests, 2423 assertions, 23 skipped**. That is exactly 2 more tests than `8d5686c` (`--list-tests` diff: the two new tests).
- **PostgreSQL 17** (throwaway cluster on loopback in the QA dir, stopped afterwards): **OK, 359 tests, 2451 assertions, 13 skipped**.
- `php -l` is clean on the 5 changed PHP files, and none has a CR. All 5 subjects match `check-commits.sh` (45–56 characters).
- gitleaks (`detect`) on the delta, with the default rules and with the repo `.gitleaks.toml`: **no leaks**. The added lines have no IPs, no hostnames other than `github.com` (the repo and the synthetic `o/r` test repo) and no Worker URL. The only token is the synthetic `sekret-token-xyz` test fixture. The git identity in the new test is the synthetic `t@t`.

## Findings

| ID | Severity | Status (at `2bb1b1d`) | Summary |
| --- | --- | --- | --- |
| M-1 | Medium | **fixed** in `414fca9` (D2) | Explicit size not clamped to `max_image_bytes` when `enforced_image_bytes` is absent |
| L-1 | Low | **fixed** in `27a3fb6` (D3) | `release.php apply` → notes wiring is not tested (mutant A9 survives) |
| L-2 | Low | **fixed** in `a57c1b5` (D4) | The new CHANGELOG `autofill:` line links PR #37 instead of #59 |
| N-1 | Nit | deferred to the follow-up PR | v2.6.0 will carry a generated and a hand-written `autofill:` line for the same change |
| N-2 | Nit | **fixed** in `414fca9` / `56cd5f3` (D5) | Empty input silently saves `auto`; the admin guide says anything else is rejected |
| N-3 | Nit | deferred to the follow-up PR | The clamp warning is logged on every photo (twice on a 413 retry) |
| N-4 | Nit | deferred to the follow-up PR | Quality steps from the configured value straight to 70, so a high configured quality can leave a copy far under target |
| N-5 | Nit | deferred to the follow-up PR | The fit is measured on the multipart body, not the image part (conservative by about 320 B) |
| N-6 | Nit | deferred to the follow-up PR | A very small limit prints as `0 MiB` in the too-large message |

### Medium

- **M-1 (fixed in `414fca9`, verified in D2): an explicit size is not clamped to `max_image_bytes` when the service sends no `enforced_image_bytes`.** The agreed spec clamps to `enforced_image_bytes` when present, else to `max_image_bytes`. `autofill_target_image_bytes()` implements only the first half. On a service with `max_image_bytes` 1048576 and no `enforced_image_bytes` (the Jetson service, any pre-Worker v1.x service), Maximum image size 2097152 sends a 1,968,295 B copy. It gets a 413, refetches `/v1/info`, rebuilds against the same 2097152, gets a second 413, and shows "the photo is too large (max 2 MiB / 2097152 bytes)", naming the admin's value instead of the service's limit. Every Analyze fails until the admin goes back to `auto`. The paid and free Workers are not affected because they always send `enforced_image_bytes`. Suggested fix: take `$cap = enforced_image_bytes ?? max_image_bytes` from the cached info and clamp when `$cap < $target`, with the same warning. Add a test with a stub that advertises only `max_image_bytes`, and change "An explicit value is used as stored" in `docs/autofill.md` and the admin guide's clamp paragraph.

### Low

- **L-1 (fixed in `27a3fb6`, verified in D3): the release body wiring has no test.** `rel_release_notes()` is tested on its own, but replacing the `apply` call with the old `$section`-only notes (mutant A9) keeps all of `ReleaseTest` green, which is how the #58 L-1 gap came about. QA's what-if confirms the current code is correct. Suggested fix: a test that runs `apply` in a temp git repo (or a small `rel_apply()` helper that returns the changelog and notes) and asserts a hand-written line is in the notes file.
- **L-2 (fixed in `a57c1b5`, verified in D4): wrong PR link in the CHANGELOG.** The hand-written `### Added` line ends with `([#37](https://github.com/thordin9/apotheke/pull/37))`. #37 is the Imagick colour PR; this is #59. The line will go into the v2.6.0 section and release body as it is. Suggested fix: change it to #59 before merging.

### Nit

- **N-1:** the what-if 2.6.0 section has both `- **autofill:** add a configurable maximum image size` (generated) and the long hand-written `autofill:` line. This is the pairing #58 N-3 removed by hand for 2.5.7 and 2.5.8, and it will come back on every release with hand-written notes until the generator drops a generated line whose scope already has a hand-written one.
- **N-2 (fixed in `414fca9` / `56cd5f3`, D5):** empty and blank input save as `auto` (and `null` from an import becomes `auto`). That is sensible, but the admin guide says "Anything else is rejected when you save". One clause ("empty means auto") would make it exact.
- **N-3:** with an explicit value above `enforced_image_bytes`, `image limit clamped from … to …` is logged on every Analyze, and twice when a 413 retry rebuilds. Logging once per `/v1/info` refresh would keep the log quieter.
- **N-4:** the quality ladder is the configured quality, then 70, then 60. With `AUTOFILL_UPLOAD_JPEG_QUALITY=95` a noise copy of 2,537,151 B over a 2031616 target drops straight to 580,512 B (q70), although q90 (1,968,295 B) fits. At the default 80 the gap is small. An intermediate step (for example 85 and 75) would only matter for high configured qualities.
- **N-5:** the copy must fit with the whole multipart body (about 320 B of boundaries and context), while the Worker limits the image part. That is safe, and the image is at most about 320 B smaller than it could be. Mutant A12 (measure the JPEG) survives for the same reason.
- **N-6:** a tiny cap from a service, such as `enforced_image_bytes` 1000, gives "max 0 MiB / 1000 bytes". It is cosmetic and only reachable from a misconfigured service.

### Info

- **I-1:** the "error page:" log line is not new in #59. v2.5.8 already logs it from `view_error()`, and #59 adds the test that pins both lines. Both are sanitized.
- **I-2:** the unresolvable-host CLI case takes 30 s on the box's resolver (same as #58), and it exits 1 cleanly when not capped.
- **I-3:** P2 and P5 used `AUTOFILL_UPLOAD_JPEG_QUALITY` (90/95) to make copies near the 2 MiB targets. This is a supported environment setting, not the default.

## Not covered

- The Docker image, Playwright and headless-mobile jobs were not rerun locally; CI 39964543959 is green for all of them. There is no Docker on the box.
- Imagick is not installed on the box, so the hard-max and step-down runs used GD. The unit test runs both engines where available, and CI's Docker smoke covers the image.
- The paid Worker's daily cap and its 503s were not exercised (5-photo budget).
- **Delta recheck:** no live check at `2bb1b1d` (the photo budget was used up), so the M-1 fix is verified against stubs and the paid and free tiers against their stub shapes only. The paid-tier code path the live check exercised (`enforced_image_bytes` present) is unchanged in behaviour (D2). Docker, Playwright and headless-mobile were not rerun locally; CI 40011796059 is green for all of them.
