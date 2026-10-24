# QA: apotheke PR #60, the v2.6.0 image-size nits (#59 follow-up)

- **PR:** [#60](https://github.com/thordin9/apotheke/pull/60) into `main`, by Coder. Title: `fix(autofill): address the v2.6.0 image-size nits`. Open, draft, mergeable state `clean`.
- **Head reviewed:** `58a27a9a2bdfcd85340caa7e8c8d0d433da6f7ff` (the pinned head). Base `main` at `e1f77ef` (the v2.6.0 release commit). 9 commits, 10 files, +228/−34.
- **Commits:** `fcdfd21` fix(release) scope dedup (N-1), `9f2606c` fix(autofill) clamp logged once (N-3), `0d7ae60` docs(autofill) once per pair, `1138b1c` fix(autofill) quality step of ten (N-4), `4904488` fix(autofill) measure the JPEG (N-5), `5799d8d` fix(autofill) KB for a tiny limit (N-6), `055245b` and `c90f1e9` docs(autofill), `58a27a9` merge of `main` (`e1f77ef`). The branch starts at `d90cd1b`.
- **CI:** run 40036395491 (`pull_request`, attempt 1) on `58a27a9`: **success**, 10/10 jobs (Lint, Conventional Commits, Secret scan, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Docker build + smoke test, Headless mobile checks, Visual E2E shards 1/3, 2/3, 3/3), 01:53 to 02:03 EEST on 2026-10-24.
- **Verdict: PASS.** N-1 and N-3 to N-6 from the #59 report are fixed and verified with stubs, unit tests and mutants. No high or medium findings. Two lows and five nits are follow-ups. No photos went to any real service, and the Worker token was not read.

## Test plan

1. **Scope.** Compare `e1f77ef...58a27a9` (unauthenticated GitHub API, codeload tarballs of `e1f77ef`, `58a27a9`, `c90f1e9` and `d90cd1b`): commits, parents, files, `diff -r` of the trees. Check the merge `58a27a9` adds only the release commit `e1f77ef`. CI on the head.
2. **Diff read-through.** Every changed line in `src/autofill.php`, `scripts/release.php`, both test files, the CHANGELOG and the five docs.
3. **N-1 release dedup.** The new `ReleaseTest`. A what-if of the v2.6.0 fold with the head's `release.php` (the `d90cd1b` CHANGELOG plus the generated 2.6.0 lines), and a what-if of the next release from this PR's own commits (PR 60) and Unreleased notes.
4. **N-3 to N-6 against stubs.** The #59 QA tier stub behind the QA recording proxy on loopback (it logs method, path, status, image-part and body bytes and dimensions; never the `Authorization` header), the app code under PHP 8.4.27 with SQLite in a QA `mktemp -d` dir, head and base side by side. Scenarios: paid-style info and 3 photos in a row; always-413; an address switch; quality 95 at 2031616 and 524288; a cap between the JPEG size and the multipart body size; `enforced_image_bytes` 1000 and 50000.
5. **Cap-order regressions (stubs).** Free-style `auto` with no cache; `/v1/info` 503 (fallback 983040); Jetson-style `max_image_bytes` only, primed and unprimed; always-413 with no cache (exactly 2 POSTs, 1 refetch); a 3200×2400 noise JPEG at the 65536 minimum with quality 80 and 95.
6. **Original stays byte-identical.** sha256 of the source photo before and after every run, and `DATA_DIR/tmp` empty afterwards.
7. **Suites.** The full unit suite on SQLite and on a throwaway PostgreSQL 17 (loopback, in the QA dir), head and base. `php tests/integration.php` and `scripts/lint.sh` at the head.
8. **Mutants.** 16 mutants against the new code, run against `AutofillTest` and `ReleaseTest`.
9. **Leftovers.** Whether the v2.5.8 release N-2 and #58 N-4 are included.
10. **Lint, secrets, commits.** `php -l` and a CR check on the 4 changed PHP files, commit subjects against `scripts/check-commits.sh`, gitleaks 8.x on the PR diff (default rules and the repo `.gitleaks.toml`), and a grep of the added lines for URLs, IPs, tokens and hostnames.

## Results

### 1. Scope: pass

- The compare shows `ahead_by 9`, `behind_by 0`, merge base `e1f77ef`. The eight work commits form a linear chain from `d90cd1b`, each with one parent. `58a27a9` has parents `c90f1e9` and `e1f77ef`.
- `diff -r` of `e1f77ef` and `58a27a9` lists exactly the 10 files the API lists: `src/autofill.php` (+50/−19), `scripts/release.php` (+21/−1), `tests/Unit/AutofillTest.php` (+113), `tests/Unit/ReleaseTest.php` (+14), `CHANGELOG.md` (+8), `docs/autofill-admin-guide.md`, `docs/autofill.md`, `docs/configuration.md`, `docs/releasing.md`, `docs/user-guide.md`. No migrations, settings, views or baselines change.
- **The merge is clean.** `c90f1e9 → 58a27a9` changes only `CHANGELOG.md`, `README.md` and `VERSION`, the same content as `d90cd1b → e1f77ef` (the release commit), with no extra edits. `VERSION` is `2.6.0` on both sides.
- The PR is still a **draft**, so it has to be marked ready before the merge.

### 2. Diff read-through: pass

- **N-1:** `rel_changelog_fold()` now passes the generated text of a heading through `rel_drop_scoped_generated()` before appending the handwritten text. It collects the `- **scope:** ` prefixes of the handwritten bullets under that heading and drops generated bullets with the same scope under the same heading. Other headings, other scopes and unscoped bullets stay.
- **N-3:** `autofill_target_image_bytes(true)` calls the new `autofill_log_image_clamp()`. It keeps the last `{from, to, field}` in the existing `DATA_DIR/autofill-info.json` and returns without logging when the pair is the same. `autofill_info_cache($info)` keeps the `clamp` key when the refreshed file is for the same `autofill_url`. There is no `static` and no global. The line is unchanged: two sizes and the field, never the token.
- **N-4:** `autofill_jpeg_qualities()` is the configured quality, then 10 points lower down to 60, then 60 if the ladder missed it. Below 60 only the configured quality is tried. The quality is still clamped to 40–95, so the ladders are: 80 → 80/70/60 (unchanged default), 95 → 95/85/75/65/60, 90 → 90/80/70/60, 65 → 65/60, 40–59 → that value only (as on base).
- **N-5:** the build loop compares `strlen($jpeg)` with the cap instead of the multipart body. `autofill_suggest_at()` takes a new `$imageBytes` argument for its local pre-check, and all three call sites (first POST, 413 retry, busy retry) pass `strlen($opened['jpeg'])`. Without it, it falls back to the body length. Both services limit the image part: the Worker checks the image part against `enforced_image_bytes` and the body against `enforced_image_bytes` + 65536, and the Python service checks the image read against `MAX_IMAGE_BYTES`. So a JPEG exactly at the cap is accepted.
- **N-6:** `autofill_mib()` shows KB (bytes / 1024, 1 decimal, 2 below 0.05 KB) when the MiB value is under 0.05, which is where a 1-decimal MiB would round to 0. 52429 bytes and up stay in MiB (`0.1 MiB` and up). The explicit minimum 65536 is `0.1 MiB`, so only a service cap can reach the KB form.
- The docs (admin guide, `autofill.md`, configuration, user guide, releasing) and the five CHANGELOG lines describe each change the same way as the code.

### 3. N-1 release dedup: fixed

- **v2.6.0 replay** (head `release.php`, the `d90cd1b` CHANGELOG, the generated 2.6.0 lines): `### Added` now has only the handwritten `autofill:` line. The generated `add a configurable maximum image size` line is gone. `### Fixed` keeps `cap an explicit size to the service limit` (the same scope, but there is no handwritten `autofill:` line under Fixed), and Maintenance is unchanged.
- **Next-release what-if** (this PR's 8 work commits as PR 60, the head's Unreleased notes): `### Fixed` has the five handwritten lines and none of the five generated `fix(release)`/`fix(autofill)` lines. Maintenance keeps the three `docs(autofill)` lines with `([#60](…))`. **The Fixed section then has no PR link at all** (L-2).
- `testHandwrittenScopeReplacesTheGeneratedLine` covers a dropped scope, a kept other scope, a kept unscoped heading and the same scope under another heading.

### 4. N-3 to N-6 against stubs: fixed

Every row is counted by the recording proxy. The image is the 1024×1024 random-noise JPEG from the #59 QA (2,537,100 B) at the default quality 80 unless noted.

| Scenario | Setting | Head: requests and result | Base `e1f77ef` |
| --- | --- | --- | --- |
| **N-3** paid-style (`max 1048576`, `enforced 2031616`), primed, 3 photos | 2097152 | GET info, 3× POST 200 (700,597 B); **1** clamp line; cache has `{"from":2097152,"to":2031616,"field":"enforced_image_bytes"}` | 3 clamp lines |
| **N-3** always 413, paid info, primed | 2097152 | GET info, POST 413, GET info, POST 413; "max 1.9 MiB / 2031616 bytes"; **1** clamp line | 2 clamp lines |
| **N-3** address switch before the 3rd photo | 2097152 | GET info, 2× POST, GET info (new address), POST; **2** clamp lines (logs again for the new address) | 3 |
| **N-4** quality 95, paid-style | 2097152 | POST 200, **793,396 B at q85** | 580,512 B at q70 |
| **N-4** quality 95, free-style | auto (524288) | POST 200, 496,016 B (q60) | same |
| Default quality 80, free-style | auto | POST 200, 496,016 B (q60) | same (default ladder unchanged) |
| **N-5** `enforced 700700`, stub accepts an image part ≤ 700700 | auto | POST 200, **image 700,597 B, body 700,918 B** (q80; the body is over the cap, the JPEG is not) | 580,512 B at q70 |
| **N-6** `max`/`enforced` 50000, always 413 | auto | 2 POSTs (39,795 B, 419×419), "**max 48.8 KB / 50000 bytes**" | "max 0 MiB / 50000 bytes" |
| **N-6** `max`/`enforced` 1000 | auto | POST 200, a 990 B 68×68 JPEG (I-1) | `too_large` "max 0 MiB / 1000 bytes", nothing sent |

- The clamp line never has the token or `Bearer` (unit tests, mutant M16 below).
- N-4 is gentler, as asked. With quality 95 the copy now lands at q85 instead of q70. q90 would also have fitted, but a 10-point ladder skips it, which is within what the nit asked for ("for example 85 and 75").

### 5. Cap-order regressions: pass

| Scenario | Setting | Requests (head) | Result (head = base) |
| --- | --- | --- | --- |
| Free-style, no cache | auto | POST 200, GET info | 983040 fallback for the build, then 524288 |
| `/v1/info` 503, no cache | auto | POST 200, GET info 503 | **983040** fallback |
| Jetson-style (`max 1048576` only), primed | 2097152 | GET info, POST 200 | **1048576**, warning `(max_image_bytes)` |
| Jetson-style, no cache, q90 | 2097152 | POST 413 (1,968,295 B), GET info, POST 200 | the one retry clamps to 1048576 (head 700,597 B at q80, base 580,512 B at q70) |
| Always 413, no cache | auto | POST 413, GET info, POST 413 | **exactly 2 POSTs, 1 refetch**, "max 1 MiB / 1048576 bytes" |
| 3200×2400 noise (14.4 MB), q80 | 65536 | POST 200 | 52,153 B at 655×491, 2.24 s (base 2.23 s) |
| Same, q95 | 65536 | POST 200 | head 61,804 B in **3.44 s**, base 52,153 B in 2.05 s (N-4 below) |

Bounds, `auto`, the 983040 fallback and the 413 rule (one refetch, one retry) are unchanged. The settings schema and validation are not touched by this PR.

### 6. Original stays byte-identical: pass

In all 32 stub runs (16 scenarios on head and base) the harness's `orig_same` was true: the source photo's sha256 was the same before and after. `DATA_DIR/tmp` was empty after each run. Only the analysis JPEG reached the stub, and its size and dimensions differ from the original's in every row.

### 7. Suites: pass

- **SQLite**, PHP 8.4.27: head **OK, 362 tests, 2459 assertions, 23 skipped**. Base: OK, 358 tests, 2421 assertions, 23 skipped. The 4 extra tests are the PR's four new tests.
- **PostgreSQL 17** (throwaway cluster on loopback in the QA dir, stopped afterwards): head **OK, 362 tests, 2487 assertions, 13 skipped**.
- `php tests/integration.php` at the head: **1423 passed, 0 failed**. `scripts/lint.sh`: ok.
- Coder's one local `HeicTest` failure (Imagick placeholder) does not occur here, because Imagick is not installed on the box (skipped). CI's Docker smoke covers Imagick.

### 8. Mutants

| Mutant | Result |
| --- | --- |
| M1 N-1: no drop | killed (`testHandwrittenScopeReplacesTheGeneratedLine`) |
| M2 N-1: drop every scoped generated line | killed (same) |
| M3 N-3: always log | killed (`testClampWarningIsLoggedOncePerDistinctPair`) |
| M4 N-3: a refresh drops the remembered pair | killed (same) |
| M5 N-3: the pair compares only `from` | **survives** (N-1 below) |
| M6 N-3: the pair compares only `to` | killed (2 tests) |
| M7 N-3: the pair is kept across an address change | **survives** (N-1 below; the behaviour was checked by stub in section 4) |
| M8 N-4: the old 70/60 ladder | killed (`testJpegQualityStepsDownByTen`) |
| M9 N-4: no final 60 | killed (same) |
| M10 N-5: the build measures the body | killed (`testLimitMeasuresTheImagePartNotTheMultipartBody`) |
| M11 N-5: the pre-check measures the body | killed (same) |
| M12 N-5: the first POST omits `$imageBytes` | **survives** (L-1) |
| M13 N-5: the 413 retry omits `$imageBytes` | **survives** (L-1) |
| M14 N-6: no KB branch | killed (`testImageLimitInMib`) |
| M15 N-6: KB threshold 0.01 MiB instead of 0.05 | **survives** (N-2 below) |
| M16 token in the clamp line | killed (4 tests) |

M12 matters in practice. With M12 applied, the section 4 N-5 scenario (`enforced 700700`) gives `too_large` "max 0.7 MiB / 700700 bytes" and **nothing is sent**, while the whole suite stays green.

### 9. Leftovers: not included

The PR body says only the #59 nits are in scope. The v2.5.8 release N-2 (the README coverage figure does not count the subprocess tests) and #58 N-4 (`db()` exits directly under the CLI) are not in this PR. They stay open. #58 N-4 is in DevOps's ci4 sync plan (CLI int exit codes). Neither blocks.

### 10. Lint, secrets, commits: pass

- `php -l` is clean on the 4 changed PHP files, and none has a CR.
- The 8 work subjects match `check-commits.sh` (40–54 characters). The merge subject is skipped by the script, as designed.
- gitleaks (`detect`) on the PR diff (38,779 B), with the default rules and with the repo `.gitleaks.toml`: **no leaks**. The added lines have no URLs, IPs, hostnames or Worker URL. The only token is the synthetic `sekret-token-xyz` test fixture.
- `docs/qa/apotheke-pr59-v2.6.0-max-image-size-qa.md` on `main` is byte-identical to the QA original (sha256 `c4207407…af0b`).

## Findings

| ID | Severity | Summary |
| --- | --- | --- |
| L-1 | Low | The N-5 call-site wiring is untested: dropping `$imageBytes` at the first POST or the 413 retry (M12, M13) keeps the suite green but makes a JPEG that fits fail locally as too large |
| L-2 | Low | The five handwritten Unreleased `### Fixed` lines have no PR link, and N-1 now drops the generated lines that carried `([#60](…))`, so the next release's Fixed section has no PR link |
| N-1 | Nit | The N-3 test does not pin a change of `to` only (M5) or an address change (M7) |
| N-2 | Nit | The N-6 test checks only 1000 and 10 bytes, so a narrower KB threshold (M15) survives |
| N-3 | Nit | `KB` is computed with 1024, next to `MiB`; `KiB` would be consistent |
| N-4 | Nit | Quality 95 on a large, hard-to-compress photo builds more copies: 3.44 s against 2.05 s at the 65536 minimum. The multipart body is also built for every attempt, not only for the one that fits |
| N-5 | Nit | The remembered pair is a read-modify-write of `autofill-info.json` without a lock |

### Low

- **L-1: the N-5 call sites have no test.** `testLimitMeasuresTheImagePartNotTheMultipartBody` calls `autofill_open_analysis()` and then `autofill_suggest_at()` directly, with the image size passed by hand. Nothing runs the real flow (`autofill_suggest()` → `autofill_post_analysis()`) with a cap between the JPEG size and the body size. With M12 (first POST without `$imageBytes`), that photo fails as "too large" and nothing is sent, and all tests pass. The code is correct today (section 4). Suggested fix: one test through `autofill_suggest()` with a stub whose `enforced_image_bytes` is a few bytes over the JPEG, asserting 1 POST and `ok`, plus the same for the 413 retry path.
- **L-2: the next release's Fixed lines lose their PR link.** The handwritten lines in `## [Unreleased]` have no `([#60](https://github.com/thordin9/apotheke/pull/60))`. Before this PR the generated duplicates carried the link. N-1 now drops those, so the v2.6.1 section and release body (what-if in section 3) list the five fixes with no link. Only the three Maintenance docs lines link #60. #58 N-3 moved the PR links onto the handwritten lines for the same reason. Suggested fix: append the #60 link to the five lines before merging, or let `rel_drop_scoped_generated()` carry a dropped line's PR link onto the handwritten line with that scope.

### Nit

- **N-1:** add one assert for a pair that changes only `to` (for example, the service lowers `enforced_image_bytes`) and one for an address change. QA checked the address case by stub (section 4: logs again).
- **N-2:** add `autofill_mib(50000)` → `48.8 KB` and the 52428/52429 boundary to `testImageLimitInMib`.
- **N-3:** `1000 bytes is 1 KB` uses 1024 as the divisor (1000 / 1024 = 0.98, shown as 1). `KiB` would match `MiB`. It is cosmetic.
- **N-4:** with `AUTOFILL_UPLOAD_JPEG_QUALITY=95`, each long edge now tries 5 qualities instead of 3. On a 3200×2400 noise photo at the 65536 minimum that is 3.44 s against 2.05 s. The default 80 is unchanged (2.24 s). Building the multipart body only after the JPEG fits would save a little work in each attempt.
- **N-5:** `autofill_log_image_clamp()` and `autofill_info_cache()` each read and rewrite `autofill-info.json`. Two concurrent Analyze requests and a refresh could interleave and lose the remembered pair (one extra log line) or the info cache (one extra `/v1/info` fetch). It is harmless, and the cache already had no lock.

### Info

- **I-1:** a very small service cap now reaches the service. With `enforced_image_bytes` 1000, base stopped locally (the body could never fit), and head posts a 990 B 68×68 JPEG, because the cap now measures the JPEG. That matches the spec, and it only happens with a misconfigured service.
- **I-2:** N-1 drops every generated bullet whose scope and heading match a handwritten bullet, even one for a different change. Every merge to `main` cuts its own release, so Unreleased only holds that PR's notes. `docs/releasing.md` states the rule.
- **I-3 (CI4 port):** the remembered clamp pair lives in a `DATA_DIR` file next to the info cache. In the CI4 Autofill module it belongs behind the cache or storage interface, not a direct `writable/` path (Cloudflare-phase flag).

## Not covered

- No live check: the 5-photo budget was used up on #59, so every service shape here is a stub. The paid-tier path the #59 live check exercised behaves the same except for the N-3 to N-6 changes verified above.
- Docker, Playwright and headless-mobile were not rerun locally (there is no Docker on the box); CI 40036395491 is green for all of them. Imagick is not installed on the box, so the stub runs used GD.
