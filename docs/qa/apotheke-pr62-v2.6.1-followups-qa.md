# QA: apotheke PR #62, the #60 and v2.6.1 QA follow-ups

- **PR:** [#62](https://github.com/thordin9/apotheke/pull/62) into `main`, by Coder. Title: `test: cover exact-fit uploads and changelog PR links`. Open, draft, mergeable state `clean`.
- **Head reviewed:** `8896fd849c3ebc7595c7b38c3336b36ffc0d9b37` (the pinned head). Base `main` at `e2628f2` (the v2.6.1 release commit, still the head of `main`). 5 commits, 7 files, +364/−8.
- **Commits** (linear, one parent each): `fd5ba7a` test(autofill) exact-fit uploads (#60 L-1), `6fdd20f` docs(changelog) the 2.6.1 and 2.6.0 links (#60 L-2, v2.6.1 L-1, v2.6.0 N-1), `8c21714` ci(release) the link check, `14c57af` docs(changelog) the Unreleased note, `8896fd8` docs(qa) the v2.6.1 release QA report.
- **CI:** run 40046775541 (`pull_request`, attempt 1) on `8896fd8`: **success**, 10/10 jobs (Lint, Conventional Commits, Secret scan, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Docker build + smoke test, Headless mobile checks, Visual E2E shards 1/3, 2/3, 3/3), 03:48 to 03:56 EEST on 2026-10-24.
- **Verdict: PASS.** #60 L-1 and L-2, v2.6.1 L-1 and v2.6.0 N-1 are fixed. The new link check fails on an unlinked Unreleased bullet and leaves released sections alone. No behaviour change in `src/`. No high or medium findings. One low, four nits. No photos went to any real service, and nothing called a Worker or Cloudflare.

## Test plan

1. **Scope.** Compare `e2628f2...8896fd8` (unauthenticated GitHub API, codeload tarballs of both): commits, parents, files, `diff -r` of the trees. Confirm no file under `src/`, `migrations/`, `views/` or `public/` changes. CI on the head.
2. **Diff read-through.** Every changed line in the two test files, `scripts/release.php`, `scripts/lint.sh`, `docs/releasing.md` and `CHANGELOG.md`.
3. **#60 L-1 (N-5 call sites).** Run the two new tests, then mutation-check them: drop the image size at the first POST and at the 413 retry, one at a time, and confirm a test fails. Also the third call site (the test-photo busy retry) and two pre-check mutants.
4. **Released CHANGELOG sections.** Which published lines change, whether anything else in a released section changes, and how the CHANGELOG then compares with the v2.6.1 and v2.6.0 release bodies.
5. **The link check.** `php scripts/release.php check-links` on the head CHANGELOG, on the base CHANGELOG (released sections with unlinked lines), and on scratch copies with an unlinked, linked, wrapped, `*`, nested, CRLF, issue-link and mid-text-link Unreleased bullet. `scripts/lint.sh` with an unlinked Unreleased bullet. Mutants against the check and its wiring.
6. **Release impact.** Whether merging cuts a release (commit types), and how the next release will fold the Unreleased note.
7. **The v2.6.1 release QA doc.** Is `docs/qa/apotheke-v2.6.1-release-qa.md` in the PR, and is it byte-identical to the QA original (14,562 B, sha256 `4ed0e3df…e0ee`)?
8. **Suites.** The full unit suite on SQLite and on a throwaway PostgreSQL 17 (loopback, in a QA `mktemp -d` dir), head and base. `php tests/integration.php` and `scripts/lint.sh` at the head. The `HeicTest` 64×64 report.
9. **Lint, secrets, commits.** Commit subjects against `scripts/check-commits.sh`, actionlint on the workflows, gitleaks 8.30.1 on the PR diff (default rules and the repo `.gitleaks.toml`), and a grep of the added lines for URLs, IPs, tokens and hostnames.

## Results

### 1. Scope: pass

- The compare shows `ahead_by 5`, `behind_by 0`, merge base `e2628f2`. The five commits form a linear chain from `e2628f2`.
- `diff -r` of the two trees lists exactly the 7 files the API lists: `CHANGELOG.md` (+10/−6), `docs/qa/apotheke-v2.6.1-release-qa.md` (new, +224), `docs/releasing.md` (+3/−1), `scripts/lint.sh` (+3), `scripts/release.php` (+27/−1), `tests/Unit/AutofillTest.php` (+80), `tests/Unit/ReleaseTest.php` (+17).
- **No behaviour change:** nothing under `src/`, `migrations/`, `views/`, `public/`, `docker/` or the Dockerfile changes. `release.php` only gains a new `check-links` command and a helper; `plan` and `apply` are untouched apart from the usage line.
- The PR is still a **draft**, so it has to be marked ready before the merge.

### 2. Diff read-through: pass

- **Tests.** `testFirstPostSendsAJpegThatExactlyFitsTheCap` builds the analysis JPEG for an 80×60 source, sets the service cap (`max_image_bytes` in the info cache, `auto` setting) to exactly that JPEG's length (768 B here), runs the real `autofill_suggest()`, and asserts `ok`, exactly 1 POST, an image part of exactly the cap, and a multipart body over the cap. `testRetryPostSendsAJpegThatExactlyFitsTheCap` uses the PHP echo stub's `clamp` mode (first POST 413, then `/v1/info` advertises `enforced_image_bytes` = the JPEG length): `ok`, 2 POSTs, the refetched limit equals the JPEG length, and the second image part is exactly the cap with the body over it. The helper `jpegCap()` asserts the body really is larger than the JPEG, so the test cannot pass trivially. Both clear and restore `AUTOFILL_UPLOAD_JPEG_QUALITY` / `AUTOFILL_UPLOAD_MAX_SIDE` and the info cache.
- **`rel_unreleased_missing_pr_links()`** takes the text between `## [Unreleased]` and the next `## [` heading or link-reference line, and returns each line starting with `- ` that has no `([#N](https://github.com/…/pull/N))`. `check-links` prints those lines to stderr and exits 1, or exits 0. `scripts/lint.sh` runs it between shellcheck and the pipefail grep check, so CI's Lint job runs it on every PR.
- **`testUnreleasedHandwrittenBulletNeedsAPrLink`** pins: a bare Unreleased bullet is reported and a linked one is not; the same bare bullet in a released section is not; an empty Unreleased returns nothing; the real CHANGELOG passes; 2.6.1 Fixed has five `#60` links; the 2.6.0 `db:` Fixed line carries the `#59` link.
- **`docs/releasing.md`** adds that a handwritten Unreleased bullet keeps its PR link and that lint fails otherwise, with the reason (the fold keeps the bullet and drops the generated line that would have carried the link). It matches the code.

### 3. #60 L-1, the N-5 call sites: fixed

Each mutant was applied to a scratch copy of the head and run against `AutofillTest` and `ReleaseTest` (86 tests), then `scripts/lint.sh`.

| Mutant | Result |
| --- | --- |
| M1 first POST (`autofill_post_analysis()`, line 994) drops `$imageBytes` | **killed** (`testFirstPostSendsAJpegThatExactlyFitsTheCap`) |
| M2 413 retry (line 1007) drops `$imageBytes` | **killed** (`testRetryPostSendsAJpegThatExactlyFitsTheCap`) |
| M3 test-photo busy retry (`autofill_test_photo()`, line 1129) drops `$imageBytes` | survives (N-1) |
| M4 the pre-check always measures the body | killed (3 tests) |
| M5 the pre-check uses `>=` instead of `>` | killed (3 tests: an exact fit is now pinned) |

These are #60's surviving M12 and M13, so **#60 L-1 is fixed** for the two call sites it named. Each of M1 and M2 is caught by exactly one test, so a failure points at the right call site.

### 4. Released CHANGELOG sections: edited, as intended

- The CHANGELOG diff touches exactly six released lines, and in each the only change is a ` ([#N](https://github.com/thordin9/apotheke/pull/N))` suffix: the five handwritten `### Fixed` lines in `[2.6.1]` (`#60`) and the handwritten `db:` line in `[2.6.0]` `### Fixed` (`#59`). Every other byte of the released sections is unchanged (checked in the unified diff). That is what #60 L-2, v2.6.1 L-1 and v2.6.0 N-1 asked for.
- `## [Unreleased]` gains one `### Maintenance` bullet linking `#62`.
- **The GitHub release bodies now differ from the CHANGELOG.** v2.6.1's body (published 03:01:58 EEST, not edited since) still has the five Fixed lines with no link. v2.6.0's body still has the `db:` line with no link (its other 16 PR links are unchanged). Coder plans to hand-update the v2.6.1 body after the merge; the v2.6.0 body needs the same one-line edit (L-1).

### 5. The link check: pass

`php scripts/release.php check-links` against scratch copies of the head CHANGELOG with one Unreleased bullet added:

| Case | Exit | Reported |
| --- | --- | --- |
| Head CHANGELOG as is | 0 | – |
| Base CHANGELOG (v2.6.1 as released, 5 unlinked Fixed lines, empty Unreleased) | 0 | – |
| Unlinked `- **autofill:** …` | **1** | that line |
| Linked to `/pull/63` | 0 | – |
| Link to `/issues/63` | 1 | that line |
| Link mid-line, not at the end | 0 | – |
| CRLF line endings, unlinked / head as is | 1 / 0 | that line / – |
| Bullet wrapped onto a second line, link on the second line | 1 | the first line (N-3) |
| `*` bullet with no link | 0 | – (N-3) |
| Linked bullet with a bare nested `  -` sub-bullet | 0 | – (N-3) |
| No `## [Unreleased]` heading at all | 0 | – (N-3) |

- **It does not flag older released sections.** The head CHANGELOG has 205 bullets; 22 released ones have no PR link (older releases), and none of them is reported. Only `## [Unreleased]` is read.
- With an unlinked Unreleased bullet, **`scripts/lint.sh` fails** (mutant C3 below); with the head CHANGELOG it prints `== changelog links` / `ok`.

Mutants (same harness as section 3):

| Mutant | Result |
| --- | --- |
| L1 the helper always returns `[]` | killed (`testUnreleasedHandwrittenBulletNeedsAPrLink`) |
| L2 the whole file is scanned, not just Unreleased | killed (same; lint also fails on the real CHANGELOG) |
| L3 any `#N` counts as a link, no URL needed | survives (N-2) |
| L4 `check-links` always exits 0 | survives (N-2) |
| L5 `lint.sh` no longer runs `check-links` | survives (N-2) |
| C1 the 2.6.0 `db:` line loses its `#59` link | killed |
| C2 one 2.6.1 Fixed line loses its `#60` link | killed |
| C3 the new Unreleased bullet loses its `#62` link | killed, and `lint.sh` fails |

### 6. Release impact: no release from this merge

- The five commits are `test`, `docs`, `ci`, `docs`, `docs`. Per `release.php`'s bump rules (`feat` minor, `fix`/`perf` patch, other types no release), merging #62 **cuts no release**, so the release bodies are only changed by hand.
- The new `### Maintenance` bullet (`changelog:` scope) stays in `## [Unreleased]` until the next `feat`/`fix` merge. At that release the fold will drop the two generated `docs(changelog)` Maintenance lines (same scope, same heading) and keep the generated `test(autofill)`, `ci(release)` and `docs(qa)` lines (N-4).

### 7. The v2.6.1 release QA doc: already in the PR

`docs/qa/apotheke-v2.6.1-release-qa.md`, added by `8896fd8`, is 14,562 B, sha256 `4ed0e3df05c6e583e6b7adee0a05257d0e2340aef6647b900c07805da3f4e0ee`, and `cmp` matches the QA original byte for byte. The commit adds only that file.

### 8. Suites: pass

- **SQLite**, PHP 8.4.27 (Imagick 7 with HEIC, GD): head **OK, 366 tests, 2,646 assertions, 11 skipped**. Base `e2628f2`: OK, 363 tests, 2,625 assertions, 11 skipped. The 3 extra tests are the PR's three new tests.
- **PostgreSQL 17** (throwaway cluster on loopback in the QA dir, scram, stopped afterwards): head **OK, 366 tests, 2,674 assertions, 1 skipped**.
- `php tests/integration.php` at the head: **1426 passed, 0 failed**. `scripts/lint.sh`: ok, including the new `== changelog links` step (188 relative Markdown links, 0 broken).
- **`HeicTest`:** all 3 tests pass here (Imagick with libheif on the box) and in CI's sqlite, pgsql and Docker jobs. The PR touches no image code. The 64×64 result Coder saw was specific to the agent's VM (I-2).

### 9. Lint, secrets, commits: pass

- The 5 subjects match `check-commits.sh` (42 to 59 characters).
- actionlint on `.github/workflows/*.yml` is clean (no workflow changes).
- gitleaks 8.30.1 (`dir`) on the PR diff (29,798 B), with the default rules and with the repo `.gitleaks.toml`: **no leaks**.
- The added lines have no tokens, hostnames, account ids or production domain. The URLs are `github.com/thordin9/apotheke` PR and release links, plus `https://autofill.example` and `127.0.0.1` inside the v2.6.1 QA doc.

## Findings

| ID | Severity | Summary |
| --- | --- | --- |
| L-1 | Low | The v2.6.0 release body also differs from the CHANGELOG now (the `db:` line has no `#59` link); the plan only mentions updating the v2.6.1 body |
| N-1 | Nit | The third `$imageBytes` call site (the test-photo busy retry) still has no test (M3 survives) |
| N-2 | Nit | The link check accepts a bare `#N` in its unit test (L3), and the CLI exit code and the `lint.sh` wiring are untested (L4, L5) |
| N-3 | Nit | The check reads only the first line of a `- ` bullet: a wrapped bullet with its link on line 2 is flagged, and `*` or nested bullets, or a missing Unreleased heading, pass |
| N-4 | Nit | The new Maintenance bullet uses the `changelog:` scope for test and lint changes |

### Low

- **L-1: both release bodies need the hand edit, not just v2.6.1.** After the merge, v2.6.1's body lacks the five `#60` links and v2.6.0's body lacks the `#59` link on the `db:` Fixed line, while the CHANGELOG has them. Suggested fix: when Coder hand-updates the v2.6.1 body, append ` ([#59](https://github.com/thordin9/apotheke/pull/59))` to that one v2.6.0 line too, then compare each body with its CHANGELOG section (QA can do that check). Doesn't block.

### Nit

- **N-1:** `autofill_test_photo()` (the admin test-photo check) retries once on 503 + Retry-After and passes `$imageBytes` there too (line 1129). Dropping it keeps the suite green. The code is correct today, and #60 L-1 named only the first POST and the 413 retry. Optional: one stub test with a busy-then-ok service and an exact-fit cap.
- **N-2:** add a bullet like `- **x:** note (#62)` to `testUnreleasedHandwrittenBulletNeedsAPrLink`, so a looser regex is caught, and one `exec` of `php scripts/release.php check-links` on a scratch CHANGELOG to pin the exit code. CI's Lint job does run the command, but only on a CHANGELOG that passes.
- **N-3:** the CHANGELOG has no wrapped, `*` or nested bullets today, so nothing is wrongly flagged or missed now. If someone wraps a long bullet, the check asks for the link on the first line, which is a confusing message. A note in `docs/releasing.md` ("one line per bullet") or treating indented continuation lines as part of the bullet would cover it. A CHANGELOG with no `## [Unreleased]` heading passing silently is harmless, since the release fold needs that heading anyway.
- **N-4:** the bullet describes the exact-fit upload tests and the link check under `**changelog:**`. A split (`autofill:` for the tests, `release:` for the check) would match the generated lines. Cosmetic.

### Info

- **I-1:** published sections were edited on purpose (section 4), limited to six link suffixes. This is the same kind of post-release CHANGELOG fix as #59 did for v2.5.8.
- **I-2:** `HeicTest` passes on the box and in CI. The 64×64 result is an environment effect on the agent's VM (its Imagick/libheif build), not a code issue, and this PR touches no HEIC path.
- **I-3:** merging cuts no release (section 6), so `VERSION` stays `2.6.1` and the next `feat`/`fix` PR carries the Unreleased Maintenance bullet into its release.
- **I-4 (CI4 / Cloudflare phase):** nothing here touches runtime code or storage; there is nothing to port.

## Not covered

- Docker, Playwright and headless-mobile were not rerun locally (there is no Docker on the box); CI 40046775541 is green for all of them.
- No live service: every upload ran against the repo's PHP echo stub on loopback.
