# QA: apotheke #79, refuse oversized PNGs and clean up failed uploads

- **PR:** [#79](https://github.com/thordin9/apotheke/pull/79), `cursor/png-upload-hardening-40df` into `main`, by Coder
- **Head reviewed:** `f3a534bad58d5395ab9ac7df16e5218092bd830c` (`docs(qa): add the v2.6.5 release QA report`) in full. During the review the PR moved to `bf951c350159de7595fa1e764f0d6ecd6c3c0c4c`, which merges `main` v2.6.7 and adds the v2.6.6 and v2.6.7 release QA docs. That delta is a diff read (2.12).
- **Base:** `main` at `d9eb204` (`chore(release): v2.6.6`), the second parent of the merge `3263de3`
- **Scope:** a full review of the whole PR against `main`. It covers #72's QA L-1, N-4 and N-5 and #77's QA L-1 and N-2. The main merges `b56dc47` and `3263de3` and the CHANGELOG fix `3afb3ed` are a diff read only.
- **Verdict: PASS.** 0 High, 0 Medium, 1 Low, 3 Nits, 7 Info. Nothing blocks. The Low is not a regression.

## 1. Test plan

1. Read-only `git clone` into a temporary directory, `refs/pull/79/head`, `main`. Diff review, commit subjects, `scripts/check-commits.sh`, `scripts/check-links.mjs`.
2. Merge check: each main merge compared with `git merge-tree` of its two parents. Changed lines of the PR's own work compared before and after the v2.6.6 merge.
3. CHANGELOG gate (PM rule) at the head against `d9eb204`. Hash check of the v2.6.5 release QA doc.
4. PHPUnit, the full suite including `HttpSuiteTest`, on PHP 8.3 and 8.4, on SQLite and PostgreSQL 17.
5. Real HTTP runs on the image's PHP and GD: each photo goes through `/autofill/suggest` (picked photo), `/save`, and `/autofill/suggest` again by `photo_id`, on a fresh `php -S` with `memory_limit=256M` and the autofill stub from `tests/lib.php`. The harness records status, error text, files kept, a SHA-256 of the kept original, what the stub received (type, size, dimensions, SHA-256) and the server's peak RSS.
6. Memory probe of `image_store()` under `memory_limit=256M` with a 20 MB string held, at the head and with the cap raised, to find the real failure point and check the fatal clean-up.
7. A `cli-server` probe of `actor_is_admin()` with sign-in and guest browsing on.
8. Mutations of the key fixes.
9. CI run 40093044754, checked through the GitHub API.

**Environment.** The box has no Docker.
- **Image stack (HTTP runs and memory):** private copies of the Alpine rootfs from `php:8.4.27-fpm-alpine` and `php:8.3.36-fpm-alpine`, used through a chroot. They have the image's bundled GD ("bundled (2.1.0 compatible)", JPEG/PNG/WebP/GIF) and no Imagick, so JPEGs over 40 MP are out of scope there.
- **Suites and mutations:** Debian PHP 8.4 and 8.3 with system libgd 2.3.3 and ImageMagick 7.1.1-43 with HEIC. System libgd allocates outside `memory_limit`, so memory figures come only from the Alpine stack.
- **PostgreSQL:** 17.11 on the box, a throwaway role and database. The role had `CREATEROLE` with `createrole_self_grant = 'set, inherit'` so the tests' own `nocreate_*` roles could be created and dropped. Everything was dropped afterwards.
- **Not repeated:** the Docker build, the smoke test and Playwright. CI covers them (2.1).

## 2. Results

### 2.1 CI

Run 40093044754 (`pull_request`, head `f3a534b`): `completed` / `success`, 10/10 jobs `success`. The jobs are Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Visual E2E 1/3, 2/3 and 3/3, Docker build + smoke test, Conventional Commits, and Secret scan. The run started at 15:13:29 and ended at 15:22:41 (Athens, UTC+3).

### 2.2 Diff and commits

15 files, +562/−58 against `main`: `src/autofill.php`, `src/image-color.php`, `src/items.php`, `src/users.php`, `tests/Unit/ImageDecodeTest.php` (+4 tests), `tests/Unit/UsersTest.php` (+1 test, +1 assertion block), `tests/integration.php` (+3 checks), `tests/integration_autofill.php` (+5 checks), README, 4 docs, CHANGELOG and the v2.6.5 release QA doc.

| Commit | Length | Subject |
|---|---|---|
| `2c6e9a5` | 62 | `fix(images): refuse oversized PNGs and clean up failed uploads` |
| `8d14dd0` | 48 | `docs(changelog): record the PNG upload hardening` |
| `b56dc47` | — | Merge of `origin/main` (`4dd79fe`, #77) |
| `e5257eb` | 52 | `test(auth): pin web guest admin and CLI password off` |
| `3263de3` | — | Merge of `origin/main` (`d9eb204`, v2.6.6) |
| `3afb3ed` | 48 | `docs(changelog): keep the #79 note out of v2.6.6` |
| `f3a534b` | 42 | `docs(qa): add the v2.6.5 release QA report` |

All subjects are Conventional Commits and 72 characters or shorter. `scripts/check-commits.sh d9eb204 f3a534b` passes (5 checked, merges skipped). There are no rewrites. `check-links`: 201 relative links in 16 files, 0 broken.

**The changes.**
- **PNG cap (#72 L-1):** `IMAGE_PNG_MAX_PIXELS = 26_000_000`. `image_raster_header_over_limit()` returns `px > 26,000,000` for PNG, so the upload and the suggest header check (`image_assert_bytes_within_limits()`) both refuse from the header with `Image dimensions are too large.` (422). `autofill_analysis_gd()`, `autofill_analysis_imagick()` and `autofill_prepare_image()` repeat the check (I-2). WebP and GIF keep the 40 MP GD budget.
- **Clean-up (#72 L-1):** `image_store()` arms a per-request list right after `image_original_save()`. It holds the resolved original path, the map entry, and the served and thumbnail paths for the new random name. A `finally` deletes and disarms on any exception, success disarms before returning, and a shutdown function registered once per process unlinks whatever is still armed. So a fatal error (which skips `finally`) still removes the files.
- **Suggest 422 at decode (#72 N-4):** `autofill_analysis_imagick()` rethrows `UserError` and turns a failure that `imagick_failure_is_too_large()` recognises into `UserError('Image dimensions are too large.', 422)`. `autofill_suggest_path()` rethrows `UserError` instead of answering "unavailable". The route already maps `UserError` to 422. The other caller, the Control Panel's test-photo path, catches `Throwable`, so it can't 500.
- **Docblocks (#72 N-5):** `autofill_analysis_jpeg()`, `autofill_suggest()` and `autofill_suggest_path()` now say they throw `UserError` (N-3 for the two that don't).
- **Auth (#77 L-1, N-2):** `actor_is_admin(?string $sapi = null)` with `$sapi ??= PHP_SAPI`. Every caller passes no argument, so behaviour is unchanged. New `testAnonymousGuestIsNotAdminOnAWebSapi`, an `auth_turn_off()` assertion with guest browsing on, and an HTTP-suite check that `password --off` works with sign-in and guest browsing on.

### 2.3 Merges and the CHANGELOG gate

| Check | Result |
|---|---|
| `3263de3` has no code conflicts | **Yes.** `git merge-tree` of `e5257eb` and `d9eb204` is clean and gives exactly the committed tree (`6492249e…`). |
| `b56dc47` | The only conflict was `CHANGELOG.md`, resolved by hand. Every other file equals the clean merge. |
| PR's changed lines unchanged by the merges | **Yes.** The `+`/`−` lines of `4dd79fe..e5257eb` and `d9eb204..f3a534b` are identical for every file except CHANGELOG and `docs/qa/`. v2.6.6 itself only touched CHANGELOG and VERSION. |
| Head contains main's latest release commit `d9eb204` | **Yes**, the second parent of `3263de3`. |
| `## [2.6.6]` to the end of the file (38,866 B, with all older sections and link refs) vs main | **Byte-identical**, both sides SHA-256 `e959c5c6…429171` |
| Everything above `## [Unreleased]` | Identical to main |
| The #79 bullet | One `### Fixed` bullet, only under `[Unreleased]`, linked to #79. `3afb3ed` moves it out of `[2.6.6]`, where the merge had left it. |
| Link refs | `[Unreleased]: …/compare/v2.6.6...HEAD`, the same as main |

**Gate: PASS.**

### 2.4 v2.6.5 release QA doc

`f3a534b` is a `docs(qa):` commit that adds only `docs/qa/apotheke-v2.6.5-release-qa.md` (145 lines). The committed file is 7,644 B with SHA-256 `ce177de4b2f863b9634148343b83519eb8e17dbd574d696fcad17d3abc09637f`. That is byte-identical (`cmp`) to my copy. #79's own doc (this one) goes after it as the last commit.

### 2.5 Test suites (PHPUnit, 426 tests each)

| Leg | Result |
|---|---|
| PHP 8.4, SQLite | 426 tests, 3,139 assertions, OK, 13 skipped |
| PHP 8.4, PostgreSQL 17 | 426 tests, 3,167 assertions, OK, 3 skipped |
| PHP 8.3, SQLite | 426 tests, 3,139 assertions, OK, 13 skipped |
| PHP 8.3, PostgreSQL 17 | 426 tests, 3,167 assertions, OK, 3 skipped |

- That matches Coder's 426 on each driver. `HttpSuiteTest` (the full `tests/integration.php` with the autofill and hardening parts) passes on all four legs.
- **Skips** are environment-only: no `php-cgi`, and `testHeicDecoderMemoryLimitIsTheDimensionError`, whose decoder doesn't report a limit on this libheif. The SQLite legs also skip the PostgreSQL-only tests.
- **First run on PostgreSQL:** 2 errors in `PgsqlSchemaCreateTest` and an `HttpSuiteTest` abort at `test_pg_role_without_create()`, because the throwaway role had no `CREATEROLE` (and then no `SET ROLE` on the roles it made). Both legs were green after the grant above. This is environment-only. CI's role has the rights.
- All 4 new `ImageDecodeTest` tests ran and passed on Debian. `testSuggestHeicDecodeResourceLimitIsTheDimensionError` was not skipped. See N-1 for what it actually covers.

### 2.6 Over HTTP on the image's PHP and GD (`memory_limit=256M`)

"Kept" means `/save` answered 200, `originals/` holds exactly one file, and its SHA-256 equals the upload's. "Sent" is what the stub received on both suggest calls, the picked photo and `photo_id`, which were identical.

| Photo | Header | Suggest | `/save` | Sent to the stub | Peak RSS 8.4 / 8.3 |
|---|---|---|---|---|---|
| PNG RGBA, 26,000,000 px exactly | 5000×5200 | 200 ok | 200, kept | JPEG 985×1024, 23 KB | 230 / 228 MB |
| Same, 18.7 MB file (upload held on suggest) | 5000×5200 | 200 ok | 200, kept | JPEG 985×1024, 56 KB | 264 / 262 MB |
| PNG RGBA, rotated | 5200×5000 | 200 ok | 200, kept | JPEG 1024×985 | 246 / 244 MB |
| PNG RGB, 26 MP | 5000×5200 | 200 ok | 200, kept | JPEG 985×1024 | 204 MB (8.4) |
| PNG RGBA 16-bit / 8-bit interlaced / 16-bit interlaced, 26 MP | 5000×5200 | 200 ok | 200, kept | JPEG 985×1024 | 229 MB (8.4) |
| PNG 26,005,000 px / 26,000,000 + 5,200 px | 5000×5201 / 5001×5200 | **422** `Image dimensions are too large.` | **422**, nothing kept | nothing | 28–29 MB |
| PNG 30 MP / 40 MP | 6000×5000 / 6400×6250 | **422** | **422**, nothing kept | nothing | 29 MB |
| WebP 40 MP | 6400×6250 | 200 ok | 200, kept | JPEG 1024×1000 | **505 / 503 MB** (L-1) |
| WebP 40 MP + 1 row | 6400×6251 | **422** | **422**, nothing kept | nothing | 27–29 MB |
| GIF 40 MP | 6400×6250 | 200 ok | 200, kept | JPEG 1024×1000 | 112 / 109 MB |
| GIF 40 MP + 1 column | 6401×6250 | **422** | **422**, nothing kept | nothing | 55–57 MB |
| PNG RGBA 1200×900 | — | 200 ok | 200, kept | JPEG 1024×768 | 46 / 44 MB |

- **The boundary is exact.** 26,000,000 px is accepted and one more row or column is refused, on both endpoints, on 8.3 and 8.4. A refusal is a header read, under 30 MB RSS for PNG and WebP.
- **No 500s and no PHP fatal** in any run at the head. No temp files were left in the temp directory.
- **Memory headroom (store, `memory_get_peak_usage()`, 20 MB string held):** a 26 MP RGBA PNG peaks at **234,056,008 B** (8-bit, 16-bit and interlaced alike), against Coder's 231,931,904 B. With the cap raised (mutant copy), 27 MP peaks at 242,153,744 B, 28 MP at 250,247,440 B, and 30 MP, 32 MP and 40 MP hit `Allowed memory size of 268435456 bytes exhausted`. So the cap leaves about 22 MB, and the real limit is between 28 and 30 MP. That agrees with Coder's measurement.

### 2.7 Clean-up after a fatal error

| Case | Result |
|---|---|
| Unit test `testCameraFileIsRemovedWhenDecodeFatals` (forced `E_USER_ERROR` in decode) | Passes on all 4 legs. Mutants M4–M6 fail it (2.9). |
| Real `memory_limit` fatal, CLI probe (cap raised to 40 MP, 30 MP and 40 MP PNG at 256M) | `Allowed memory size … exhausted` in `imagecreatefrompng()`, then **0 files** in `originals/`, `originals/map/`, `images/`, `thumbs/`. My check ran as a nested shutdown function, after the app's. |
| Real fatal over HTTP, cap-raised copy, 40 MP PNG, `display_errors=0` (as on the image) | **500**, nothing left, on 8.4 and 8.3 |
| Same on `main` `d9eb204` | 500 with the **original and its map entry left behind**. That is #72 L-1, now fixed. |
| A successful upload | Disarmed before return. All kept files stayed, and the original stayed byte-identical after both suggest calls. Mutant M7 (never disarm) fails 16 tests. |

### 2.8 Stathis's photo requirement, end to end

For every photo that was kept (10 on 8.4, including the 3 bit-depth and interlace variants, and 6 on 8.3), the file in `originals/` was **byte-identical** to the upload. It was still identical after the `photo_id` suggest call, which reads that original. The stub **never** received the original: in all 32 suggest calls that sent something, the SHA-256 differed from the upload's. Each time it was a re-encoded JPEG of at most 1,024 px on the long edge (16 to 56 KB). Refused photos kept nothing and sent nothing, and the fatal cases kept nothing on disk.

### 2.9 Mutations (Debian PHP 8.4, SQLite)

Unit filter: `ImageDecodeTest|UsersTest|SettingsTest|AutofillTest|HeicTest|OriginalsTest|PermissionsTest` (139 tests, baseline green, 2 environment skips). Survivors were also run against `HttpSuiteTest`. The source was restored from the head after each mutant.

| ID | Mutation | Result |
|---|---|---|
| M1 | PNG cap `>` → `>=` | **Killed**: `testPngOverThePngBudgetIsRefusedBeforeDecode` |
| M2 | Cap 26,000,000 → 27,000,000 | **Killed**: same |
| M3 | Drop the PNG branch of the header check (back to 40 MP) | **Killed**: same |
| M4 | Shutdown function doesn't unlink the original | **Killed**: `testCameraFileIsRemovedWhenDecodeFatals` |
| M5 | Shutdown function does nothing | **Killed**: same |
| M6 | Never arm the clean-up | **Killed**: same |
| M7 | Don't disarm on success | **Killed**: 16 failures (`OriginalsTest`, `ImageDecodeTest`) |
| M8 | Imagick decode refusal not mapped to 422 (returns null) | **Killed**: `testSuggestDecodeResourceLimitIsTheDimensionError` (JPEG). The HEIC test still passes, see N-1. |
| M9 | `autofill_suggest_path()` swallows `UserError` | **Killed**: same JPEG test |
| M10 | Anon branch returns `true` whatever the SAPI | **Killed**: `testAnonymousGuestIsNotAdminOnAWebSapi` |
| M11 | SAPI default `'cli'` instead of `PHP_SAPI` | **Survives**: unit and `HttpSuiteTest`. The `cli-server` probe shows the anon guest becoming admin on the web. See N-2. |
| M12 | Ignore the `$sapi` argument (always `PHP_SAPI`) | **Killed**: `testAnonymousGuestIsNotAdminOnAWebSapi` |
| M13 | Drop the anon clause (back to before #77) | **Killed**: 3 tests, including both #77 tests and the new web-SAPI test |
| M14–M16 | Drop the repeated PNG check in `autofill_analysis_gd()`, `autofill_analysis_imagick()`, `autofill_prepare_image()` | **Survive** (unit and HTTP). They are behind the header check, so they can't be reached from a route (I-2). |

That is 12 of 16 killed, and 12 of the 13 mutants that can be reached from a route.

**Extra HEIC check (for N-1).** The PR's HEIC test lowers the Imagick *width* limit to 8, so the header check (`image_heic_header_over_limit()` → `imagick_frame_exceeds_policy()`) refuses the fixture before any decode. With memory/map/disk set to 20,000/20,000/10,000 B instead, the header passes (budget 50,000 B > 96×64×8) and the decode is refused. At the head, suggest then answers `UserError` 422 `Image dimensions are too large.`, and with M8 it answers 200 `unsupported`. So the HEIC decode mapping works; it just isn't what that test exercises.

### 2.10 `actor_is_admin()` (web probe, `php -S`, sign-in on, guest browsing on)

| Tree | `cli-server` | `cli` |
|---|---|---|
| Head | `{"role":"guest","anon":true,"admin":false}` | admin `true` |
| M11 (default `'cli'`) | admin **true** | admin `true` |

The head is correct. Every production caller passes no argument, so `PHP_SAPI` decides, as before. php-fpm reports `fpm-fcgi`.

### 2.11 Docs

README, the user guide, the admin guide, `docs/autofill.md` and `docs/deployment-and-operations.md` state the 26 MP PNG cap and the 40 MP WebP/GIF cap, the 422 on suggest including a refusal at decode, and the clean-up. They match the code and the measurements in 2.6. The measured peaks they quote are PHP's accounting (`memory_get_peak_usage()`). For WebP the process RSS is much higher (L-1). The CHANGELOG bullet matches the code.

### 2.12 The new head `bf951c3` (diff read)

| Commit | Content |
|---|---|
| `eb7ab56` | Merge of `main` `c4e9eda` (`chore(release): v2.6.7`, after #75's merge `077f60b`) |
| `ca5fcf5` | `docs(qa): add v2.6.6 release QA doc`, only `docs/qa/apotheke-v2.6.6-release-qa.md` |
| `bf951c3` | `docs(qa): add v2.6.7 release QA doc`, only `docs/qa/apotheke-v2.6.7-release-qa.md` |

- **The merge has no code conflicts.** `git merge-tree` conflicts only in `CHANGELOG.md`. `src/users.php` (#75's start-up checks next to #79's `actor_is_admin()`), README and the deployment doc merge cleanly, and only CHANGELOG differs from the clean merge.
- **The PR's changed lines are unchanged.** The `+`/`−` lines of `d9eb204..f3a534b` and `c4e9eda..bf951c3` are identical for every file except CHANGELOG and `docs/qa/`.
- **CHANGELOG gate against `c4e9eda`:** `## [2.6.7]` to the end is byte-identical (`9bd87989…f063`), everything above `[Unreleased]` is identical, the #79 bullet is only under `[Unreleased]`, and the link ref is `compare/v2.6.7...HEAD`. **PASS.**
- **Docs:** v2.6.5 is unchanged at `ce177de4…637f`. v2.6.6 is 6,487 B, `e5faed7a…98bb`, and v2.6.7 is 6,489 B, `226b31aa…dfd6`, each byte-identical to the copy in the QA docs folder and in its own `docs(qa):` commit.
- **Sanity run (PHP 8.4, SQLite):** `UsersTest|AuthAtStartTest|ImageDecodeTest|SettingsTest|PermissionsTest` give 62 tests, OK, 1 environment skip.
- **CI:** run 40094826424 (`pull_request`, head `bf951c3`) is `completed` / `success`, 10/10 jobs, 15:39:26 to 15:49:37 (Athens, UTC+3).

## 3. Findings

### L-1 (Low, not a regression): a 40 MP WebP takes about 505 MB of RSS, outside `memory_limit`

On the image's bundled GD, a 6400×6250 WebP is accepted (as on `main`) and peaks at **505 MB RSS** on 8.4 and 503 MB on 8.3, for both `/save` and suggest. libwebp's decode buffer is allocated outside PHP's memory manager, so the 256M `memory_limit` doesn't bound it, and the docs' "peak 195035136 bytes" counts only PHP's side. With `pm.max_children = 10` that is about 5 GB in the worst case, from signed-in users only. A 26 MP PNG peaks at 229–264 MB RSS and a 40 MP GIF at 112 MB for comparison. The code path is unchanged from `main`. The PR does restate that "WebP and GIF stay at 40 MP", so I'm raising it here.

**Suggested fix (follow-up is fine):** give WebP the PNG budget (26 MP), or decode WebP already reduced. Or at least say in the deployment doc that WebP's real peak is about twice `memory_limit`, so a memory-capped container should allow for it.

### N-1 (Nit): the HEIC suggest test passes without the decode-to-422 mapping

`testSuggestHeicDecodeResourceLimitIsTheDimensionError` lowers the width limit, so the fixture is refused by the header check that #72 added, not at decode. Mutant M8, which removes the new mapping, still passes it (2.9). The mapping itself works for HEIC (extra check in 2.9). Setting memory/map/disk to about 20,000/20,000/10,000 B instead of the width would make the test cover the decode path.

### N-2 (Nit): the `PHP_SAPI` default is not pinned on a web SAPI

The new test passes the SAPI explicitly, which pins the rule. But changing the default to `'cli'` (M11) passes every unit test and the HTTP suite, because the HTTP suite's guest is stopped by route and role guards before `actor_is_admin()` runs. The head is correct (2.10). A `cli-server` check in `tests/integration.php` (for example, a guest-browsing request that reaches an admin-only guard) would pin it. This is the rest of #77 L-1.

### N-3 (Nit): two functions that can now throw `UserError` don't say so

`autofill_analysis_imagick()`, which now throws, has no docblock, and the docblock of `autofill_open_analysis()` (its caller's caller) lists only array returns. `autofill_analysis_jpeg()`, `autofill_suggest()` and `autofill_suggest_path()` are updated as claimed.

### I-1 (Info): Coder's memory numbers reproduce

The store peak with a 20 MB string held is 234,056,008 B against Coder's 231,931,904 B. Coder's run fitted and encoded the image, while mine also wrote the display pair. 16-bit and interlaced PNGs give the same peak, because GD strips to 8 bits. The failure point is between 28 and 30 MP, so the 26 MP cap has about 22 MB of headroom (2.6).

### I-2 (Info): the repeated PNG checks in the analysis functions are defence in depth

M14–M16 survive because `autofill_suggest_path()` runs the header check first. That's fine. They would only matter for a future caller that skips the header check.

### I-3 (Info): stored PNGs between 26 and 40 MP

Photos uploaded before #79 can be PNGs of 26 to 40 MP. Suggest by `photo_id` now refuses them with 422 from the header. On `main` the same request decoded them, and at about 30 MP and above it hit the 256M fatal. Their display copies are untouched and still served.

### I-4 (Info): what the clean-up doesn't cover

- **Covered:** `max_execution_time` and `memory_limit` fatals both run shutdown functions.
- **Not covered:** a hard kill (SIGKILL, or php-fpm's `request_terminate_timeout`) skips them.
- **Not covered:** a fatal *after* `image_store()` has returned (for example, in the item insert) isn't armed any more. That isn't new and is outside this PR.

### I-5 (Info): CI4 parity, per-process static state

`image_upload_cleanup_list()` keeps the pending list in a function `static`, and `image_upload_cleanup_arm()` keeps a `static $hooked`. Under php-fpm both reset per request, so this is correct on `main`. When this is ported to CI4 (the Cloudflare phase, possible persistent workers), it should be per-request state. A worker that keeps `$hooked = true` would never register the shutdown hook again. The PNG cap and the suggest 422 also need porting.

### I-6 (Info): environment

The memory figures, the fatal path and the HTTP matrix ran on the image's Alpine PHP with bundled GD. That stack has no Imagick, so a JPEG over 40 MP gives 422 there, as without Imagick. Debian's system libgd allocates outside `memory_limit` (a 26 MP alpha PNG is fine there at 260 MB RSS), so it was used only for the suites and mutations.

### I-7 (Info): the built-in server shows a fatal as 200 when `display_errors` is on

With `display_errors=1` (the CLI default), `php -S` answers a fatal with 200 and the PHP error text. The image runs with `display_errors=0`, where it is 500 (2.7). This is not from this PR and doesn't apply to php-fpm in production.

## 4. Security

- **The PNG cap can't be used for DoS.** It is a header read (`getimagesize`, 24 bytes of IHDR), and a refusal costs under 30 MB RSS. GD decodes the IHDR dimensions, so a file can't declare a small size and decode a large one. 16-bit, interlaced and alpha variants at the cap stay at 234 MB of PHP memory and 229–264 MB RSS, under the 256M limit. WebP is the exception (L-1), unchanged from `main`.
- **The shutdown unlink can only hit this request's own files.**
  - The paths are recorded at arm time from values made in this request: a 128-bit random served name (checked by `valid_image_name()`) and the 256-bit random original basename from the map that was just written (checked by `image_original_basename_ok()`).
  - No request input reaches them, so there is no path traversal.
  - Another request can't collide with the random names, and the list is per process, so there is no race.
  - Success and every handled exception disarm first, so a stored photo is never removed afterwards.
  - The original's path is resolved once at arm time, so a later rewrite of the map can't point the unlink elsewhere.
- **The 422 mapping leaks nothing.** It is the fixed message `Image dimensions are too large.` with no path or library text. CSRF and `no_store()` still run first. The test-photo path catches `Throwable`.
- **Auth is unchanged.** Production callers use `PHP_SAPI`. The anonymous guest is not admin under `fpm-fcgi` or `cli-server`, the sign-in-off anonymous admin is still admin, and the command line keeps the #77 rights (2.10). N-2 is a test gap, not a vulnerability at this head.
- **Photo requirement:** originals stay byte-identical, and only a re-encoded copy of at most 1,024 px is sent (2.8).
- **Secrets and host names:** the diff against `main` has no tokens, keys, account ids or private host names. The only literals are test passwords and `127.0.0.1` in tests, plus `github.com` PR links. CI's secret scan passed.
- **No High or Medium findings.**

## 5. Gate summary

| Gate | Status |
|---|---|
| CI green | Yes, 10/10 at `f3a534b` (run 40093044754) and at `bf951c3` (run 40094826424) |
| Docs and tests in the PR | Yes |
| QA with a Security section, no High or Medium | Yes (this doc) |
| CHANGELOG gate | PASS against `d9eb204` (v2.6.6) at `f3a534b`, and against `c4e9eda` (v2.6.7) at `bf951c3` |
| Release docs in the PR | v2.6.5 (`f3a534b`), v2.6.6 (`ca5fcf5`) and v2.6.7 (`bf951c3`), each byte-identical in its own `docs(qa):` commit |
| PM secret scan | PM to run |
| Merge pinned to the `docs(qa):` head | after this doc is committed unchanged on top of `bf951c3` as the last commit |
