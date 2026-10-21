# QA: apotheke PR #46, build(docker): fetch imagick from a pinned GitHub tarball

- **PR:** [#46](https://github.com/thordin9/apotheke/pull/46) into `main`, by DevOps
- **Head:** `0260ba1dadb082f76100a84a107f9116bf48f711` (unchanged during the review). Base `main` = `c654569`.
- **CI:** run 39643869272, **10/10 jobs green**
- **Verdict: PASS.** No high or medium findings. One low (L-1) and one nit (N-1) are follow-ups and do not block.

## Test plan

1. **Delta and release.**
   - compare_commits `main...head`: ahead/behind, files, line counts.
   - Commit subject against `scripts/check-commits.sh`.
   - `rel_parse()` / `rel_bump()` from `scripts/release.php` on the subject plus a merge commit.
2. **Source authenticity.**
   - Download the archive URL independently (github.com and codeload); compare its sha256 with `IMAGICK_SHA256`.
   - `git get-tar-commit-id` against the `3.8.0` tag ref from the API.
   - Git blob hashes of every file against the upstream tree at that commit.
   - Commit signature and `package.xml` version.
   - File-by-file comparison with the PECL `imagick-3.8.0.tgz`, if pecl.php.net responds.
   - Read GitHub's published archive-stability policy and rate the risk.
3. **Fail-closed.**
   - Unit level: busybox `sha256sum -c -` with a good hash, a mutated hash, an empty hash, a short hash, a corrupted file and a missing file.
   - Real `podman build --no-cache` of the Dockerfile, each mutation stopped before extract or compile:
     - (a) mutated `IMAGICK_SHA256` build arg;
     - (b) one byte appended after the download;
     - (c) a nonexistent tag (404) with `curl -f`;
     - (d) the same 404 with `-f` removed;
     - (e) the `@PACKAGE_VERSION@` `sed` removed, to prove the new version assert bites.
   - Check https, `-f`, retry flags, and that the runtime user setup is unchanged.
4. **Image.**
   - Build with podman.
   - imagick version, `php -m`, ini, `ldd` of `imagick.so`, the apk package set, the module list and the image size against the last PECL-built image of the same Dockerfile (`main` = `46a3adf` Dockerfile, built yesterday).
   - CI's Docker leg with podman: `docker-heic.sh`, `smoke.sh` on SQLite, `docker-checks.sh`, `smoke.sh` on PostgreSQL 17, graceful stop.
   - #37 photo requirement end to end in the image: the original is kept byte-identical, and only a resized, compressed copy is sent. Same harness and cases as the #37 QA, on the new image and on the PECL-built baseline.
   - Imagick resize spot check.
5. **CI, lint, secrets, docs.**
   - Job list and Docker job log.
   - gitleaks on the diff and on the Dockerfile.
   - shellcheck on the extracted `RUN` step and the scripts CI runs; actionlint; hadolint (head against base).
   - Docs read against the Dockerfile, plus a search for stale `pecl` references.

## Results

### 1. Delta and release: PASS

- 1 ahead, 0 behind `main` (`c654569`). One commit: `build(docker): fetch imagick from a pinned GitHub tarball`.
- Files: `Dockerfile` (+15/−3) and `docs/getting-started.md` (+3/−1). Nothing else. Only build-related changes and their doc.
- The PR has no test files. The tests are the new build-time assert (`phpversion('imagick') === IMAGICK_VERSION`) and the existing Docker CI leg. That is adequate for a `build:` change; see 3(e) for proof that the assert bites.
- `check-commits.sh --message`: `ok`.
- `rel_parse()` gives type `build`, scope `docker`, not breaking. `rel_bump()` over that commit plus a `Merge pull request #46 …` commit returns `NULL`, so **no release is cut**. In the next release's notes the commit falls under *Maintenance*.

### 2. Source authenticity: PASS (risk: L-1)

| Check | Result |
|---|---|
| sha256 of `https://github.com/Imagick/imagick/archive/refs/tags/3.8.0.tar.gz` (382,754 B) | `a964e54a441392577f195d91da56e0b3cf30c32e6d60d0531a355b37bb1e1a59` = `IMAGICK_SHA256` |
| Same archive through codeload | Same sha256 |
| `git get-tar-commit-id` | `555bf68b592a8d9d0a0a2f37d1256a8b6cf2d39e` |
| API `git/refs/tags/3.8.0` | Lightweight tag pointing at commit `555bf68…`. The match proves the archive is the tag. |
| Commit `555bf68` | "Prepare 3.8.0 (#733)", 2025-04-10, signature verified by GitHub |
| Git blob sha1 of all 441 files against the upstream tree (recursive, not truncated) | 441/441 match, 0 mismatches |
| `package.xml` | `<release>3.8.0</release>`, date 2025-04-10 |
| PECL `imagick-3.8.0.tgz` (pecl.php.net returned 504 at first, 200 later in the review) | 363,874 B, sha256 `bda67461c854f20d6105782b769c524fc37388b75d4481d951644d2167ffeec6`. Identical to Debian's `php-imagick_3.8.0.orig.tar.gz`. |
| PECL tgz against the GitHub archive, file by file | All 388 PECL files are in the GitHub archive. 387 are byte-identical. The one difference is `php_imagick.h`, where PECL has `"3.8.0"` and GitHub has `"@PACKAGE_VERSION@"`. After the Dockerfile's `sed`, it is **byte-identical to PECL**. The other 53 GitHub-only files are repo extras (CI config, docker/, README, …) that the build doesn't use. |

So the source compiled is exactly the PECL 3.8.0 release source.

**Archive stability.** GitHub's docs ("Downloading source code archives, Stability of source code archives") say:

- For an unchanged tag, the file *contents* stay the same.
- The *compression settings* "may change over time", which would change the outer bytes and so the sha256. GitHub promises at least six months' notice.
- For checksum stability, GitHub recommends release assets.

GitHub reverted one such change in January 2023 after breakage. If it happens again, or if upstream moves the tag, the pinned hash fails the build loudly. It cannot produce a bad image. The risk is availability only: see **L-1**.

### 3. Fail-closed: PASS

**Unit level.** busybox `sha256sum -c -` in `php:8.3-fpm-alpine` (BusyBox 1.37.0):

- good hash: rc 0;
- mutated, empty and short hash: rc 1, `FAILED`;
- one appended byte: rc 1;
- missing file: rc 1.

`sha256sum` is the last command in its pipe, so its status is the pipe's status, and `set -e` stops the `RUN`.

**Real builds** (`podman build --no-cache`):

| Mutation | Build | Stopped at | Extract, `sed` or compile run? |
|---|---|---|---|
| (a) `--build-arg IMAGICK_SHA256=b964…` | rc 1, 8 s | `/tmp/imagick.tar.gz: FAILED` | No (0 compile lines, no `+ mkdir`/`+ tar` trace) |
| (b) `printf x >>` the download | rc 1, 7 s | `FAILED` | No |
| (c) `IMAGICK_VERSION=0.0.0-qa-nonexistent` (404) | rc 22, 32 s | `curl: (22) … error: 404`, after 1 + 5 retries | No; the error page is never hashed or extracted |
| (d) the same 404 without `-f` | rc 1, 7 s | The 404 body is saved, then `sha256sum` reports `FAILED` | No. The pin is the real guard; `-f` makes the error clear. |
| (e) the `@PACKAGE_VERSION@` `sed` removed | rc 1, 53 s | `php -r "exit(phpversion('imagick') === '3.8.0' …)"` | Compiled, then the new assert failed |

**Other checks:**

- The URL is `https://`. `curl -fsSL --retry 5 --retry-all-errors --retry-delay 5 --connect-timeout 20`.
- `-L` redirects to codeload over https. There is no `--proto '=https'` (N-1); the hash pin makes this harmless.
- Only the build runs as root, which is unchanged. The image's runtime user setup is unchanged from `main`: tini, the entrypoint and the nginx and php-fpm masters run as root, and all workers as `www-data` (8 nginx and 2 php-fpm worker processes counted).
- `docker-php-source delete` and `rm /tmp/imagick.tar.gz` leave no source behind. `/usr/src` holds only `php.tar.xz` and its `.asc`, the same as the baseline.

### 4. Image: PASS

| | Baseline (PECL build, `main` Dockerfile) | #46 head |
|---|---|---|
| `phpversion('imagick')` | 3.8.0 | 3.8.0 |
| ImageMagick | 7.1.2-34 Q16-HDRI | 7.1.2-34 Q16-HDRI |
| `php -m` | lists `imagick`; md5 `e1bab222…` | Identical list, same md5 |
| `docker-php-ext-imagick.ini` | `extension=imagick` | Same |
| `imagick.so` `ldd` (`libMagickWand-7.Q16HDRI.so.10`, `libMagickCore…`, 31 libs) | — | Identical set |
| apk packages (89) | md5 `678d5a27…` | Identical |
| Formats, HEIC | 215, HEIC yes | 215, HEIC yes |
| `imagick.so` | 1,506,552 B, not stripped (`.symtab` and `.debug*`) | 485,576 B, stripped (I-1) |
| Image size (podman) | 136,329,547 B | 134,215,017 B (−2.1 MB) |
| Image size (CI step) | 134 MB (#37 runs) | 131 MB |

**CI Docker leg with podman** (with a `docker`→`podman` shim):

- `docker-heic.sh`: ok HEIC, heic round-trip, Imagick white background and sRGB, refusal hides the path.
- `smoke.sh` on SQLite: ok front page 200 and upload 303.
- `docker-checks.sh`: all 25 checks ok.
- `smoke.sh` on PostgreSQL 17 (throwaway container): ok.
- Graceful stop: 0 s, exit 0.

**#37 photo requirement, end to end.** This uses the #37 QA harness and its 13 cases (PNG, GIF, CMYK, grayscale, EXIF orientation 6 with GPS, HEIC). It runs over HTTP inside the image against the test stub, which saves the exact bytes it receives (a one-line QA-only change to the stub, outside the repo). It runs in two modes: *default* (GD first) and *imagick* (`imagecreatefromstring` disabled).

- All invariants hold in both modes:
  - the original is kept, with sha256 = upload, before and after Analyze;
  - the served copy is unchanged;
  - every sent JPEG is different from the upload, the original and the served copy;
  - no metadata segments;
  - no temp files are left.
- Largest side sent: 1024 px. Largest payload: 25,444 B (default) and 18,834 B (imagick). In imagick mode, 11/11 cases went through Imagick.
- The same run on the PECL-built baseline gives **identical results JSON** in both modes. The sent JPEGs (11 and 10, named by sha256) are **byte-identical** between the two images.

**Resize spot check in the container:**

- a 3000×2000 CMYK JPEG → 1024×683 JPEG, 37,381 B;
- a 1800×1200 HEIC → 1024×683 JPEG, 18,640 B.

### 5. CI, lint, secrets, docs: PASS

- **CI run 39643869272 (pull_request, attempt 1): 10/10 success.** Jobs:
  - Lint
  - Tests (PHP 8.3, sqlite)
  - Tests (PHP 8.3, pgsql)
  - Docker build + smoke test
  - Visual E2E shards 1/3, 2/3 and 3/3
  - Headless mobile checks
  - Secret scan
  - Conventional Commits
- **Docker job log:** the `curl` fetch, `/tmp/imagick.tar.gz: OK`, the version assert, `apotheke:ci 131MB`, ok heic round-trip, both smokes, `stopped in 0s with exit code 0`, and Playwright `33 passed`.
- **gitleaks:** no leaks in the diff or the Dockerfile.
- **shellcheck:** clean on the extracted `RUN` step (`-s sh`) and on `docker-heic.sh`, `smoke.sh` and `entrypoint.sh`.
- **actionlint:** clean (no workflow changes).
- **hadolint:** the same 5 warnings on head and base, only shifted by line (DL3018, DL4006, SC2086, DL3064, DL3025). No new ones. DL4006 (pipefail) doesn't matter for the new pipe, because `sha256sum` is its last command.
- **Docs** (`docs/getting-started.md`): accurate. Imagick is built from the GitHub tag, pinned by `IMAGICK_VERSION` and `IMAGICK_SHA256`, and doesn't depend on pecl.php.net. "To upgrade it, change both" is right. The Dockerfile comment matches. The only other `pecl` mentions are in historical QA docs and are left as they are.

## Findings

| ID | Severity | Finding | Suggestion |
|---|---|---|---|
| L-1 | Low (follow-up) | The pin is on a GitHub on-demand *source archive*. GitHub guarantees stable contents for an unchanged tag, but not stable compressed bytes; it promises ≥ 6 months' notice before compression changes. A change, or upstream moving the tag, makes every build fail on the hash. This fails closed, so it is availability only. There is also no second source. | One of the following: **(a)** attach the verified tarball as a release asset in `thordin9/apotheke` (GitHub guarantees release assets stay byte-stable) and fetch that, with the GitHub archive as a fallback under the same pin; **(b)** vendor the 383 KB tarball (or the 364 KB PECL tgz) in the repo and `COPY` it, so there is no network fetch; **(c)** add PECL as a fallback with **its own** pin `bda67461c854f20d6105782b769c524fc37388b75d4481d951644d2167ffeec6`, cross-checked here against Debian's orig tarball. The PECL tgz builds with the same steps, since `--strip-components=1` drops `imagick-3.8.0/` and it needs no `sed`. |
| N-1 | Nit | `curl -L` allows any protocol on redirects. | Add `--proto '=https' --proto-redir '=https'`. This is cosmetic, because the hash pin already covers integrity. |
| I-1 | Info | `docker-php-ext-install` strips `imagick.so` (1.5 MB → 486 KB). The image is 2.1 MB smaller, and the runtime libs and behaviour are identical (byte-identical analysis output). | None. |
| I-2 | Info | `--retry-all-errors` also retries a 4xx, so a bad version takes about 30 s to fail. | None. |
| I-3 | Info | The new version assert proves the `@PACKAGE_VERSION@` substitution worked (mutation (e)). The source's identity comes from the sha256 pin, not from the assert. | None. |

## Evidence

Kept locally by QA, not committed:

- the diff and compare output;
- both tarballs, the PECL and Debian tarballs, and the tree check;
- the build log, the mutation logs and their summary;
- the image comparison, the smoke output and the e2e JSON for head and baseline;
- the CI Docker job log, hadolint output and resize spot check.

All podman images and containers created for this review were removed afterwards.
