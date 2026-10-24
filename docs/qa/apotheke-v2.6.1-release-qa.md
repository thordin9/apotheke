# v2.6.1 release QA

Release: [v2.6.1](https://github.com/thordin9/apotheke/releases/tag/v2.6.1), from PR [#60](https://github.com/thordin9/apotheke/pull/60)
(`fix(autofill): address the v2.6.0 image-size nits`), merged as `6e93bb2a0f9cbb12e553aad5f250ed5c20a2d806`
pinned to `df89717`. Previous release: v2.6.0.

**Verdict: PASS.** No high or medium findings. One low, one nit and five info notes. The low is #60's L-2 showing
up in production exactly as predicted: the 2.6.1 `### Fixed` section has no PR link at all. The #59 N-1 dedup fix
works: no change is listed twice in 2.6.1.

## Test plan

1. Tag and release commit: is `v2.6.1` annotated; is it on a `chore(release): v2.6.1 [skip ci]` commit whose
   only parent is `6e93bb2`; is `df89717` (the hash-checked `docs(qa)` head) the merge's second parent; which
   files the release commit changes (expected: `VERSION`, `CHANGELOG.md`, `README.md` coverage refresh).
2. Main CI on `6e93bb2`: run conclusion and every job, including "Assert the image runs the pinned PHP patch".
   Release workflow run: conclusion and steps. Release state, name, author, target, assets and edit time
   compared with v2.6.0.
3. Release body and CHANGELOG: `## [Unreleased]` empty; the hand-written Unreleased lines of `6e93bb2` folded
   into 2.6.1; the body compared with the CHANGELOG 2.6.1 section; PR links (#60 L-2 prediction); the
   duplicate-line dedup (#59 N-1 fix); compare links.
4. Source archive for the tag (codeload tar.gz): `VERSION`; migration count (26) and settings count (63); the
   Dockerfile `FROM` line; `zend.exception_ignore_args`; the tree compared with `6e93bb2` (and `6e93bb2` with
   `df89717`); the phpunit suite on the archive; a fresh SQLite install and a v2.6.0 database (no new step
   expected).
5. `v2.6.0...v2.6.1`: commit list and changed files; nothing outside #60 and the release commit; the QA docs in
   the release compared byte for byte with the QA originals; no addresses, hostnames or tokens added.
6. Secret scan: gitleaks 8.30.1 with the repo's `.gitleaks.toml` and with the default rules on a clean archive
   extract; no `.git`, `.env`, keys or certificates.
7. #60 nit fixes as released: a quick sanity run of the archive's own code against the archive's own PHP echo
   stub (`tests/stubs/autofill-stub.php`) on loopback, SQLite in a QA `mktemp -d` dir. No live Worker calls.

## Results

### 1. Tag and release commit

- Tag `v2.6.1` is an **annotated** tag (tag object `816e2ffe`, tagger github-actions[bot], message `v2.6.1`,
  2026-10-24 03:01:56 EEST, unsigned like the earlier ones) pointing at commit
  `e2628f27ff53ecceebc66bba8e53ad71e25cf8ca`, `chore(release): v2.6.1 [skip ci]` by github-actions[bot]. Its only
  parent is `6e93bb2`. `e2628f2` is the current head of `main`.
- `6e93bb2` ("Merge pull request #60 from thordin9/cursor/max-image-size-nits-601f", 02:51:23 EEST) has parents
  `e1f77ef` (the v2.6.0 release commit) and `df89717a4877a06abcfcdb16234edc5c5c310744`
  (`docs(qa): add the v2.6.0 release QA report`, two `docs(qa)` commits on top of the QA-passed head `58a27a9`).
  The merge is pinned to the `docs(qa)` head QA hash-checked.
- The release commit changes exactly three files: `VERSION` (2.6.0 to 2.6.1), `CHANGELOG.md` (+12/-1: the 2.6.1
  header, the generated Maintenance lines, the compare links) and `README.md` (+1/-1: the "All PHP" coverage row,
  from the "Refresh README coverage table from the CI artifact" step). As expected.

### 2. Main CI and the Release run

CI run **40041967294** on `6e93bb2` (push to main, attempt 1): **success**, 02:51:25 to 03:01:44 EEST.

| Job | Result |
|---|---|
| Lint | success |
| Secret scan | success |
| Tests (PHP 8.3, sqlite) | success |
| Tests (PHP 8.3, pgsql) | success |
| Headless mobile checks | success |
| Docker build + smoke test | success (step 6 "Assert the image runs the pinned PHP patch": success) |
| Visual E2E (Playwright, shard 1/3) | success |
| Visual E2E (Playwright, shard 2/3) | success |
| Visual E2E (Playwright, shard 3/3) | success |
| Conventional Commits | skipped (push event; it runs on pull requests) |

The only skipped steps are the failure-only report uploads and the coverage steps in the pgsql job (coverage
runs in the sqlite job), the same as for v2.6.0.

Release run **40042877611** (workflow_run, head `6e93bb2`, attempt 1): **success**, 03:01:46 to 03:02:01 EEST.
One job, "Version, changelog, tag and GitHub Release"; every step green, including "Only release the commit CI
tested", "Plan", "Refresh README coverage table from the CI artifact", "Update VERSION and CHANGELOG.md,
commit, tag and push" and "GitHub Release".

The release (id 422408607) was published at 03:01:58 EEST and its `updated_at` is the same second, so **nobody
edited the body by hand**. It is not a draft or a prerelease, name `v2.6.1`, author github-actions[bot], target
`main`, no assets: the same as v2.6.0. A patch bump is right for a fix and docs release.

### 3. Release body and CHANGELOG

- **`## [Unreleased]` is empty**: it is followed directly by `## [2.6.1] - 2026-10-24`.
- At `6e93bb2`, Unreleased held five hand-written `### Fixed` lines (one `release:`, four `autofill:`). All five
  now sit in 2.6.1 Fixed, unchanged. The CHANGELOG diff `v2.6.0` to `6e93bb2` adds only those lines, so #60 did
  not edit any published section.
- The 2.6.1 section has `### Fixed` (five hand-written, no generated) and `### Maintenance` (five generated:
  three `autofill:` docs lines and two `qa:` docs lines, each linking #60). `[2.6.1]` links
  `v2.6.0...v2.6.1` and `[Unreleased]` moved to `v2.6.1...HEAD`.
- **Dedup (#59 N-1 fix) worked.** #60's five `fix(release)`/`fix(autofill)` commits would each have generated a
  Fixed line with the same scope as a hand-written line; none of them is in 2.6.1, and no bullet appears twice
  in the section. The `docs(...)` lines under Maintenance stay, because there is no hand-written Maintenance
  line.
- **#60 L-2 showed up as predicted.** The five hand-written Fixed lines have no `([#60](…))`, and the generated
  lines that carried the link were dropped, so **2.6.1 Fixed has no PR link at all**. Only the Maintenance lines
  link #60 (L-1 below).
- **Release body vs CHANGELOG 2.6.1:** after normalising line endings and trimming outer blank lines, the body up
  to the `**Full changelog:** …/compare/v2.6.0...v2.6.1` link is identical to the section (15 lines each).

### 4. Source archive

- `apotheke-2.6.1.tar.gz` from codeload: 32,978,289 B, sha256
  `ab1e3c187234ae3740d3a22261b257392db3dcbca7f843b4ce122d60a06c1167` (GitHub generates it on request, so the
  hash is not stable over time).
- `VERSION` reads `2.6.1`.
- `migrations/` has 26 migrations; the last is still `0026_autofill_max_image_bytes.php`. #60 adds none.
- `Dockerfile` line 2 is `FROM php:8.3.36-fpm-alpine`; `docker/php.ini` line 12 is
  `zend.exception_ignore_args = On`.
- Against the `6e93bb2` tree the archive differs only in `CHANGELOG.md`, `README.md` and `VERSION`. The
  `6e93bb2` tree is identical to `df89717`'s (`diff -r`: no differences), so the merge added nothing.
- **phpunit** (local PHP 8.4.27 CLI, `composer install` from the archive's lock, phpunit 11.5): **OK, 363
  tests, 2,625 assertions, 11 skipped**. The skips are environmental: 10 need a PostgreSQL server
  (PgsqlSchemaCreate, cross-driver backup) and 1 needs php-cgi. Imagick and a QR decoder are now on the box, so
  the Imagick, HEIC and QR tests ran (v2.6.0 had 23 skips). CI's sqlite, pgsql and Docker jobs are green.
- **Migrations, SQLite:** a fresh install with `bin/migrate.php` applies 0001 to 0026 ("Done: 26 step(s)"), a
  second run says "Already up to date", and the settings table has 63 rows with `autofill_max_image_bytes` =
  `"auto"`. A database created by v2.6.0's `bin/migrate.php` (26 steps) is **"Already up to date"** under
  v2.6.1, with no migration step, 63 settings rows, and `bin/settings.php list` shows
  `autofill_max_image_bytes "auto"`.

### 5. v2.6.0...v2.6.1

13 commits ahead, 0 behind, merge base `e1f77ef`:

| Commit | Subject |
|---|---|
| `fcdfd21` | fix(release): drop generated notes that repeat a scope |
| `9f2606c` | fix(autofill): log each image clamp once |
| `0d7ae60` | docs(autofill): say the clamp warning is once per pair |
| `1138b1c` | fix(autofill): step JPEG quality down by ten |
| `4904488` | fix(autofill): measure the image against the byte cap |
| `5799d8d` | fix(autofill): show a tiny image limit in KB |
| `055245b` | docs(autofill): describe the ten-point quality step |
| `c90f1e9` | docs(autofill): say a tiny limit is shown in KB |
| `58a27a9` | Merge remote-tracking branch 'origin/main' into cursor/max-image-size-nits-601f |
| `36996a3` | docs(qa): add the #60 max image size nits QA report |
| `df89717` | docs(qa): add the v2.6.0 release QA report |
| `6e93bb2` | Merge pull request #60 |
| `e2628f2` | chore(release): v2.6.1 [skip ci] |

Changed files (14): the 12 files of #60 at `df89717` (`src/autofill.php`, `scripts/release.php`,
`tests/Unit/AutofillTest.php`, `tests/Unit/ReleaseTest.php`, `CHANGELOG.md`, `docs/autofill-admin-guide.md`,
`docs/autofill.md`, `docs/configuration.md`, `docs/releasing.md`, `docs/user-guide.md` and the two QA docs
(new)), plus `README.md` and `VERSION` from the release commit. A `diff -rq` of the two archives lists the same 14
paths. Nothing outside #60 and the release commit shipped.

QA docs in the release:

| File | Size | sha256 | vs QA original |
|---|---|---|---|
| `docs/qa/apotheke-pr60-v2.6.0-nits-qa.md` | 18,709 B | `528b716891b155b61d06553d305a94881eb20891c034b35a3a1ddad06487c6e8` | `cmp` identical |
| `docs/qa/apotheke-v2.6.0-release-qa.md` | 12,466 B | `151b258a10b7f10f533bec0248b4534ebbfce1cef8001fe6c7757da10c16c03b` | `cmp` identical |

Every other `docs/qa/*.md` in the archive that QA has a local copy of (22 more files) is also byte-identical.
`2.4.0-qa.md` predates the local archive and was not compared.

The 604 added lines contain no private addresses other than `127.0.0.1` in a test, no Worker or tunnel hosts,
and no production domain. The only Worker URL is `https://autofill.example`; every other URL is a
`github.com/thordin9/apotheke` link. The only token is the synthetic `sekret-token-xyz` test fixture, and the
only long hex strings are commit hashes and QA-doc checksums in the QA docs.

### 6. Secret scan

- No `.git`, `.env*`, private keys or certificates in the archive. The only `PRIVATE KEY` text is the sentences
  about it in the v2.5.8 and v2.6.0 release QA docs.
- gitleaks 8.30.1 (`dir`) on a clean extract of the archive, from the archive root: **no leaks found** with the
  repo's `.gitleaks.toml`, and **no leaks found** with the default rules.

### 7. #60 nit fixes as released (stub sanity check)

The archive's own `src/autofill.php` and `scripts/release.php`, under PHP 8.4.27 with SQLite in a QA
`mktemp -d` dir, against the archive's PHP echo stub on loopback (the stub's `/b/` is a real image-part limit,
`/e/` advertises `enforced_image_bytes`). The photo is a 1024×1024 random-noise JPEG (2,537,276 B); the token
was a dummy. No request left loopback.

| Nit | Check | Result |
|---|---|---|
| N-1 dedup | `rel_drop_scoped_generated()` with generated `autofill:`, `release:`, `db:` and unscoped lines, hand-written `autofill:` | only the `autofill:` generated line is dropped; the other three stay |
| N-3 clamp once | explicit 2097152, `max` 1048576 / `enforced` 2031616, primed, 3 photos | 3 POSTs, all ok; **1** clamp line; remembered pair `{2097152 → 2031616, enforced_image_bytes}`; no token or `Bearer` in the log |
| N-4 quality ladder | `AUTOFILL_UPLOAD_JPEG_QUALITY` 95 / 90 / 80 / 65 / 59 | 95/85/75/65/60, 90/80/70/60, 80/70/60 (default unchanged), 65/60, 59 |
| N-4 in the flow | q95, `enforced` 700000 | 1 POST ok, 631,902 B |
| N-5 first POST | `auto`, cap = JPEG + 40 B (701,142); image part 701,102 B, multipart body 701,522 B | **1 POST, ok** (the body is over the cap, the JPEG is not) |
| N-5 413 retry | stub `clamp` mode (first POST 413, then `enforced` = 701,142), no cache | **2 POSTs** (701,102 B each), ok: the retry's local pre-check also measures the JPEG |
| N-6 KB | `autofill_mib()` 1000 / 10 / 50000 / 52428 / 52429 / 65536 | 1 KB, 0.01 KB, 48.8 KB, 51.2 KB, 0.1 MiB, 0.1 MiB |
| N-6 message | always-413 stub with `max`/`enforced` 50000 | "the photo is too large (max 48.8 KB / 50000 bytes)" |

The source photo's sha256 was the same before and after, and `DATA_DIR/tmp` was empty afterwards. This also
exercises both N-5 call sites through `autofill_suggest()` in the released code, the path #60 L-1 says has no
unit test; both behave correctly in v2.6.1.

## Findings

### High

None.

### Medium

None.

### Low

- **L-1: 2.6.1 `### Fixed` has no PR link (#60 L-2, now published).** The five hand-written Fixed lines have no
  `([#60](…))`, and the N-1 dedup dropped the generated lines that carried it. The CHANGELOG and the GitHub
  release body both list the five fixes with no link; only the Maintenance docs lines link #60. Already queued
  for Coder's next small PR. That PR can add the link to the 2.6.1 lines (and to the 2.6.0 `db:` line, v2.6.0
  N-1). For the longer term, let `rel_drop_scoped_generated()` carry a dropped line's PR link onto the
  hand-written line with that scope, so this cannot recur. Doesn't block.

### Nit

- **N-1:** the published 2.6.0 section still has its two `autofill:` lines for one feature under Added (v2.6.0
  N-2). The dedup only applies to new releases, as designed. Rewriting 2.6.0 is optional and would make the
  v2.6.0 release body differ from the CHANGELOG (like v2.5.8 after #59).

### Info

- **I-1: README coverage** (unit / HTTP integration / combined). "All PHP" goes from 64.2 / 91.7 / 96.0 % (6,796
  lines) to 64.3 / 91.5 / 96.0 % (6,814 lines). No other row changed.
- **I-2:** #60 L-1 (no unit test for the N-5 call sites) is still open in the code; section 7 shows both call
  sites behave correctly as released. Queued with L-1 above.
- **I-3:** v2.6.1 ships only GitHub's source archives. QA did not build the image locally (no Docker on the
  box); the PHP pin and `php.ini` checks rest on CI's Docker job and the file contents.
- **I-4:** no migration and no setting change: a v2.6.0 install picks up v2.6.1 with
  `docker compose build --pull && docker compose up -d` and no database step.
- **I-5:** the stub run used GD; Imagick is now on the box but the analysis copy tries GD first and Imagick only as a fallback for non-HEIC input.
  CI's Docker smoke covers Imagick.
