# QA: apotheke PR #48, build(docker): add pinned PECL fallback for imagick, https-only curl

- **PR:** [#48](https://github.com/thordin9/apotheke/pull/48) into `main`, by DevOps
- **Head:** `a73815a8e3a04156a85d19b6a77aa5fb1eaa41d2`. Base `main` = `ba4644efe05d4c55f32b58b0bf54b53dfeb45f22` (where #47 merged). The head didn't move during the review.
- **CI:** run 39650110019, **10/10 jobs green**
- **Origin:** this is the fallback work first written as `48f4172` on the closed #46. It follows `docs/qa/apotheke-pr46-imagick-pin-qa.md`, which rated the GitHub archive pin with no second source as low (L-1), and the redirect protocol not restricted to https as a nit (N-1).
- **Verdict: PASS.** It closes L-1 and N-1, and P-1 is fixed by #48's subject. There are no high or medium findings.

## Test plan

1. **Delta.**
   - Head, base and ahead/behind against `main`. Files changed.
   - The full tree against `48f4172`. If anything other than the announced comment differs, retest it with podman.
   - The subject against `scripts/check-commits.sh` and CI.
2. **Code review.**
   - `--proto '=https' --proto-redir '=https'`.
   - The order: GitHub against `IMAGICK_SHA256` first, then PECL against `IMAGICK_PECL_SHA256`.
   - The PECL pin value.
   - The log line naming the source.
   - The error path.
   - `set -e` inside an `if` condition.
   - POSIX sh compatibility.
3. **Forced-source podman builds** (`--no-cache`, on the identical code in `48f4172`):
   - default (GitHub);
   - GitHub pin broken (PECL);
   - GitHub URL broken with a 404 (PECL);
   - both pins broken;
   - a leak case: the first slot downloads the real PECL bytes but fails the GitHub pin, and the PECL slot is a 404;
   - the same leak case with `rm -f` removed.
4. **Unit test of `fetch()`** in busybox `sh` with a stub curl: 404, partial transfer (curl 18), wrong bytes, and both sources failing.
5. **Images from each source.**
   - phpversion, ImageMagick version, HEIC support, the `php -m`, apk and `ldd` sets, and the `imagick.so` bytes.
   - The PECL layout under `--strip-components=1`, and that the `sed` is a no-op on the PECL file.
6. **Default image.**
   - `docker-heic.sh`, `smoke.sh` on SQLite, `docker-checks.sh` and graceful stop.
   - #37 end to end: the original is kept unchanged and only a resized copy is sent.
7. **CI, lint and secrets.**
   - CI jobs and the Docker job log.
   - shellcheck (`sh` and `dash`), `dash -n`, hadolint, and gitleaks on the delta.

## Results

### 1. Delta: PASS

- `main...a73815a`: 1 ahead, 0 behind. One commit, whose parent is `ba4644e`.
- Files: `Dockerfile` (+19/−5) and `docs/getting-started.md` (+3/−2). Nothing else.
- **Against `48f4172`:** the whole tree is identical except one added Dockerfile comment line, above the `ARG` lines and outside the `RUN`:

  ```
  # set -e does not apply inside fetch() because it runs in an if, so keep its && chain.
  ```

  `docs/getting-started.md` is byte-identical. So the podman results below, run on `48f4172`, carry over unchanged.
- `docs/qa/apotheke-pr46-imagick-pin-qa.md` is unchanged.
- **Subject:** `build(docker): add pinned PECL fallback for imagick, https-only curl`, 68 characters. `check-commits.sh --message` gives `ok`, and the CI job logs `ok a73815a: …` with `1 commit(s) checked`.
- **Release:** `build` doesn't bump the version in `scripts/release.php`, so the merge cuts no release.

### 2. Code review: PASS

- **https only.** `curl -fsSL --proto '=https' --proto-redir '=https' --retry 5 --retry-all-errors --retry-delay 5 --connect-timeout 20`. Both URLs are `https://`. Closes N-1.
- **Order.**
  1. GitHub archive, checked against `IMAGICK_SHA256` (`a964e54a…1a59`).
  2. `https://pecl.php.net/get/imagick-${IMAGICK_VERSION}.tgz`, checked against `IMAGICK_PECL_SHA256`.
  3. Otherwise `echo "imagick: no source matched its sha256 pin" >&2; exit 1` before `mkdir` or `tar`.
- `IMAGICK_PECL_SHA256` = `bda67461c854f20d6105782b769c524fc37388b75d4481d951644d2167ffeec6`. That is the value verified in the #46 QA, and it matches Debian's orig tarball.
- **Log line:** `imagick source: github` or `imagick source: pecl`.
- **Error handling.** A new header comment says `set -e` doesn't apply inside `fetch()` and to keep its `&&` chain. `fetch()` runs `rm -f`, then `curl … && echo … | sha256sum -c -`. Because it's called as an `if`/`elif` condition, `set -e` is suspended inside it, and the `&&` chain carries the status: a curl failure skips the hash check and returns non-zero. `sha256sum` is the last command in its pipe, so its status is the pipe's status.
- **POSIX.**
  - `shellcheck -s sh` and `-s dash` are clean on the extracted `RUN` step, and `dash -n` passes.
  - The image's `/bin/sh` is busybox ash, which handles the `fetch() { …; }` definition and the `'=https'` quoting. All the builds below confirm it.
  - Docker doesn't expand `$1` and `$2` inside `RUN`.
- **Docs.** `docs/getting-started.md` describes the two sources, the two pins and "the build fails if neither download matches", and says to change all three ARGs to upgrade. That is accurate. The Dockerfile comment explains the `sed` no-op on PECL.

### 3. Forced-source builds: PASS

| Build | rc | Log | Extract and compile |
|---|---|---|---|
| Default | 0 (67 s) | `/tmp/imagick.tar.gz: OK`, then `imagick source: github` | Yes |
| `IMAGICK_SHA256` mutated | 0 (76 s) | GitHub `FAILED`, then PECL `OK`, then `imagick source: pecl` | Yes, from PECL |
| GitHub URL → nonexistent tag | 0 (85 s) | `curl: (22) … 404` ×6 (1 + 5 retries), PECL `OK`, `imagick source: pecl` | Yes, from PECL |
| Both pins mutated | **1** (7 s) | GitHub `FAILED`, PECL `FAILED`, `imagick: no source matched its sha256 pin` | **No**: no `mkdir`/`tar` trace, 0 compile lines |
| Leak: GitHub slot downloads the real PECL bytes (fails the GitHub pin), PECL slot 404 | **1** (33 s) | `FAILED`, then 404 ×6, then `no source matched` | No. The leftover valid PECL file was not used. |
| Same leak case with `rm -f` removed | **1** (34 s) | Same | No. The `&&` chain alone prevents reuse, so `rm -f` is defence in depth. |

### 4. `fetch()` unit test (busybox sh, stub curl): PASS

| Case | Result |
|---|---|
| Both sources good | github |
| GitHub 404 | pecl |
| GitHub partial transfer (curl exit 18) | pecl |
| GitHub wrong bytes | pecl |
| GitHub partial with otherwise *matching* bytes, PECL 404 | no source matched, exit 1. A curl error is never accepted, even when the hash would match. |
| GitHub serves the bytes PECL's pin expects, PECL 404 | no source matched, exit 1. The other source's pin is never applied. |
| Both 404 | no source matched, exit 1 |

### 5. Images by source: PASS

| | github | pecl (pin broken) | pecl (URL broken) |
|---|---|---|---|
| `phpversion('imagick')` | 3.8.0 | 3.8.0 | 3.8.0 |
| ImageMagick, HEIC, formats | 7.1.2-34 Q16-HDRI, yes, 215 | Same | Same |
| `php -m` md5 | `e1bab222…` | Same | Same |
| apk set md5 (89 packages) | `678d5a27…` | Same | Same |
| `ldd imagick.so` set | Same across all three | | |
| `imagick.so` sha256 | `0312bcbe…9dda` (485,576 B) | **Identical** | **Identical** |
| Leftovers | `/usr/src` holds only `php.tar.xz` and its `.asc`; `/tmp` is empty | Same | Same |

The `php -m` and apk hashes are the same as for the #46 head image and the earlier PECL-built image.

**PECL layout**, extracted in the image with the Dockerfile's `tar -xzf … --strip-components=1`:

- 388 files, with `config.m4` at the root.
- PECL's top-level `package.xml` is dropped, which is harmless because `docker-php-ext-install` doesn't use it.
- `php_imagick.h` already says `"3.8.0"`, and the `sed` leaves it byte-identical (`cmp`).

### 6. Default image: PASS

- `docker-heic.sh`: ok HEIC, heic round-trip, white background and sRGB, refusal.
- `smoke.sh` on SQLite: front page 200 and upload 303.
- `docker-checks.sh`: all ok.
- Graceful stop: exit 0.
- **#37 end to end**, with the same harness and cases as the #46 QA:
  - all invariants hold in both modes: the original sha256 equals the upload before and after Analyze, the served copy is unchanged, every sent JPEG differs from the upload, the original and the served copy, there are no metadata segments and no temp files are left;
  - largest side 1024 px, payloads ≤ 25,444 B;
  - the sent JPEGs (11 default, 10 imagick) are byte-identical to the PECL-built baseline;
  - the application code is unchanged from `main`.

### 7. Lint and secrets: PASS

- **gitleaks:** clean on the delta and the Dockerfile.
- **hadolint:** the same 5 warnings as `main` (DL3018, DL4006, SC2086, DL3064, DL3025), nothing new.
- **shellcheck and dash:** see 2.

### 8. CI: PASS

Run 39650110019 (pull_request, attempt 1): **10/10 success**. Jobs:

- Lint
- Tests (PHP 8.3, sqlite)
- Tests (PHP 8.3, pgsql)
- Docker build + smoke test
- Visual E2E shards 1/3, 2/3 and 3/3
- Headless mobile checks
- Secret scan
- Conventional Commits

The Docker job log shows:

- `/tmp/imagick.tar.gz: OK`, then `imagick source: github`;
- `apotheke:ci 131MB`, the same as #46;
- ok heic round-trip, and both smokes `ok front page 200`;
- `stopped in 0s with exit code 0`;
- Playwright `33 passed`.

## Findings

| ID | Severity | Finding | Status |
|---|---|---|---|
| L-1 (from #46) | Low | The GitHub tag archive was the only source, and its bytes aren't guaranteed stable. | **Closed.** There is now a second source with its own verified pin, and the build fails closed when neither matches. |
| N-1 (from #46) | Nit | curl redirects weren't restricted to https. | **Closed.** `--proto '=https' --proto-redir '=https'`. |
| P-1 (from `48f4172`) | Medium | The subject was 78 characters against a 72 maximum, so *Conventional Commits* was red. | **Closed.** #48's subject is 68 characters, and CI is green. |
| I-1 | Info | `set -e` is suspended inside `fetch()`, so correctness depends on the `&&` chain. | Covered by the new header comment. |
| I-2 | Info | When both sources are down, the build takes about 60 s to fail (two sources × retries). | None. |

## Evidence

Kept locally by QA, not committed:

- the forced-source build logs and their summary;
- the `fetch()` unit test;
- the `imagick.so` copies, the layout check, the image comparison and the smoke output;
- the e2e JSON, hadolint and gitleaks output;
- #48's delta and CI job logs.

All podman images and containers created for this review were removed afterwards.
