# QA: apotheke #73, #70 follow-up hardening (docs, checks, nginx 421)

- **PR:** [#73](https://github.com/thordin9/apotheke/pull/73), `sec/hardening-followup` into `main`, by DevOps
- **Head reviewed:** `959d0e1f7a7fd9d5aa26499f32a67eb6f08ce707` (re-checked at the end of the review: unchanged)
- **Base:** `main` at `6251870` (`chore(release): v2.6.2`), the merge commit's second parent
- **Scope:** #70 QA's L-2 and N-1 to N-7, plus the health-check line of the v2.6.2 upgrade note
- **Verdict: PASS.** 0 High, 0 Medium, 1 Low, 2 Nits, 4 Info.

## 1. Test plan

1. Code from the codeload tarballs of the head and of `main` `6251870`. Diff review, commit subjects (Conventional Commits, 72 characters at most).
2. CHANGELOG gate: the head contains main's latest release commit, `[2.6.2]` is byte-identical to main's, and every new bullet sits under `[Unreleased]` with a link to #73. Simulated merge into current main. The upgrade note is compared with the v2.6.2 release body.
3. PHPUnit (unit and HTTP suites) and `tests/integration.php` on PHP 8.3 and 8.4, on SQLite and PostgreSQL 17, including the new `ReleaseTest` case.
4. N-3 under real nginx: the shipped `docker/nginx.conf` and main's copy, side by side, in front of php-fpm 8.3 with sign-in on. Requests cover an unknown `Host`, signed out, Editor, Backup holder, and an app-side 500. `scripts/docker-auth-checks.sh` runs against both configs.
5. Mutations: N-2 (`policy.xml`), N-1 (the restore check's 403), the `ReleaseTest` note check, and the nginx change.
6. Docs read against the code.
7. CI run 40082111147, checked through the GitHub API.

**Environment, and how it differs from the built image.** The box has no Docker.
- **nginx:** Debian's nginx 1.26.3, with `auth_request`. The image uses Alpine's nginx.
- **php-fpm:** Debian's php-fpm 8.3, with `clear_env = no` as in the image.
- **Config:** the shipped config is unchanged except for paths, the port, the pid and log files and the `user` line, which a non-root run needs.
- **N-2 policy check:** run in a separate Alpine (latest-stable) rootfs with ImageMagick 7.1.2-34 and `php83-pecl-imagick` 3.8.1. The image builds Imagick 3.8.0 from source. The policy and `scripts/imagick-policy-check.php` are the files from the head.
- **Not repeated:** the Docker build itself. CI's Docker job ran the build-time policy check and `docker-auth-checks.sh` on the real image (below).

## 2. Results

### 2.1 CI

Run 40082111147 (`pull_request`, head `959d0e1`): `completed` / `success`, 10/10 jobs `success`. The check-runs API for the head also lists 10/10 `success`. The jobs are Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Visual E2E 1/3, 2/3 and 3/3, Docker build + smoke test, Conventional Commits, and Secret scan. The run started 12:28:50 and ended 12:38:55 (Athens, UTC+3).

### 2.2 Diff and commits

The head is 8 commits ahead of `6251870` and 0 behind. It changes 10 files: CHANGELOG, README, `docker/nginx.conf`, `docs/ci.md`, `docs/configuration.md`, `docs/deployment-and-operations.md`, `scripts/docker-auth-checks.sh`, `scripts/imagick-policy-check.php`, `tests/Unit/ReleaseTest.php` and `tests/integration_hardening.php`. No application code changes.

| Commit | Length | Subject |
|---|---|---|
| `585b1c9` | 58 | `test(security): cover the restore check 403 without Backup` |
| `f09ad2b` | 67 | `build(docker): check ImageMagick delegate and @file rights and time` |
| `375d805` | 63 | `fix(docker): answer 421 on restore uploads with an unknown host` |
| `3b42a90` | 53 | `docs(deploy): note health checks need an allowed host` |
| `febcd50` | 55 | `docs(changelog): say health checks need an allowed host` |
| `60e1ac2` | 51 | `docs(changelog): record the #70 follow-up hardening` |
| `57d4cca` | 70 | Merge of `origin/main` (a normal two-parent merge, `60e1ac2` + `6251870`; `check-commits.sh` skips merge commits) |
| `959d0e1` | 59 | `test(release): check the whole upgrade note leads the notes` |

All subjects are Conventional Commits and 72 characters or shorter. History is linear apart from the merge, with no rewrites. The gitleaks scan of the diff found nothing.

### 2.3 CHANGELOG gate (PM rule)

| Check | Result |
|---|---|
| The head contains main's latest release commit `6251870` | **Yes.** It is the second parent of `57d4cca`; compare is `ahead 8, behind 0` |
| `## [2.6.2]` section (32 lines, 3,077 bytes) extracted from both and compared with `cmp` | **Byte-identical** |
| Everything outside `[Unreleased]`…`[2.6.2]` | Identical to main |
| New entries | All under `## [Unreleased]`: the upgrade note, then `### Fixed` (1 bullet) and `### Maintenance` (2 bullets). All 3 bullets link #73. |
| Link refs | `[Unreleased]: …/compare/v2.6.2...HEAD` and `[2.6.2]: …/compare/v2.6.1...v2.6.2`, the same as main |
| Simulated merge into current main (`6251870`, unchanged at the end) | Main is the merge parent and nothing is behind, so the merged tree is the head tree. The bullets stay under `[Unreleased]` and `[2.6.2]` keeps main's bytes. |
| Upgrade note text vs the [v2.6.2 release body](https://github.com/thordin9/apotheke/releases/tag/v2.6.2) (published 12:15, body edited 12:25 Athens) | **Exact match** of the sentence pair "Health checks (`/healthz` and `/health`) follow the same rule. … `ALLOWED_HOSTS` or `PUBLIC_URL`." |
| Next release notes simulated with `rel_changelog_insert` and `rel_release_notes` | They open with the note, then `### Fixed` with #73's bullet, then `### Maintenance`. But see L-1. |

**Gate: PASS.** When #71 or #72 merges first, this PR needs main merged in again and the gate re-run. DevOps has already said so.

### 2.4 Test suites

| Leg | PHPUnit | `tests/integration.php` |
|---|---|---|
| PHP 8.3, SQLite | 386 tests, 2,784 assertions, 1 skipped | 1,483 passed, 0 failed |
| PHP 8.4, SQLite | 386 tests, 2,784 assertions, 1 skipped | 1,483 passed, 0 failed |
| PHP 8.3, PostgreSQL 17 | 386 tests, 2,785 assertions, 1 skipped | 1,194 passed, 0 failed |
| PHP 8.4, PostgreSQL 17 | 386 tests, 2,785 assertions, 1 skipped | 1,194 passed, 0 failed |

- **Skips:** the single skip is `OriginalsTest::testPhpCgiRefusalHidesThePathAndDoesNotNeedStderr`, because `php-cgi` isn't installed on the box. The SQLite legs ran with the `TEST_PG_*` settings, so the cross-database tests ran too.
- **New `ReleaseTest` case:** `testRealChangelogUpgradeNoteLeadsTheNextReleaseNotes` passes on all four legs.
- **New N-1 checks:** "editor account added", "the editor has no Backup permission" and "restore check → 403 for a signed-in user without Backup" pass on both SQLite legs. On PostgreSQL they don't run (I-1).

### 2.5 N-3 under nginx (shipped config vs main's)

php-fpm ran with `APP_PASSWORD` set to a random value (sign-in on). A QA-only `auto_prepend_file` forces a 500 on the main `/backup/restore` request when the test header `X-QA-500` is present. It never fires on the `/_restore_check` subrequest. The upload in each case is 30 MB.

| Case | Head config | Main config |
|---|---|---|
| Unknown `Host`, `POST /backup/restore` | **421**. 0 bytes uploaded, so the body isn't read. The 166-byte nginx page doesn't echo the `Host` in the body or the headers. | 500 |
| Unknown `Host`, `GET /` and `GET /backup/restore/check` | 421 / 421 | 421 / 421 |
| Allowed `Host` (IP and `localhost`), signed out | 401 | 401 |
| Editor (no Backup): `/backup`, check, then upload | 403, 403 with an empty body, then 403 with 0 bytes uploaded | — |
| Backup holder (admin): check, then upload of a non-zip | 204, then 422 from the app (it got through) | — |
| Backup holder with an app-side 500 | **500 with the app's own body** (passed through; no `fastcgi_intercept_errors`) | 500, app body |
| Backup holder with an unknown `Host` | 421 | — |
| nginx error log | `auth request unexpected status: 421`, as the docs say | same line, then 500 |
| `docker-auth-checks.sh` from the head | **11/11 ok, exit 0** | **exit 1**: `FAIL 30 MB restore with unknown Host: expected '421', got '500'` |
| main's `docker-auth-checks.sh` | — | exit 0 (it had no such check) |

The doc's nginx snippet matches the shipped config line for line. The only exception is the `include` placeholder comment.

### 2.6 Mutations

**N-2, `docker/imagemagick-policy.xml`, then `imagick-policy-check.php` in the IM7 rootfs.** The baseline passes (exit 0).

| Mutation | Check |
|---|---|
| `delegate *` rights `read\|write` | **exit 1** (`FAIL delegate * has rights None`) |
| `delegate *` rights `read` | **exit 1** |
| `delegate` rule removed | **exit 1** |
| `path @*` rights `read` | **exit 1** (`FAIL path @* has rights None`) |
| `path @*` rights `read\|write` | **exit 1** |
| `path` rule removed | **exit 1** |
| `time` 61 | **exit 1** (`FAIL limit TIME <= 60 s (got 61)`) |
| `time` 3600 | **exit 1** |
| `time` 0 (unlimited) | **exit 1** |
| `time` `1h` | **exit 1** |
| `time` rule removed | **exit 1** (`got none`) |
| `width` back to `16KP` | **exit 1** (`FAIL limit WIDTH = 16384 (got 16000)`) |
| `height` `16KP` | **exit 1** |
| `time` 30 (stricter) | exit 0, as intended |
| Extra narrower allow rule `delegate` `gs` `read\|write` | exit 0 (N-1) |
| Extra narrower allow rule `path` `@/tmp/*` `read` | exit 0. With it, `magick @/tmp/list.txt` really does read the list again, so the rule takes effect (N-1). |

**N-1, the restore check in `public/index.php`, then the SQLite integration suite (PHP 8.4).**

| Mutation | Result |
|---|---|
| 403 → 204 for a signed-in user without Backup | **killed**: `✘ restore check → 403 for a signed-in user without Backup` (1,482 passed, 1 failed) |
| 403 → 401 | **killed** (same check) |
| 403 with a non-empty body | **killed** (same check) |

**`ReleaseTest` note check: mutations in `rel_changelog_fold`, then `testRealChangelogUpgradeNoteLeadsTheNextReleaseNotes`.**

| Mutation | Result |
|---|---|
| Baseline | pass |
| Note truncated to 60 characters | **killed**. The old assertion (`startsWith('**Upgrade note:**')`) would pass this one. |
| Last sentence of the note dropped | **killed** |
| Note dropped | **killed** |
| Note moved after the `###` sections | **killed** |

Main's version of this test fails against the head's CHANGELOG, because it hard-coded the 2.6.2 note text. That is why the test had to change.

**nginx, against the head config.**

| Mutation | Result |
|---|---|
| `error_page 500 = @restore_check_failed;` removed | 500 for the unknown `Host` restore, so `docker-auth-checks.sh` would fail |
| `if ($restore_check = 421)` line removed | 500, so it would fail |
| `auth_request_set` removed | `nginx -t` fails (`unknown "restore_check" variable`) |

### 2.7 Docs against the code

| Item | Text at head | Code / behaviour | Accurate? |
|---|---|---|---|
| L-2 | Upgrade note; "Host names": health endpoints follow the Host rule; "Monitoring and logs" bullet; README and configuration rows | `/healthz`, `/health` → 421 for an unknown `Host` (suite); `HEALTHCHECK` uses `http://127.0.0.1:${PORT}/healthz` (Dockerfile) | Yes |
| N-3 | The restore location note and its snippet; checklist step 4 (`421`); the `docs/ci.md` row | §2.5 | Yes, but see N-2 on "the app's 421" |
| N-4 | "the container ID unless `hostname:` is set" | Docker's default host name is the short container ID | Yes |
| N-5 | The sign-in page starts a session per cookieless visit; PHP's session clean-up removes old files | Suite: "the sign-in page starts a session (it has a form)"; `docker/php.ini` `session.gc_probability = 1` / `gc_divisor = 1000` | Yes |
| N-6 | Behind a TLS proxy, set `PUBLIC_URL` to `https://` or turn on `trust_proxy`, or `Origin`-only browsers get 403 | `request_same_origin()` builds the own origin from `request_is_https()` (`HTTPS`, port 443, or `X-Forwarded-Proto` only with `trust_proxy`) plus `PUBLIC_URL` | Yes (it sits in the sign-in-off paragraph) |
| N-7 | README row adds "the machine's host name" | `docs/configuration.md` and `src/hosts.php` behaviour | Yes |
| N-2 | Deployment doc and CHANGELOG: no rights for delegates or `@file` paths, and time ≤ 60 s | §2.6 | Yes for `delegate *` and `path @*`; slightly broader than the check (N-1) |

The wording describes the defences and how to configure them, with no exploit-ready detail. The example hosts are placeholders (`other.example`, `apotheke.example`, `nas.local`).

## 3. Findings

| ID | Grade | Finding |
|---|---|---|
| L-1 | Low | The next release notes open with "Health checks (`/healthz` and `/health`) follow the same rule." In v2.6.3 or v2.6.4 that rule isn't in the same notes (it lives in 2.6.2's note), so the first sentence has nothing to refer to. Suggestion: "Health checks (`/healthz` and `/health`) follow the Host rule from 2.6.2 ([Host names](…#host-names)) …". This is a CHANGELOG-only change, and the published v2.6.2 body can keep its wording. |
| N-1 | Nit | `imagick-policy-check.php` only checks rules whose pattern is exactly `*` (delegate) or `@*` (path). A later, narrower allow rule, such as `path` `@/tmp/*` `read`, re-enables `@file` reads and the check still passes (§2.6). The CHANGELOG and the deployment doc say "delegates and `@file` paths have no rights". Suggestion: fail on any `delegate` or `path` rule whose rights aren't `None`, or reword to "`delegate *` and `path @*`". |
| N-2 | Nit | The `nginx.conf` comment, the deployment doc and the `docker-auth-checks.sh` comment say nginx passes "the app's 421" on. The status comes from the app's check, but the body is nginx's generic "421 Misdirected Request" page, not the app's *Unknown host name* page. Harmless, since the body has no echo; the wording could say "answers 421". |
| I-1 | Info | At this head, `tests/integration.php` exits before `integration_hardening.php` on PostgreSQL (the legacy SQLite upgrade block, which predates this PR). So the new N-1 checks run only on the SQLite legs. #71 says it makes the sign-in checks run on PG; after both merge, confirm these three checks show up in a PG leg. |
| I-2 | Info | Main's CHANGELOG `[2.6.2]` section stays without the health-check line, because the gate requires byte-identity. The published v2.6.2 body has the line, and the next release carries it through `[Unreleased]`. |
| I-3 | Info | nginx still logs `auth request unexpected status: 421` at error level for each such request. This is documented, and it was accepted in #70 (N-3). |
| I-4 | Info | #71, #72 and #73 all edit `[Unreleased]`. Whichever merges later needs main merged in again and the CHANGELOG gate re-run. |

#70's L-2 and N-1 to N-7 are all addressed. N-3 is fixed in nginx, not just documented.

## 4. Security

- **N-3:** unknown-`Host` restore uploads now get 421 before nginx reads the body (0 bytes uploaded). The page doesn't echo the `Host`. 401/403 for signed-out users and for users without Backup are unchanged. Backup holders still reach the app, and the app's own 500s are not rewritten. The named location only maps an exact check status of 421; any other failure stays 500. No new path accepts a large body.
- **N-1:** the 403 for a signed-in user without Backup is now pinned by a test that fails on 204, on 401, or on a non-empty body.
- **N-2:** the build now fails if the shipped policy loosens `delegate *` or `path @*` rights, raises, removes or unbounds `time`, or reverts `width`/`height` to `16KP`. The check reads `magick -list policy` through `shell_exec`, with a fixed command and no input. An empty listing fails the build (fail-closed). The `argv[1]` override is for testing the check at build time only. See N-1 for the remaining narrower-rule gap.
- **Docs:** they tell operators how to configure health probes, TLS proxies and the restore check, with no exploit-ready detail. No secrets, tokens or real host names appear in the diff (gitleaks: no findings). The QA runs used a random throwaway password, which isn't recorded here.

## 5. Verdict

**PASS** for `959d0e1f7a7fd9d5aa26499f32a67eb6f08ce707`: 0 High, 0 Medium, 1 Low (L-1), 2 Nits, 4 Info.

- **CI:** run 40082111147, 10/10 `success`.
- **CHANGELOG gate:** passed. `[2.6.2]` is byte-identical to main's, and all new entries are under `[Unreleased]` and link #73.
- **Tests:** green on PHP 8.3 and 8.4 with SQLite and PostgreSQL 17.
- **Mutations:** every one aimed at the new checks was killed. The exceptions are the narrower-rule cases in N-1.
- **L-1:** a one-line CHANGELOG wording fix. It is worth doing before the merge but doesn't block it.
