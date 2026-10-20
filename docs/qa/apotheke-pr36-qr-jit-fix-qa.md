# QA: apotheke PR #36, `fix(qr): disable function JIT before the encoder loads` (into `main`, releases v2.5.3)

> **Status: FINAL.** Covers the full QA at `c22d4c9` and the delta recheck at `b954a5e` (see the last Results section).

- **PR:** [#36](https://github.com/thordin9/apotheke/pull/36) (draft, into `main`)
- **Head:** `b954a5e6434f9fc64da3f83cba4fad121ac97903` (final). The full QA ran at
  `c22d4c95e1a6e26140e190eb8619729b086e2870`, and the delta `c22d4c9..b954a5e` was rechecked. Base: `main` at
  `42f497e28d5bacaa5642c28882747cf971f83fea` (v2.5.2)
- **Compared with:** `feature/ci4` at `d94465fb578ca8f5af5c08badae5fc3fd59c8a55`
- **CI:** run 39427196000 (`c22d4c9`), run 39430719814 (`b954a5e`)
- **Date:** 2026-10-20 (Europe/Athens). No live service calls, no credentials.

## Test plan

1. **Scope.** Diff `42f497e...c22d4c9` from codeload tarballs checked with `git get-tar-commit-id`.
   - `src/qr.php` must be only the guard plus `require_once`.
   - `src/qr-encode.php` must be byte-identical to the encoder in `main`'s `src/qr.php`.
   - Compare the guard and its split with `feature/ci4`.
   - `docker/php.ini` may only gain a comment.
2. **Behaviour on PHP 8.2, 8.3 and 8.4.**
   - Run the 240-code harness (ECC L/M/Q/H, versions 1–40, every module hashed) on `main` and on the head.
   - Test 19 `opcache.jit` values: function modes, tracing modes and off modes.
   - Run php-fpm in the 8.3 image with `opcache.jit=1235`, against the head and against `main`'s `src/` as a control.
3. **Tests.**
   - `testJitModeClassification` and `testFunctionJitMatchesInterpreter` must be present.
   - Mutants, on 8.3 and 8.4: (A) the guard's `ini_set` removed, (B) the old `125` prefix check, (C) the old 8.4-only
     check, and (D) the guard removed plus the `disable` assertion removed, so only the matrix check is left.
4. **Suites.**
   - PHPUnit with and without `opcache.jit=1235`, on host PHP 8.4.26 and in a PHP 8.3.35 image.
   - `tests/run.sh` on 8.4 and `tests/integration.php` on 8.3.
5. **CI run 39427196000.** PHP versions, whether the JIT tests ran or were skipped, and the Docker leg with
   Imagick/HEIC. The image's opcache settings.
6. **Trial merge into `feature/ci4` `d94465f`.** List the conflicts, check a resolution, and run the CI4-specific tests.
7. **Docs.** Accuracy, the link checker, actionlint, gitleaks on the delta, and the loopback test URL.
8. **Release.** Simulate `scripts/release.php plan` and `apply` on a replay of the merge.
9. **Delta recheck `c22d4c9..b954a5e`.**
   - Only `cd81290` and `b954a5e`, docs and comments only, with code identical after stripping comments.
   - Re-run the JIT tests on 8.3 and 8.4.
   - Replay `release.php` with all three commits.
   - Check the post-release note in the PR description against the measurements.
   - CI run 39430719814, the link checker, actionlint and gitleaks on the delta.

## Results

### 1. Scope (PASS)

- Changed files: `src/qr.php`, the new `src/qr-encode.php`, `tests/Unit/QrCodeTest.php`, `scripts/coverage-readme.php`
  (adds `src/qr-encode.php` to the QR coverage area), `docker/php.ini`, `CHANGELOG.md`, `README.md`, `AGENTS.md`,
  `docs/getting-started.md`, `docs/configuration.md` and `docs/architecture.md`. Nothing else changed.
- **`src/qr-encode.php`** is byte-identical to `main`'s encoder, header comment included, and to `feature/ci4`'s
  `src/qr-encode.php`.
- **`src/qr.php`** is now just a comment, `qr_php84_jit_is_tracing()`, the guard closure and
  `require_once __DIR__ . '/qr-encode.php'`.
- **Compared with `feature/ci4`:**
  - The detection function is identical.
  - The only code difference is that the `PHP_VERSION_ID < 80400` early return is gone, so the guard runs on every PHP
    version.
  - The comment explains that difference.
  - The test adds a `0205` case and drops the PHP 8.4 skip.
- **`docker/php.ini`:** the only change is one comment line, and the settings are identical with comments stripped.
  Loading the head's ini in the 8.3 image gives `opcache.enable=1`, `opcache.jit=tracing` and
  `opcache.jit_buffer_size=0`, so JIT stays off. The image built from the head reports the same: PHP 8.3.35,
  `jit=tracing`, `buf=0`.

### 2. Behaviour (PASS)

The interpreter hash is `502d18ad` on 8.2, 8.3 and 8.4.

| `opcache.jit` | 8.2.34 main | 8.2 head | 8.3.35 main | 8.3 head | 8.4.26 main | 8.4 head |
|---|---|---|---|---|---|---|
| `0`, `off`, `disable` | ok, JIT off | untouched, ok | ok, JIT off | untouched, ok | ok, JIT off | untouched, ok |
| `1235` | **wrong** | disabled, ok | **wrong** (1/240: L1, same mask, different bits) | disabled, ok | **hangs** | disabled, ok |
| `0235` | **wrong** | disabled, ok | **wrong** | disabled, ok | **hangs** | disabled, ok |
| `1205`, `0205`, `function` | ok | disabled, ok | ok | disabled, ok | **wrong** | disabled, ok |
| `1215` | n/r | n/r | ok | disabled, ok | **wrong** | disabled, ok |
| `1225`, `1201` | n/r | n/r | ok | disabled, ok | ok | disabled, ok |
| `205` | n/r | n/r | ok | disabled, ok | **wrong** | disabled, ok |
| `1254`, `1255`, `0255`, `1155`, `1055`, `tracing`, `on` | ok | stays on, ok | ok | stays on, ok | ok | stays on, ok |

(n/r = not run on 8.2. That run covered `disable`, `off`, 1205, 1235, 0205, 0235, `function`, 1255, `tracing` and `on`.)

- Every function mode is guarded, every tracing mode stays on, and the off modes are left alone. After the fix, every
  mode matches the interpreter on all three versions.
- PHP 8.2 is affected too. The guard covers it, since it isn't version-limited.
- **php-fpm, 8.3 image built from the head, 4× `/labels` each:**

  | Setup | Result |
  |---|---|
  | default (JIT off) | 200, SVG hash `74901f85` |
  | head with `opcache.jit=1235` | 200, `74901f85` on every request |
  | control: `main`'s `src/` with `opcache.jit=1235` | **first request serves a different label (`36c242d0`)**; later ones `74901f85` |

  So the bug shows up in a real php-fpm deployment on 2.5.2 with function JIT, and the head fixes it.

### 3. Tests (PASS)

- Both tests are present, and they pass on 8.3 and on 8.4 (`OK (2 tests, 64 assertions)`).
- `testFunctionJitMatchesInterpreter`:
  - Must be disabled and match the interpreter: 1235, 1205, 1215 and `function`.
  - Must stay on and match: `tracing`, 1254, 0255 and `on`.

| Mutant | PHP 8.4 | PHP 8.3 |
|---|---|---|
| A: guard `ini_set` removed | **fails**: 1235 hangs ("exited 255 … Maximum execution time") | **fails**: "1235 is disabled before encode" |
| B: old `125` prefix | **fails** both tests ("0255 stays tracing") | **fails** both tests |
| C: old 8.4-only check | passes (expected) | **fails**: "1235 is disabled before encode" |
| D: guard removed, `disable` assertion removed | **fails** (hang) | **fails**: "opcache.jit=1235 matrix" |

The test payloads do hit the PHP 8.3 miscompile (mutant D), so the behavioural check doesn't just rest on the
`disable` assertion.

### 4. Suites (PASS)

| Where | PHPUnit | PHPUnit, `opcache.jit=1235` | Integration |
|---|---|---|---|
| Host PHP 8.4.26 | 279 tests, 8 skipped (pgsql ×2, zbarimg ×6) | 279 tests, 8 skipped | `tests/run.sh`: lint OK, **1423 passed, 0 failed** |
| PHP 8.3.35 image | 279 tests, 9 skipped (adds php-cgi) | 279 tests, 9 skipped | **1423 passed, 0 failed** |

Coder reported 278 passing, 5 skipped and 1424 integration. The counts depend on the environment: CI has 279 in the
PHPUnit step, 278 in the coverage step, and 1425 integration. All of these pass.

### 5. CI run 39427196000 (PASS)

- A `pull_request` run on `c22d4c9`, from 16:03:34 to 16:12:12 (Europe/Athens). **10/10 green.**
- `main` has only PHP 8.3 legs; 8.4 is covered locally (§2–4).
- **Tests 8.3 sqlite:** setup-php 8.3.35, `OK (279 tests, 2056 assertions)` with **no skips**, so both JIT tests ran.
  The coverage run (PCOV) gave `OK (278 tests)`, integration gave 1425 passed, and `coverage-sqlite` was uploaded.
- **Tests 8.3 pgsql:** `OK (279 tests, 2057 assertions)`, also no skips.
- **Lint:** 8.3.35, 186 relative links in 16 files, 0 broken.
- **Headless mobile:** PHP 8.3.35 dev server, "labels sheet shows QR codes" passes.
- **Docker build + smoke:**
  - `FROM php:8.3-fpm-alpine@sha256:454b…`. The Imagick/libheif layer was **cached**: the Dockerfile is unchanged and
    `php.ini` is copied later.
  - `docker-heic.sh`: `ok HEIC`, `ok heic round-trip`, `ok refusal`.
  - Smoke, docker-checks and Playwright `@smoke` pass.
- **Playwright** ×3, **Conventional Commits** and **Secret scan** all succeeded.

### 6. Trial merge into `feature/ci4` `d94465f` (see I-3)

- The merge base is `42f497e`. `src/qr-encode.php` is an add/add of identical content, so it doesn't conflict.
- **7 files conflict, including `src/qr.php` and `tests/Unit/QrCodeTest.php`**, because both branches edited the same
  lines. The others are `AGENTS.md`, `CHANGELOG.md`, `README.md`, `docs/architecture.md` and `docs/getting-started.md`.
- **Resolution I verified:**
  - Take #36's side in the `qr.php` and `QrCodeTest.php` hunks. The resulting files are byte-identical to #36's, which
    is a superset: no 8.4-only check, plus `0205`.
  - Take #36's wording in the README, architecture and AGENTS hunks. CI4's other lines, such as `parity/`, are kept.
  - Union the CHANGELOG hunk. In getting-started, keep CI4's `PHP_VERSION` sentences and add "JIT is left off".
  - With that resolution, QrCode, Ci4BranchCi, LinkCheck and ParityRecorder pass (34 tests, 6 skipped), and the link
    check gives 228 links in 35 files, 0 broken.

### 7. Docs, links, gitleaks (PASS, see N-1 and I-4)

- **Docs:**
  - The README requirements, getting-started (image and "Without Docker"), configuration, architecture, AGENTS.md and
    the coverage map match the code.
  - Covered modes: four-digit CRTO values with a trigger digit other than 5, and `function`.
  - Tracing stays on, and the guard acts for the whole worker from the first request.
  - The negligible-cost and `opcache_jit_blacklist()` statements match the #34 measurements.
  - The image does not enable JIT.
  - "PHP 8.3 with `opcache.jit=1235` miscompiled 1 of 240 codes; PHP 8.4 can also hang" matches §2.
- **Checks:** link checker 186/16/0, actionlint clean, gitleaks 8.30.1 finds **0 leaks** on `42f497e..c22d4c9`.
- **Added URLs:**
  - `https://catalog.example/?loc=3` (reserved example domain).
  - `http://127.0.0.1:9091/?loc=1` in `QrCodeTest.php` (I-4).

### 8. Release (version PASS, notes see M-1)

- I replayed it with a local repo: `main` tagged `v2.5.2`, the PR commit, then `Merge pull request #36 …` with
  `--no-ff`.
- `release.php plan` gives `current=2.5.2 last_tag=v2.5.2 commits=1 bump=patch next=2.5.3 release=true`.
- `apply` writes `VERSION` 2.5.3, the compare links `[Unreleased]: …/compare/v2.5.3...HEAD` and
  `[2.5.3]: …/compare/v2.5.2...v2.5.3`, and this section:

  ```
  ## [2.5.3] - 2026-10-20
  ### Fixed
  - **qr:** disable function JIT before the encoder loads ([#36](…))
  ```

  The GitHub release notes contain only that line.
- **The hand-written known-issue note stays under `## [Unreleased]`**, above `[2.5.3]`. `rel_changelog_insert()`
  keeps anything written under Unreleased by hand, and the section is built from commit subjects (M-1).

### 9. Delta recheck `c22d4c9..b954a5e` (PASS, see L-2, N-3 and N-4)

- **Commits:** the PR now has `c22d4c9` → `cd81290` `docs(qr): name PHP 8.2, 8.3 and 8.4 as affected` →
  `b954a5e` `docs(changelog): drop the unreleased QR note`. Each has one parent, and both tarballs pass
  `git get-tar-commit-id`. The PR is still a draft into `main` and is mergeable (`clean`).
- **`cd81290`** touches `README.md`, `docs/getting-started.md`, `docs/configuration.md`, `AGENTS.md`, plus comments in
  `src/qr.php` (the header block) and `tests/Unit/QrCodeTest.php` (the docblock of `testFunctionJitMatchesInterpreter`).
  - `php -w` (comments and whitespace stripped) gives identical output for `src/qr.php`, `src/qr-encode.php` and
    `QrCodeTest.php` at `c22d4c9` and `b954a5e`. **No code changes.**
- **`b954a5e`** removes the `### Fixed` note from `[Unreleased]`. `CHANGELOG.md` is now byte-identical to `main`'s, so
  `[Unreleased]` is empty. That is option (a) for M-1.
- **JIT tests at `b954a5e`:** `testJitModeClassification` and `testFunctionJitMatchesInterpreter` pass on host PHP
  8.4.26 and in the PHP 8.3.35 image. Both give `OK (2 tests, 64 assertions)`.
- **Release replay:** a local repo with `main` tagged `v2.5.2`, then the three PR commits, then a `--no-ff`
  `Merge pull request #36 …`.
  - `plan` gives `current=2.5.2 last_tag=v2.5.2 commits=3 bump=patch next=2.5.3 release=true`.
  - `apply` writes `VERSION` 2.5.3 and the same compare links as before. `[Unreleased]` stays empty, so no
    hand-written content is left behind.
  - The section, and the release notes, which add `**Full changelog:** …/compare/v2.5.2...v2.5.3`:

    ```
    ## [2.5.3] - 2026-10-20
    ### Fixed
    - **qr:** disable function JIT before the encoder loads ([#36](…))
    ### Maintenance
    - **qr:** docs: name PHP 8.2, 8.3 and 8.4 as affected ([#36](…))
    - **changelog:** docs: drop the unreleased QR note ([#36](…))
    ```

  - The `docs:` commits don't change the bump, but they do add two Maintenance lines. That follows the project's
    practice (`[2.5.2]` lists `docs:` commits from #32 the same way), but the second line mentions a note that was never
    released (N-3).
- **Post-release note in the PR description:** these parts are accurate:
  - The guard text: whole worker from the first request, negligible cost, `opcache_jit_blacklist()` doesn't help,
    tracing stays on, and the Docker image leaves JIT off.
  - The 8.4 hang, and the merge checklist (a `docs(changelog):` PR adding the note under `## [2.5.3]`, plus
    `gh release edit v2.5.3`).

  But "PHP 8.2, 8.3 and 8.4 with `opcache.jit=1235` miscompiled 1 of 240 codes" is only true for 8.2 and 8.3. Re-measured
  today on `main`, 8.2.34 is wrong on 1 of 240 under both `1235` and `0235`, and 8.3.35 on 1 of 240 (§2). On 8.4.26,
  `1235` and `0235` hang rather than miscompile, and `1205`, `1215`, `0205`, `205` and `function` get **240 of 240**
  wrong (re-measured today for `1205` and `function`) (L-2).
- **Correction to §2:** the first version of the table listed `205` on 8.4 `main` as ok. The raw log
  (`logs/modes-84.txt`) shows it wrong, like `1205`. The table is now fixed. Either way the guard disabled `205` at the head.
- **In-repo wording (`cd81290`):**
  - README, getting-started and configuration are accurate: affected versions are listed, and the guard is not
    described as version-limited.
  - In `AGENTS.md` ("On PHP 8.2, 8.3 and 8.4, turns function JIT off") and the `qr.php` comment ("The guard runs on
    PHP 8.2, 8.3 and 8.4"), the guard reads as version-limited, but it has no version check. The `qr.php` comment
    also says `1235` "can emit a wrong matrix" on 8.4, where it hangs (N-4).
- **CI run 39430719814:**
  - A `pull_request` run on `b954a5e`, from 16:28:42 to 16:36:59 (Europe/Athens). **10/10 green.**
  - Tests 8.3 sqlite: PHP 8.3.35, `OK (279 tests, 2056 assertions)` with no skips, so the JIT tests ran. Coverage gave
    `OK (278 tests)`, integration gave 1425 passed, 0 failed, and `coverage-sqlite` was uploaded.
  - Tests 8.3 pgsql: `OK (279 tests, 2057 assertions)`.
  - Lint: 186 relative links in 16 files, 0 broken.
  - Docker: `imagick-3.8.0`, `docker-heic.sh` and smoke passed.
  - Playwright ×3, headless mobile, Conventional Commits and Secret scan all succeeded.
  - The counts match run 39427196000.
- **Local checks:**
  - Link checker at `b954a5e`: 186 links in 16 files, 0 broken. actionlint is clean.
  - gitleaks 8.30.1 on the two delta commits: **no leaks**.
  - The delta adds no URLs, IPs or credentials.

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| M-1 | Medium, **closed via (a)** (PM, for #36 and #37) | The v2.5.3 release won't carry the 2.5.x known-issue note. `scripts/release.php` builds `[2.5.3]` and the GitHub release notes from commit subjects only. The hand-written `### Fixed` note stays under `[Unreleased]`, so after the release it reads as unreleased and has to be cleaned up by hand, like the stale entries #32 N-1 cleared. The PM asked for v2.5.3's changelog to carry the note. | Pick one before merge. **(a)** Smallest, no tooling change: take the note out of `[Unreleased]` in #36. After the release, a `docs(changelog):` commit (no release) adds it under `## [2.5.3]`, and `gh release edit v2.5.3 --notes-file …` appends it to the release notes. Record both steps as merge checklist items. **Closed:** `b954a5e` empties `[Unreleased]`, and the PR's merge checklist has both post-release steps. QA verifies the `docs(changelog):` PR and the `gh release edit v2.5.3` at the tag check. **(b)** Tooling: a separate `fix(release):` PR, merged first, that moves hand-written `[Unreleased]` content into the new section and the notes, with a unit test. That changes every future release, including how `feature/ci4`'s Unreleased Maintenance lines would land. (Pre-writing `## [2.5.3]` by hand is not an option, because `apply` refuses with "CHANGELOG.md already has 2.5.3".) |
| N-1 | Nit (docs) | PHP 8.2 is a supported minimum (README "PHP 8.2+"), and it is affected too: 8.2.34 is wrong under 1235 and 0235. The guard covers it, but the CHANGELOG, README and configuration notes only name 8.3 and 8.4. | Say "PHP 8.2+ (8.3 with `opcache.jit=1235` …)" in the note. **Closed** by `cd81290` (README, getting-started, configuration, AGENTS.md, comments). |
| N-2 | Nit | The helper is called `qr_php84_jit_is_tracing()`, but on `main` the guard covers every version. | Keep the name for now so it matches `feature/ci4`, and rename it on both later (follow-up). **Deferred** (PM) to the next `main` → `feature/ci4` sync. |
| I-3 | Info (sync) | The next `main` → `feature/ci4` sync conflicts in 7 files, including `src/qr.php` and `QrCodeTest.php`. | Take #36's side for `qr.php` and `QrCodeTest.php` (§6). That also removes the 8.4-only check from `feature/ci4`. **PM guidance:** at the next sync, take #36's side in `qr.php` and `QrCodeTest.php`, and union the docs. |
| I-4 | Info (for the PM) | `http://127.0.0.1:9091/?loc=1` is only a string the test encodes into a QR matrix. Nothing connects to it, it is loopback, and it is the same address the repo's mobile check and scripts already use (README, `smoke.sh`, `mobile.mjs`). It exposes no host. | Harmless. Changing it to `http://catalog.example/…` would also work. The PM decides. **Kept** (PM). |
| L-2 | Low (follow-up, fix before the post-release steps) | The PR's post-release note says "PHP 8.2, 8.3 and 8.4 with `opcache.jit=1235` miscompiled 1 of 240 codes". On 8.4, `1235` hangs, and other function modes (`1205`, `function`) get 240 of 240 wrong (§9). It isn't in the repo yet, but it would go into `[2.5.3]` and the release body as written. | Use: "PHP 8.2 and 8.3 with `opcache.jit=1235` miscompiled 1 of 240 codes; on PHP 8.4 that mode can hang `GET /labels`, and other function-JIT modes such as `1205` wrote wrong codes." QA checks the final text at the tag check. |
| N-3 | Nit (release) | The generated `[2.5.3]` and release notes list two `docs:` Maintenance lines, including "drop the unreleased QR note", which refers to a note that was never released. This matches how `[2.5.2]` lists #32's `docs:` commits. | Optional: the post-release `docs(changelog):` PR and `gh release edit` can drop or reword that line when they add the note. |
| N-4 | Nit (comments) | `AGENTS.md` and the `qr.php` comment say the guard runs "on PHP 8.2, 8.3 and 8.4", but it has no version check. The `qr.php` comment also says `1235` can emit a wrong matrix on 8.4, where it hangs. | Fix with the N-2 rename at the next sync, for example "on every PHP version (affected: 8.2–8.4)". |

No H findings. M-1 is closed via (a), so nothing blocks. L-2, N-2, N-3 and N-4 are follow-ups.

## Verdict

**PASS: ready to merge. Merging releases v2.5.3.**

- **Code: PASS** (full QA at `c22d4c9`).
  - The split is byte-identical, and the guard matches `feature/ci4` minus the 8.4-only check.
  - Every function-JIT mode is guarded on 8.2, 8.3 and 8.4, tracing stays on, and off modes are untouched.
  - The bad 8.2/8.3 code and the 8.4 hang reproduce on `main` and are fixed at the head, including under php-fpm.
  - The tests fail on all relevant mutants, the suites pass, the Docker image keeps JIT off, and gitleaks is clean.
- **Delta `c22d4c9..b954a5e`: PASS.**
  - Docs and comments only, with code identical after stripping comments.
  - The JIT tests pass on 8.3 and 8.4, and CI 39430719814 is 10/10 with the JIT tests running.
  - Links: 186/16/0. gitleaks: no leaks.
- **Release: PASS.**
  - `fix(qr)` → patch → **v2.5.3**, with `[Unreleased]` empty after `apply`.
  - The two `docs:` commits add Maintenance lines but don't change the bump (N-3).
- **PM decisions recorded:**
  - M-1 closed via (a), for #36 and #37.
  - N-1 closed.
  - N-2 deferred to the next sync.
  - I-3 sync guidance: take #36's side in `qr.php` and `QrCodeTest.php`, and union the docs.
  - I-4 kept.
- **At the tag check, QA verifies:**
  - v2.5.3 is tagged and `VERSION` reads 2.5.3.
  - The `docs(changelog):` PR adds the known-issue note under `## [2.5.3]`, with the 8.4 wording corrected per L-2.
  - `gh release edit v2.5.3` puts the same note into the release body.

Evidence on the QA box is in `/workspace/apotheke-qa/pr36/`: the tarballs, `t/`, `logs/modes-8{2,3,4}.txt`,
`codes-83-main-1235.txt`, `mutants-8{3,4}.txt`, the suite logs, CI job logs, `fpm-83.txt`, `trial/` (merge into
`feature/ci4`), `relsim/` (release replay), `release-*.md`/`.txt` and gitleaks. The delta recheck is in `delta/`: the
`c1.diff`/`c2.diff` diffs, `rel/` (release replay), and `logs/` with the JIT tests, 8.2/8.4 code counts, CI logs,
links and gitleaks.
