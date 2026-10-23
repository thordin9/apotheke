# v2.5.8 release QA

Release: [v2.5.8](https://github.com/thordin9/apotheke/releases/tag/v2.5.8), from PR [#58](https://github.com/thordin9/apotheke/pull/58)
(`fix(db): keep the database user out of connect errors`), merged as `4376eaa8080f2ebe00f95623560fd2f93db6f4c0`
pinned to `c3786cd`. Previous release: v2.5.7.

**Verdict: PASS.** No high or medium findings. One low (the release body still needs a hand edit, already
tracked as #58 L-1) and three nits. The v2.5.7 L-1 and N-1 follow-ups are fixed in this release.

## Test plan

1. Tag and release commit: is `v2.5.8` annotated; is it on a `chore(release)` commit whose only parent is
   `4376eaa`; is `c3786cd` the merge's second parent; which files does the release commit change.
2. Main CI on `4376eaa`: run conclusion and every job, including "Assert the image runs the pinned PHP
   patch". Release workflow run: conclusion and steps. Release state, name, author and target compared with
   v2.5.7.
3. Source archive for the tag (codeload tar.gz): `VERSION`; the Dockerfile `FROM` line;
   `zend.exception_ignore_args` in `docker/php.ini`; the tree compared file by file with `4376eaa` (and
   `4376eaa` with `c3786cd`); `## [Unreleased]` empty; the CHANGELOG 2.5.8 section compared with the
   hand-edited release body; no `.git`, `.env`, keys or secrets; gitleaks 8.30.1 with the repo's
   `.gitleaks.toml`.
4. `v2.5.7...v2.5.8`: commit list and changed files; nothing outside #58 and the release commit; the two QA
   docs in it compared byte for byte with the QA originals; no secrets, addresses or hostnames added.
5. Smoke: two bin scripts from the archive against a refused PostgreSQL port on loopback with a canary user
   and password.

## Results

### 1. Tag and release commit

- Tag `v2.5.8` is an **annotated** tag (tag object `cb7cd1b2`, tagger github-actions[bot], message
  `v2.5.8`, 16:18 EEST) pointing at commit `7d32e3d34d063db6efde1498f3574df3d9ceb6d0`,
  `chore(release): v2.5.8 [skip ci]` by github-actions[bot]. Its only parent is `4376eaa`. `7d32e3d` is
  the current head of `main`.
- `4376eaa` ("Merge pull request #58 from thordin9/cursor/db-connect-errors", 16:09 EEST) has parents
  `0bf805a` (the v2.5.7 release commit) and `c3786cd8031f0c424371bea12c80bf3caf8f7544` (the reviewed
  `docs(qa)` head), so the merge is pinned to what QA hash-checked.
- The release commit changes `VERSION` (2.5.7 to 2.5.8), `CHANGELOG.md` (+16/-1: the 2.5.8 header,
  the generated Fixed and Maintenance lines, the compare links) and **`README.md`** (+3/-3: the coverage
  table rows "Database, migrations, legacy", "Bootstrap, config, QR, seed" and "All PHP"). The README
  change comes from the workflow's "Refresh README coverage table from the CI artifact" step, which ran for
  v2.5.7 too but left README unchanged then. It is expected workflow output, not a stray edit (N-1).

### 2. Main CI and the Release run

CI run 39958911995 on `4376eaa` (push to main, attempt 1): **success**, 16:09 to 16:18 EEST.

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
runs in the sqlite job), the same as for v2.5.7.

Release run 39960060379 (workflow_run, head `4376eaa`): **success**, 16:18:06 to 16:18:29 EEST. One job,
"Version, changelog, tag and GitHub Release"; every step green, including "Only release the commit CI
tested", "Plan", "Refresh README coverage table from the CI artifact", "Update VERSION and CHANGELOG.md,
commit, tag and push" and "GitHub Release".

The release (id 421866257) was published at 16:18:26 EEST and last updated at 16:20:39 EEST (Coder's hand
edit). It is not a draft or a prerelease, name `v2.5.8`, author github-actions[bot], target `main`, no
assets: the same as v2.5.7.

### 3. Source archive

- `apotheke-2.5.8.tar.gz` from codeload: 32,312,192 B, sha256
  `5aa2b6cfeb4ea3d7e3823349b53f77c46524e24d271a74f3f5d9929b663c2377` (GitHub generates it on request, so
  the hash is not stable over time).
- `VERSION` reads `2.5.8`.
- `Dockerfile` line 2 is `FROM php:8.3.36-fpm-alpine`.
- `docker/php.ini` line 12 is `zend.exception_ignore_args = On`.
- Against the `4376eaa` tree the archive differs only in `CHANGELOG.md`, `README.md` and `VERSION` (the
  release commit above). The `4376eaa` tree is identical to `c3786cd`'s, so the merge added nothing.
- **`## [Unreleased]` is empty**: it is followed directly by `## [2.5.8] - 2026-10-23`. The #58
  `scripts/release.php` fold worked in production: the three hand-written Unreleased lines now sit in
  2.5.8's `### Fixed`, below the two generated lines.
- The 2.5.8 section has `### Fixed` (generated `db:` and `release:` lines, then the hand-written `db:`
  seed/backup/restore/settings connect-error line, the `bootstrap:` exit-1 line and the `docker:` trace-args
  line) and `### Maintenance` (seven generated lines), plus the `[2.5.8]` compare link and `[Unreleased]`
  moved to `v2.5.8...HEAD`.
- The 2.5.7 section now carries the `--pull` upgrade paragraph and the hand-written docker/PHP 8.3.36 line,
  which closes v2.5.7 L-1 and N-1.
- **Release body vs CHANGELOG 2.5.8:** after normalising line endings, the body up to the
  `**Full changelog:** …/compare/v2.5.7...v2.5.8` link is identical to the section text. There is no drift
  from the hand edit.
- No `.git`, `.env*`, private keys or certificates in the archive, and no `PRIVATE KEY` blocks.
- gitleaks 8.30.1 with the repo's `.gitleaks.toml`, run from the archive root: **no leaks found**. (Run from
  the parent directory it reports the known `generic-api-key` fixture at
  `tests/Unit/MigrateDsnTest.php:129`, only because the allowlist path is anchored at the repo root.)

### 4. v2.5.7...v2.5.8

12 commits ahead, 0 behind:

| Commit | Subject |
|---|---|
| `cedc2aa` | fix(db): keep the database user out of connect errors |
| `a756960` | test: disable JIT in the healthz connect log |
| `d60da73` | Merge origin/main into cursor/db-connect-errors |
| `3aec981` | fix(release): move handwritten Unreleased notes into the version |
| `6c9c91e` | docs(changelog): add the 2.5.7 PHP rebuild note |
| `f421f02` | docs: add --pull to the plain docker build |
| `5e714ca` | test(docker): align the PHP pin check with CI |
| `f1c2179` | ci(docker): print the expected PHP pin on mismatch |
| `2e71d93` | docs(qa): add the v2.5.7 release QA report |
| `c3786cd` | docs(qa): add the PR 58 v2.5.8 fix QA report |
| `4376eaa` | Merge pull request #58 |
| `7d32e3d` | chore(release): v2.5.8 [skip ci] |

Changed files (21): the 19 files of #58 at `c3786cd` (`.github/workflows/ci.yml`, `CHANGELOG.md`,
`bin/migrate.php`, `docker/php.ini`, `docs/ci.md`, `docs/configuration.md`,
`docs/deployment-and-operations.md`, `docs/getting-started.md`, `docs/releasing.md`, `public/index.php`,
`scripts/release.php`, `src/bootstrap.php`, `src/db.php`, `tests/Unit/DbConnectErrorTest.php` (new),
`tests/Unit/DockerfileTest.php`, `tests/Unit/MigrateDsnTest.php`, `tests/Unit/ReleaseTest.php` and the two
QA docs), plus `README.md` and `VERSION` from the release commit. Nothing outside #58 and the release
commit shipped.

QA docs in the release:

| File | Size | sha256 | vs QA original |
|---|---|---|---|
| `docs/qa/apotheke-v2.5.7-release-qa.md` | 7,529 B | `19ff8410…96a3` | `cmp` identical |
| `docs/qa/apotheke-pr58-v2.5.8-fix-qa.md` | 17,165 B | `c4b8bb3e…9da2` | `cmp` identical |

gitleaks 8.30.1 on the 21 changed files: only the known `MigrateDsnTest.php` fixture (default rules, outside
the repo root). A scan of the added lines for private addresses, Worker hosts and long hex strings found
only commit hashes and the v2.5.7 archive checksum in the QA docs. The private-range example addresses in
`README.md` and `docs/configuration.md` already existed and are not in the added lines.

### 5. Smoke

Local PHP 8.4.27 CLI with `pdo_pgsql`. `bin/seed.php` and `bin/settings.php list` from the archive, with
`DB_DRIVER=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=1` (refused), a canary user and password,
`zend.exception_ignore_args=0`, `log_errors=1`, `display_errors=stderr` and an `error_log` file:

- both exit **1**;
- stdout is empty, stderr is the single line
  `Could not connect to the database (pgsql – host=127.0.0.1 port=1 dbname=keepdb).`;
- the canary user, the canary password, `PDOException`, `SQLSTATE` and `Stack trace` appear nowhere in
  stdout, stderr or the log.

No PostgreSQL server was started.

## Findings

### High

None.

### Medium

None.

### Low

- **L-1: the release body still needs a hand edit (known #58 L-1).** The workflow builds the GitHub release
  notes from the generated lines only, so the three hand-written 2.5.8 lines had to be added by hand after
  publishing. The edited body now matches the CHANGELOG 2.5.8 section exactly. The fix (build the notes from
  the folded section, with a test) is planned for the follow-up PR; until then every release with
  hand-written notes needs the same manual step.

### Nit

- **N-1: the release commit also touches `README.md`.** The coverage-table refresh is a documented
  workflow step, so this is expected, but "the release commit changes only VERSION and CHANGELOG" is no
  longer a safe assumption for release QA.
- **N-2: the README coverage figure for "Database, migrations, legacy" drops** from 85.5 / 86.7 / 97.8 %
  to 79.4 / 81.2 / 90.8 %. The new connect-error paths in `src/db.php` are exercised by
  `DbConnectErrorTest` through child `php` processes, which the coverage run does not count. The code is
  tested; the table just under-reports it.
- **N-3: two `db:` lines for one fix in 2.5.8** (the generated terse line and the hand-written one), as
  noted in #58 N-3. Cosmetic.

### Info

- **I-1:** v2.5.8 ships only GitHub's source archives. QA did not build the image locally (no Docker on the
  box); the PHP pin and `php.ini` checks rest on CI's Docker job and on the file contents.
- **I-2:** self-hosters only get these fixes after `docker compose build --pull && docker compose up -d`.
