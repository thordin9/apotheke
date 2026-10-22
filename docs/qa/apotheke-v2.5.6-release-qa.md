# v2.5.6 release QA

Release: [v2.5.6](https://github.com/thordin9/apotheke/releases/tag/v2.5.6), from PR [#55](https://github.com/thordin9/apotheke/pull/55)
(`fix(migrate): keep the DB user name out of connect errors`), merged as `b9fa1415c648451aab65edef26db4927a5c0f190`
pinned to `dc829ff`. Previous release: v2.5.5.

**Verdict: PASS.** No high or medium findings. One low (changelog wording) for the v2.5.6 changelog PR.

## Test plan

1. Main CI on `b9fa141`: run conclusion and every job.
2. Release workflow run: conclusion and steps; where the `v2.5.6` tag points; release draft/prerelease state,
   name and body compared with v2.5.5; `v2.5.5...v2.5.6` commit list.
3. Assets: release assets and container images; the GitHub source archives (tar.gz and zip) for the tag,
   compared file by file with the `b9fa141` tree; `bin/migrate.php` is the fixed file; no `.git`, `.env`,
   keys or secrets; gitleaks on the extracted archive.
4. Version: `VERSION` and any other version string.
5. Smoke: `php bin/migrate.php` from the extracted release archive against unreachable and refusing
   PostgreSQL targets, with the fake user `qa_probe_user` and fake password `qa_probe_pw`, with
   `zend.exception_ignore_args=Off` and `display_errors=1` (worst case). Expect exit 1 and neither value in
   the output. Control: the same cases on the v2.5.5 archive.

## Results

### 1. Main CI

CI run 39844750574 on `b9fa141` (push to main): **success**, 22:58 to 23:11 EEST.

| Job | Result |
|---|---|
| Lint | success |
| Secret scan | success |
| Tests (PHP 8.3, sqlite) | success |
| Tests (PHP 8.3, pgsql) | success |
| Headless mobile checks | success |
| Docker build + smoke test | success |
| Visual E2E (Playwright, shard 1/3) | success |
| Visual E2E (Playwright, shard 2/3) | success |
| Visual E2E (Playwright, shard 3/3) | success |
| Conventional Commits | skipped (push event; it runs on pull requests) |

### 2. Release run, tag and notes

- Release run 39846613321 (workflow_run, head `b9fa141`): **success**. One job, "Version, changelog, tag and
  GitHub Release", all steps green, 23:11 EEST.
- Tag `v2.5.6` is an annotated tag (object `b5867382`) pointing at commit
  `02464d47829d965484ed11de3d97487cf652d9dc`, `chore(release): v2.5.6 [skip ci]` by github-actions[bot].
  Its only parent is `b9fa141`, and it changes only `VERSION` (2.5.5 to 2.5.6), `CHANGELOG.md` (the new
  2.5.6 section and compare links) and the `README.md` coverage table. This matches v2.5.5, whose tag points
  at its own release commit `db983c8`.
- The release is published, not a draft and not a prerelease, name `v2.5.6`, author github-actions[bot],
  target `main`: the same as v2.5.5.
- The body has `### Fixed` with the migrate line for #55, `### Maintenance`, and the full-changelog link to
  `v2.5.5...v2.5.6`.
- `v2.5.5...b9fa141` is 20 commits ahead, 0 behind; the tag adds the release commit. Besides #54
  (`ab2b872`, merge `85a260b`) and #55 (`08f8a78`, `bab8eec`, `dc829ff`, merge `b9fa141`), it contains
  #48, #49 and #50, which merged after the v2.5.5 tag (`db983c8`) and did not cut a release because their
  types (`build`, `docs`, `test`, `chore`) do not release. The notes list them under Maintenance. They are
  expected, not unrelated (see N-1).

### 3. Assets

- Release assets: none. v2.5.5 and earlier releases have none either.
- Container images: no workflow publishes one (CI builds `apotheke:ci` with `load: true`, no push), and no
  public GHCR package exists for the repo.
- GitHub source archives for the tag:

| Archive | Size | sha256 |
|---|---:|---|
| `apotheke-2.5.6.tar.gz` | 32,290,292 B | `4f8aa779b6fa2830b50cf6d51ae0e312efad2e84414533e3505fbaa4a7677ebf` |
| `apotheke-2.5.6.zip` | 32,582,154 B | `40617b88cdb9e8b92b561c70354d6f65b3ae724e0f1796bc4f2679666bf2cc33` |

  GitHub generates these on request, so their hashes are not stable over time and there is no checksums
  file to verify.
- The tar.gz and zip trees are identical. Against the `b9fa141` tree they differ only in `CHANGELOG.md`,
  `README.md` and `VERSION`.
- `bin/migrate.php` in the archive is byte-identical to `b9fa141` (1,693 B, sha256
  `9757f2d7ffdc95e45c2ca026636126d5ca0fa2559607dae3ea73ee283b219a83`) and carries the non-SQLite catch that
  prints only the driver and `config_migrate_where()`, then exits 1.
- No `.git`, `.env*`, private keys or certificates in the archive. `docs/qa` holds only QA reports.
- gitleaks on the extracted archive with the repo's `.gitleaks.toml` reports 1 hit: the known
  `SecretMissingEq99` fixture in `tests/Unit/MigrateDsnTest.php`. The local gitleaks build is older and
  does not apply the `[[allowlists]]` entry that excludes it; CI's pinned gitleaks 8.30.1 passes. No real
  secrets.

### 4. Version

`VERSION` reads `2.5.6` at the tag and matches the app's SemVer check. `app_version()` in
`src/bootstrap.php` reads it for the Settings page. `composer.json` and `package.json` carry no version.

### 5. Smoke

Run from the extracted v2.5.6 archive, PHP 8.4.26 on the host and PHP 8.3.35 in a container, both with
`pdo_pgsql`, `zend.exception_ignore_args=Off` and `display_errors=1`:

| Case | v2.5.6 | v2.5.5 control |
|---|---|---|
| Loopback, closed port, `DB_HOST`/`DB_USER`/`DB_PASSWORD` | exit 1, no user or password | exit 255, uncaught PDOException and trace |
| Same as a `DB_DSN` with `user=` and `password=` | exit 1, no user or password | exit 255, uncaught PDOException and trace |
| Unresolvable `.invalid` host | exit 1, no user or password | exit 255, uncaught PDOException and trace |
| Local PostgreSQL 17, wrong login (auth fails) | exit 1, no user or password | exit 255, `password authentication failed for user "qa_probe_user"` |

The PHP 8.3 container ran the closed-port and auth-failure cases with the same result. In every v2.5.6 run
the output was the banner plus
`Could not connect to the database (pgsql – host=… port=… dbname=qa_db).`

## Findings

- **L-1 – changelog wording.** At the tag, the hand-written migrate note ("bin/migrate.php exits 1 when the
  database refuses the connection…") is still under `## [Unreleased]`, above `## [2.5.6]`. The 2.5.6
  section only has the generated commit subject. The v2.5.6 changelog PR should move the note into 2.5.6
  (as #49 did for 2.5.5) and the release body should be updated to match, as v2.5.5's was.
- **N-1 – #48, #49 and #50 in v2.5.6.** They merged after v2.5.5 and ship in 2.5.6. #48 changes the Docker
  image build (pinned PECL fallback for Imagick, https-only curl), which image builders will notice; the
  changelog PR could give it a user-facing line.
- **N-2 – no assets or images.** v2.5.6 ships only GitHub's source archives, the same as earlier releases.
- **N-3 – `docker/php.ini`.** It sets `display_errors = Off` but not `zend.exception_ignore_args`. The fix
  does not depend on it: the smoke ran with it Off.
