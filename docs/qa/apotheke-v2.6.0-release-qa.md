# v2.6.0 release QA

Release: [v2.6.0](https://github.com/thordin9/apotheke/releases/tag/v2.6.0), from PR [#59](https://github.com/thordin9/apotheke/pull/59)
(`feat(autofill): add a configurable maximum image size`), merged as `d90cd1b8b0a10ff6211a06828cd21f6a48ea213b`
pinned to `c52d95c`. Previous release: v2.5.8.

**Verdict: PASS.** No high, medium or low findings. Two nits and four info notes. The v2.5.8 L-1 (the release
body needed a hand edit) is fixed in production: the v2.6.0 body was generated from the full section and was not
edited after publishing.

## Test plan

1. Tag and release commit: is `v2.6.0` annotated; is it on a `chore(release): v2.6.0 [skip ci]` commit whose
   only parent is `d90cd1b`; is `c52d95c` (the reviewed `docs(qa)` head) the merge's second parent; which files
   the release commit changes (expected: `VERSION`, `CHANGELOG.md`, `README.md` coverage refresh).
2. Main CI on `d90cd1b`: run conclusion and every job, including "Assert the image runs the pinned PHP patch".
   Release workflow run: conclusion and steps. Release state, name, author, target, assets and edit time
   compared with v2.5.8.
3. Release body and CHANGELOG: `## [Unreleased]` empty; the hand-written Unreleased lines of `d90cd1b` folded
   into 2.6.0; the body compared with the CHANGELOG 2.6.0 section; #59 linked; compare links.
4. Source archive for the tag (codeload tar.gz): `VERSION`; migration `0026_autofill_max_image_bytes.php`; the
   Dockerfile `FROM` line; `zend.exception_ignore_args`; the tree compared with `d90cd1b` (and `d90cd1b` with
   `c52d95c`); the phpunit suite on the archive; migration 0026 on a fresh SQLite install and on a v2.5.8
   database.
5. `v2.5.8...v2.6.0`: commit list and changed files; nothing outside #59 and the release commit; the QA docs in
   the release compared byte for byte with the QA originals; no addresses, hostnames or tokens added.
6. Secret scan: gitleaks 8.30.1 with the repo's `.gitleaks.toml` on the archive; no `.git`, `.env`, keys or
   certificates.

## Results

### 1. Tag and release commit

- Tag `v2.6.0` is an **annotated** tag (tag object `47690ee0`, tagger github-actions[bot], message `v2.6.0`,
  2026-10-24 01:47:52 EEST) pointing at commit `e1f77efe88915dc9f3926a39eb032a6293d115cd`,
  `chore(release): v2.6.0 [skip ci]` by github-actions[bot]. Its only parent is `d90cd1b`. `e1f77ef` is the
  current head of `main`.
- `d90cd1b` ("Merge pull request #59 from thordin9/cursor/autofill-max-image-size", 01:38:21 EEST) has parents
  `7d32e3d` (the v2.5.8 release commit) and `c52d95c2e02ea6c13b1ed2963878e89cfc690522`
  (`docs(qa): add the v2.6.0 max image size QA report`, on top of the QA-passed head `2bb1b1d`). The merge is
  pinned to the `docs(qa)` head QA hash-checked.
- The release commit changes exactly three files: `VERSION` (2.5.8 to 2.6.0), `CHANGELOG.md` (+22/-1: the 2.6.0
  header, the generated Added, Fixed and Maintenance lines, the compare links) and `README.md` (+5/-5: the
  coverage rows "Views (HTML)", "Control Panel settings", "Database, migrations, legacy", "Bootstrap, config,
  QR, seed" and "All PHP", from the "Refresh README coverage table from the CI artifact" step). As expected.

### 2. Main CI and the Release run

CI run **40034908940** on `d90cd1b` (push to main, attempt 1): **success**, 01:38:24 to 01:47:40 EEST.

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
runs in the sqlite job), the same as for v2.5.8.

Release run **40035863319** (workflow_run, head `d90cd1b`, attempt 1): **success**, 01:47:42 to 01:47:58 EEST.
One job, "Version, changelog, tag and GitHub Release"; every step green, including "Only release the commit CI
tested", "Plan", "Refresh README coverage table from the CI artifact", "Update VERSION and CHANGELOG.md,
commit, tag and push" and "GitHub Release".

The release (id 422374172) was published at 01:47:54 EEST and its `updated_at` is the same second, so **nobody
edited the body by hand** (v2.5.8 needed one). It is not a draft or a prerelease, name `v2.6.0`, author
github-actions[bot], target `main`, no assets: the same as v2.5.8. A minor bump is right for a feature release.

### 3. Release body and CHANGELOG

- **`## [Unreleased]` is empty**: it is followed directly by `## [2.6.0] - 2026-10-23`.
- At `d90cd1b`, Unreleased held two hand-written lines: the `autofill:` Maximum image size line under Added
  (linked to #59) and the `db:` "while the database is down, every route … returns 500" line under Fixed. Both
  now sit in 2.6.0, each below the generated lines of its group.
- The 2.6.0 section has `### Added` (one generated, one hand-written), `### Fixed` (five generated, one
  hand-written) and `### Maintenance` (nine generated). Every generated line links #59. `[2.6.0]` links
  `v2.5.8...v2.6.0` and `[Unreleased]` moved to `v2.6.0...HEAD`.
- **Release body vs CHANGELOG 2.6.0:** after normalising line endings and trimming outer blank lines, the body up
  to the `**Full changelog:** …/compare/v2.5.8...v2.6.0` link is identical to the section (26 lines each). The
  #59 L-1 fix (body from the full section, blank lines kept) works in production.

### 4. Source archive

- `apotheke-2.6.0.tar.gz` from codeload: 32,970,042 B, sha256
  `f3b93f208f854872334a206a3baff1f5a454aa497b1ab7e06080873ac7009e35` (GitHub generates it on request, so the
  hash is not stable over time).
- `VERSION` reads `2.6.0`.
- `migrations/0026_autofill_max_image_bytes.php` is present (the last migration). It inserts
  `autofill_max_image_bytes` = `"auto"` only when the row is missing, with no schema change.
- `Dockerfile` line 2 is `FROM php:8.3.36-fpm-alpine`; `docker/php.ini` line 12 is
  `zend.exception_ignore_args = On`.
- Against the `d90cd1b` tree the archive differs only in `CHANGELOG.md`, `README.md` and `VERSION`. The
  `d90cd1b` tree is identical to `c52d95c`'s (`diff -r`: no differences), so the merge added nothing.
- **phpunit** (local PHP 8.4.27 CLI, `composer install` from the archive's lock, phpunit 11.5): **OK, 359
  tests, 2,423 assertions, 23 skipped**. The skips are environmental: no PostgreSQL server (PgsqlSchemaCreate,
  cross-driver backup), no Imagick (ImagickColor, HEIC), no QR decoder, no php-cgi. CI's sqlite, pgsql and
  Docker jobs cover those and are green.
- **Migration 0026, SQLite:** a fresh install with `bin/migrate.php` applies 0001 to 0026 ("Done: 26
  step(s)"), a second run says "Already up to date", and the settings table has 63 rows with
  `autofill_max_image_bytes` = `"auto"`. A database created by v2.5.8's `bin/migrate.php` (25 steps) upgrades
  with exactly one step, `applied 0026 autofill_max_image_bytes`, and `bin/settings.php list` then shows
  `autofill_max_image_bytes "auto"`.

### 5. v2.5.8...v2.6.0

18 commits ahead, 0 behind:

| Commit | Subject |
|---|---|
| `446b8e4` | feat(autofill): add a configurable maximum image size |
| `d1d1d47` | test(e2e): update autofill settings screenshot baselines |
| `631e220` | Merge remote-tracking branch 'origin/main' into cursor/autofill-max-image-size |
| `9709ce1` | fix(release): build the release body from the full section |
| `5c631eb` | fix(release): keep blank lines inside changelog notes |
| `efd2eb9` | docs(db): record the 500 while the database is down |
| `ef53021` | fix(bootstrap): write the data-dir error to stderr |
| `7c4a48c` | docs(changelog): drop the duplicate docker and db lines |
| `49e9fd8` | fix(bootstrap): avoid the STDERR constant under php-fpm |
| `8d5686c` | docs(qa): add the v2.5.8 release QA report |
| `414fca9` | fix(autofill): cap an explicit size to the service limit |
| `27a3fb6` | test(release): run apply and check the notes file |
| `a57c1b5` | docs(changelog): link the image-size note to #59 |
| `56cd5f3` | docs(autofill): say a blank image size saves as auto |
| `2bb1b1d` | test(e2e): update autofill settings baselines |
| `c52d95c` | docs(qa): add the v2.6.0 max image size QA report |
| `d90cd1b` | Merge pull request #59 |
| `e1f77ef` | chore(release): v2.6.0 [skip ci] |

Changed files (52): the 50 files of #59 at `c52d95c` (28 Playwright baseline PNGs; `CHANGELOG.md`;
`docs/autofill-admin-guide.md`, `docs/autofill.md`, `docs/configuration.md`, `docs/control-panel.md`,
`docs/deployment-and-operations.md`, `docs/releasing.md`, `docs/user-guide.md`; the two QA docs (new);
`migrations/0026_autofill_max_image_bytes.php` (new); `public/index.php`, `scripts/release.php`,
`src/autofill.php`, `src/bootstrap.php`, `src/settings.php`, `src/settings_views.php`;
`tests/Unit/AutofillTest.php`, `tests/Unit/DbConnectErrorTest.php`, `tests/Unit/ReleaseTest.php`,
`tests/integration.php`, `tests/stubs/autofill-stub.php`), plus `README.md` and `VERSION` from the release
commit. A `diff -rq` of the two archives lists the same 52 paths. Nothing outside #59 and the release commit
shipped.

QA docs in the release:

| File | Size | sha256 | vs QA original |
|---|---|---|---|
| `docs/qa/apotheke-pr59-v2.6.0-max-image-size-qa.md` | 38,652 B | `c4207407…af0b` | `cmp` identical |
| `docs/qa/apotheke-v2.5.8-release-qa.md` | 10,486 B | `f3d5b7ea…2e07` | `cmp` identical |

Every other `docs/qa/*.md` in the archive that QA has a local copy of (20 more files) is also byte-identical.
`2.4.0-qa.md` predates the local archive and was not compared.

The 1,182 added lines (PNGs excluded) contain no private addresses other than `127.0.0.1` in tests and docs, no
Worker or tunnel hosts, and no production domain. The only Worker URL is `https://autofill.example`. The only
long hex strings are commit hashes and archive or QA-doc checksums in the QA docs.

### 6. Secret scan

- No `.git`, `.env*`, private keys or certificates in the archive. The only `PRIVATE KEY` text is the sentence
  "no `PRIVATE KEY` blocks" in the v2.5.8 release QA doc.
- gitleaks 8.30.1 with the repo's `.gitleaks.toml`, run from the archive root: **no leaks found**. (Run from
  the parent directory it reports only the known `generic-api-key` fixture at
  `tests/Unit/MigrateDsnTest.php:129`, because the allowlist path is anchored at the repo root.)

## Findings

### High

None.

### Medium

None.

### Low

None.

### Nit

- **N-1: the hand-written `db:` line in 2.6.0 Fixed has no PR link.** "while the database is down, every route
  … returns 500 …" came from #59 (`efd2eb9`) but has no `([#59](…))`, unlike every other 2.6.0 line. The fold
  keeps hand-written lines as written, so the author has to add the link. Cosmetic.
- **N-2: two `autofill:` lines for one feature in 2.6.0 Added** (the generated terse line and the hand-written
  one), the same pattern as v2.5.8 N-3. Already tracked as #59 N-1 (dedup) for the nits PR.

### Info

- **I-1: #59 edited the published 2.5.8 and 2.5.7 sections** (`7c4a48c`): it dropped the generated
  `db: keep the database user out of connect errors` line from 2.5.8 and moved its #58 link onto the
  hand-written line, and merged the two `docker:` PHP 8.3.36 lines in 2.5.7. This was intended. The v2.5.8
  GitHub release body still has the dropped line, so it no longer matches CHANGELOG 2.5.8 word for word.
  Nothing to do unless we want the old bodies to match.
- **I-2: README coverage** (unit / HTTP integration / combined). "All PHP" goes from 63.4 / 91.8 / 95.9 % to
  64.2 / 91.7 / 96.0 % (6,796 lines). "Control Panel settings" HTTP drops from 97.2 to 96.0 % (combined 97.2 to
  97.3 %), and "Bootstrap, config, QR, seed" combined from 91.1 to 90.1 %. The new autofill paths are tested in
  `AutofillTest` (+246 lines).
- **I-3:** v2.6.0 ships only GitHub's source archives. QA did not build the image locally (no Docker on the
  box); the PHP pin and `php.ini` checks rest on CI's Docker job and the file contents.
- **I-4:** self-hosters get 0026 automatically on the next start or `bin/migrate.php`; the default `auto`
  keeps today's behaviour. They pick up the image after `docker compose build --pull && docker compose up -d`.
