# QA: apotheke #57, pin the PHP 8.3.36 security release (v2.5.7)

- PR: #57, `fix(docker): pin the PHP 8.3.36 security release`
- Head reviewed: `76f49859281bc51a18c52fe0cd92eabd4f9a5966` (3 ahead / 0 behind main `85c8c64`)
- Commits: `9389450` fix(docker), `f59e7d6` merge of main, `76f4985` ci(docker)
- CI: run 39849920575, all 10 jobs green
- Date: 2026-10-22
- Verdict: **PASS**. No H or M findings. Two L and five N findings below; none blocks the merge.

## Test plan

1. Merge integrity: redo the 3-way merge of `9389450` and `85c8c64` (base `02464d4`) and compare it with the
   tree of `f59e7d6`. Check the CHANGELOG resolution and the full diff against main.
2. Base image: confirm on Docker Hub that `php:8.3.36-fpm-alpine` exists and that its `PHP_VERSION` is 8.3.36.
   Build the head image with `--pull`, run `php -v`, the CI assert and the CI Docker smoke chain. Compare `php -m`
   with a build of main. Confirm the pinned Imagick 3.8.0 build (#48) still works on 8.3.36.
3. CI assert logic: extract the new step and run it against fake Dockerfiles and a fake `docker run`. Also run it
   against a real stale base image (8.3.35). Check for vacuous passes, what it prints, and the build cache.
4. `DockerfileTest`: run it, then mutate the `FROM` line.
5. Docs, CHANGELOG, commit subjects and a local what-if run of the release script.
6. Lint and the PHPUnit unit suite.
7. CI run 39849920575: per-job results, and that the assert step ran and printed 8.3.36.
8. gitleaks on the PR patches.
9. A note on `feature/ci4` for the later sync.

## Results

### 1. Merge integrity: pass

- `git merge-file` of `9389450` and `85c8c64` on base `02464d4` merges `Dockerfile`, `README.md`,
  `docs/deployment-and-operations.md` and `docs/getting-started.md` cleanly. Each is byte-identical to `f59e7d6`.
- The only conflict is `CHANGELOG.md`, the expected one with #56. In `f59e7d6`, `[Unreleased]` has only the
  docker line, and the migrate note stays in 2.5.6. Apart from the docker lines, `CHANGELOG.md` is identical to main.
- Apart from the conflict resolution, `f59e7d6` is exactly `9389450` plus main (including
  `docs/qa/apotheke-v2.5.6-release-qa.md`). There are no evil-merge edits.
- Compared with main, the head touches only the 8 expected files: `.github/workflows/ci.yml` (+9),
  `CHANGELOG.md` (+4), `Dockerfile` (+2/-1), `README.md` (+6/-3), `docs/ci.md` (+1/-1),
  `docs/deployment-and-operations.md` (+7), `docs/getting-started.md` (+3/-1) and the new
  `tests/Unit/DockerfileTest.php` (+16). No changed file has CRLF line endings.

### 2. Base image and built image: pass

- The `FROM` line is exactly `FROM php:8.3.36-fpm-alpine`, with the comment
  `# Bump this patch tag on each PHP security release.` above it.
- On Docker Hub, the tag `8.3.36-fpm-alpine` is active and was pushed 2026-10-22 12:57 UTC (15:57 EEST). Its index
  digest is `sha256:aaf1ad25…3628f75` and its amd64 digest is `sha256:4bba7da7…b222f4fa`. The image config has
  `PHP_VERSION=8.3.36`, and `php -r 'echo PHP_VERSION;'` prints `8.3.36`. On 22 Oct the floating `8.3-fpm-alpine`
  tag resolves to the same amd64 image.
- The head image was built with `podman build --pull=always` because there is no Docker daemon on the box.
  - `php -v` prints `PHP 8.3.36 (cli) (built: Oct 21 2026)` with Zend OPcache 8.3.36.
  - Imagick comes from the GitHub source with its sha256 pin. `phpversion('imagick')` is `3.8.0`, with
    ImageMagick 7.1.2-34, and HEIC is listed. All the build-time checks in the Dockerfile `RUN` pass.
  - `php -m` lists gd, exif, imagick, Zend OPcache, PDO, pdo_pgsql, pdo_sqlite, sqlite3 and zip. It is identical
    to the main build. Both builds even have the same image ID, because the floating tag pointed at the same base
    and none of the files the image copies changed. `intl` is not in either image; it was never installed and the
    app doesn't need it.
- The CI assert step, run against the built image, prints `PHP_VERSION=8.3.36` and exits 0.
- The CI Docker chain, run locally on SQLite, passes:
  - `scripts/docker-heic.sh`: HEIC is listed, a HEIC round trip works, transparency is flattened onto white and
    CMYK is written as sRGB, and the php-fpm refusal hides the path.
  - `scripts/smoke.sh`: the front page returns 200 and an upload returns 303.
  - `scripts/docker-checks.sh`: all checks pass.
  - Graceful stop: 0 s, exit code 0.
  - The PostgreSQL smoke and the Playwright `@smoke` runs were left to CI, where both are green.

### 3. CI assert logic: pass (fails closed)

The assert step (`set -euo pipefail`, `sed -nE 's/^FROM php:([0-9]+\.[0-9]+\.[0-9]+)-fpm-alpine$/\1/p'`, an
empty-pin guard, then `docker run --rm -e PIN=… apotheke:ci php -r '… version_compare(PHP_VERSION, getenv("PIN"), "==") …'`)
was run with a fake `docker` that reports a chosen `PHP_VERSION`.

| Case | Result |
|---|---|
| `FROM php:8.3.36-fpm-alpine`, built 8.3.36 | pass (exit 0, prints `PHP_VERSION=8.3.36`) |
| Pin 8.3.36, built 8.3.35, 8.3.37, 8.3.36RC1 or 8.4.27 | fail (exit 1) |
| `FROM php:8.3-fpm-alpine` (no patch) | fail: "does not pin" |
| A commented `# FROM php:8.3.36-fpm-alpine` plus a floating `FROM` | fail: "does not pin" (comments never match) |
| An indented `FROM`, `FROM --platform=…`, `… AS base`, lowercase `from`, a `docker.io/library/` prefix | fail: "does not pin" |
| ARG-based `FROM php:${PHP_VERSION}-fpm-alpine` | fail: "does not pin" |
| A digest, `FROM php:8.3.36-fpm-alpine@sha256:…` | fail: "does not pin" |
| A trailing space or CRLF | fail: "does not pin" |
| A four-part `8.3.36.1` | fail: "does not pin" |
| An empty or missing Dockerfile | fail (exit 1 or 2) |
| Two matching `FROM` lines (multi-stage) | fail (multi-line pin, `version_compare` false) |
| `FROM php:8.4.27-fpm-alpine`, built 8.4.27 | pass |

- It can't pass vacuously. An empty pin is caught by the guard, and even `version_compare("8.3.36", "", "==")` is false.
- Against a real stale base (the official `php:8.3.35-fpm-alpine` image, tagged locally as the image under
  test), it prints `PHP_VERSION=8.3.35` and exits 1.
- Output: only `PHP_VERSION=X.Y.Z` is printed. The container gets only `PIN`. No secrets.
- Build cache: the CI build uses `cache-from: type=gha`, and in run 39849920575 every layer was `CACHED`. Buildx
  still resolves the base tag from the registry on each run (`load metadata`, then
  `FROM …:8.3.36-fpm-alpine@sha256:aaf1ad25…`), and the cache is keyed on that digest, so the gha cache can't
  hide a stale base. The assert would catch one either way. The real stale-base risk is self-hosted
  `docker compose build` without `--pull`, which the docs now cover.

### 4. DockerfileTest: pass

- On the head it passes: `OK (1 test, 3 assertions)`.
- It fails for a floating `8.3`, `8`, a tagless `fpm-alpine`, `8.3.35`, `8.4.27`, another image (`node:`), the
  `fpm-bookworm` variant, a commented pin over a floating or tagless `FROM`, a digest, `--platform` and `AS base`.
- Comment lines never match (`^FROM`, multiline).
- It still passes for a pinned `FROM` plus a later `FROM php:fpm-alpine` or `php:8-fpm-alpine` stage, and for a
  trailing space or CRLF (see N-2).

### 5. Docs, CHANGELOG, commits, release: pass with notes

- The `--pull` commands are correct (`docker compose build --pull`, then `docker compose up -d`) and consistent
  across `README.md` (the image line, Update and Upgrading), `docs/getting-started.md` and
  `docs/deployment-and-operations.md`. The bump-then-`--pull` line and the note that CI checks the pin are in
  `docs/deployment-and-operations.md`. `docs/ci.md` describes the new assert step accurately.
- No hostnames, secrets or tokens were added.
- The plain `docker build -t apotheke .` in getting-started has no `--pull` (N-1).
- The CHANGELOG line is accurate: the image builds on 8.3.36, the security release of 21 Oct 2026, and it tells
  self-hosters to rebuild with `docker compose build --pull`.
- `scripts/check-commits.sh` passes both subjects (`fix(docker): …` is 48 characters, `ci(docker): …` is 54),
  and the merge commit is skipped.
- In a local what-if (a throwaway repo copying the PR history, tag `v2.5.6`), `scripts/release.php plan` gives
  `bump=patch`, `next=2.5.7`, `release=true`. `apply` writes VERSION 2.5.7 and a 2.5.7 section: under Fixed
  "docker: pin the PHP 8.3.36 security release", under Maintenance the ci commit. The hand-written docker line
  stays under `[Unreleased]` (L-1).

### 6. Lint and unit suite: pass

- `scripts/lint.sh` passes: PHP lint, JS syntax, 188 Markdown links with none broken, shellcheck and the
  pipefail-safe grep check.
- `actionlint` on `ci.yml` is clean.
- PHPUnit unit suite on local PHP 8.4: `Tests: 337, Assertions: 2352, Skipped: 16`, no failures. The skips are
  environment-only: QR decoder round trips (no zbar on the box), PostgreSQL-only tests and backup tests across
  drivers.

### 7. CI run 39849920575: green

Completed at 23:45 EEST.

| Job | Result |
|---|---|
| Conventional Commits | success |
| Lint | success |
| Secret scan | success |
| Tests (PHP 8.3, sqlite) | success |
| Tests (PHP 8.3, pgsql) | success |
| Headless mobile checks | success |
| Docker build + smoke test | success |
| Visual E2E shard 1/3 | success |
| Visual E2E shard 2/3 | success |
| Visual E2E shard 3/3 | success |

In the Docker job, step 6 "Assert the image runs the pinned PHP patch" ran and printed `PHP_VERSION=8.3.36`. All
later steps were green, including the PostgreSQL smoke, the graceful stop and the Playwright `@smoke` run.

### 8. gitleaks: pass

`gitleaks detect --no-git` on the three commit patches and the PR diff found no leaks. A history scan of the
PR-side commits in the what-if repo also found no leaks.

### 9. feature/ci4 (note for the sync)

`feature/ci4` still floats: `ARG PHP_VERSION=8.3` then `FROM php:${PHP_VERSION}-fpm-alpine`, and CI passes
`PHP_VERSION=8.4` for the test image. `php:8.4.27-fpm-alpine` exists on Docker Hub (pushed 2026-10-22). See L-2.

## Findings

### L-1: the docker CHANGELOG line will stay under [Unreleased] after v2.5.7

`scripts/release.php apply` inserts the generated 2.5.7 section below `[Unreleased]` and leaves the hand-written
bullet where it is (reproduced in the what-if). This is the same thing that happened to the migrate note in 2.5.6,
which #56 had to fix. Either move the line into 2.5.7 in a docs follow-up (it could ride with the v2.5.8 PR), or
make `release.php` move the `[Unreleased]` bullets into the new section. The generated release body
("pin the PHP 8.3.36 security release") is terse. As with 2.5.6, the user-facing line with the `--pull` advice
belongs in the release body.

### L-2: the CI assert and DockerfileTest don't fit feature/ci4's ARG-based FROM (sync heads-up)

On `feature/ci4` both checks fail closed on `FROM php:${PHP_VERSION}-fpm-alpine`, and CI builds the test image
with `PHP_VERSION=8.4`. In the sync:

- pin `ARG PHP_VERSION=8.3.36` (and `8.4.27` where CI passes it);
- have the assert compare against the version actually passed to the build (the ARG default or the build-arg);
- update the test regexes to match.

Nothing is unsafe today, because it fails closed.

### N-1: plain `docker build` has no --pull

`docs/getting-started.md` "Plain `docker run`" still says `docker build -t apotheke .`. Use
`docker build --pull -t apotheke .` or add the same rebuild note.

### N-2: DockerfileTest and the CI assert disagree on edge cases

The test accepts a trailing space or CRLF, which the sed rejects. It also passes when a pinned `FROM` is followed by
a floating stage, which only matters if a multi-stage build is ever added. The test also hard-codes 8.3.36, so a bump
touches the Dockerfile and the test. That is fine as a downgrade guard, but worth saying in the bump comment.

### N-3: the assert's failure message

On a mismatch the step prints only `PHP_VERSION=X` and exits 1. Printing `expected $pin` to stderr would make a red
run self-explanatory. Two matching `FROM` lines also fail with no clear message.

### N-4: a digest pin would be stronger (optional)

`php:8.3.36-fpm-alpine@sha256:…` would make the base immutable. Official patch tags do get rebuilt, for example for
Alpine updates. Both the test and the sed reject `@sha256:` today, and a digest pin adds bump work. The patch-tag pin
plus the built-image assert is a reasonable trade-off.

### N-5: the PR description is stale

It still says Docker wasn't available and that there is no `php -v`. It doesn't mention the `76f4985` assert step.
Also, `docs/ci.md` describes the assert, but the bump-then-`--pull` line itself is only in
`docs/deployment-and-operations.md`. That's fine, but it differs from the PR summary.

## Verdict

**PASS** at `76f4985`. QA is OK to merge pinned to `76f49859281bc51a18c52fe0cd92eabd4f9a5966` once the PM scan
is clean. CI 39849920575 is green. L-1 and L-2 can go in follow-ups (the v2.5.8 PR and the ci4 sync), and the N
items are optional.
