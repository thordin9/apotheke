# v2.5.7 release QA

Release: [v2.5.7](https://github.com/thordin9/apotheke/releases/tag/v2.5.7), from PR [#57](https://github.com/thordin9/apotheke/pull/57)
(`fix(docker): pin the PHP 8.3.36 security release`), merged as `944737f4956c1e2777e42cc2ad048d6169f8127a`
pinned to `37034a0`. Previous release: v2.5.6.

**Verdict: PASS.** No high or medium findings. One low (the hand-written docker line left under Unreleased,
already tracked as #57 L-1) for the v2.5.8 PR, and two nits.

## Test plan

1. Main CI on `944737f`: run conclusion and every job, including the new "Assert the image runs the pinned
   PHP patch" step.
2. Release workflow run: conclusion and steps; where the `v2.5.7` tag points and whether it is annotated;
   release draft/prerelease state, name, author and target compared with v2.5.6.
3. Source archive for the tag (codeload tar.gz): `VERSION`; the Dockerfile `FROM` line; the CHANGELOG 2.5.7
   section compared with the release body; anything left under `## [Unreleased]`; the tree compared file by
   file with `944737f` and with v2.5.6; no `.git`, `.env`, keys or secrets; gitleaks 8.30.1 with the repo's
   `.gitleaks.toml`.
4. `v2.5.6...v2.5.7`: commit list and changed files; nothing unexpected; no secrets in the changed files.
5. Release body: the `docker compose build --pull` upgrade note is present.

## Results

### 1. Main CI

CI run 39907753099 on `944737f` (push to main): **success**, 08:47 to 08:58 EEST.

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

In the Docker job, step 6 "Assert the image runs the pinned PHP patch" (added by #57) succeeded, so the built
image's `PHP_VERSION` equals the Dockerfile pin. All later steps (HEIC, SQLite and PostgreSQL smoke, web
server checks, graceful stop, Playwright `@smoke` against the image) succeeded.

### 2. Release run, tag and release

- Release run 39908697545 (workflow_run, head `944737f`): **success**, 08:58 EEST. One job, "Version,
  changelog, tag and GitHub Release"; every step green, including "Only release the commit CI tested",
  "Plan", "Refresh README coverage table from the CI artifact", "Update VERSION and CHANGELOG.md, commit, tag
  and push" and "GitHub Release".
- The earlier Release run 39851240108 on the #56 merge (`85c8c64`) succeeded with the version, tag and
  release steps skipped: `docs` changes do not release, so #56 ships in 2.5.7. That is expected.
- Tag `v2.5.7` is an **annotated** tag (tag object `d376daa1`, tagger github-actions[bot], message
  `v2.5.7`) pointing at commit `0bf805a06f8106351ebdc677ca350b741de26bb0`,
  `chore(release): v2.5.7 [skip ci]` by github-actions[bot]. Its only parent is `944737f`. It changes only
  `VERSION` (2.5.6 to 2.5.7) and `CHANGELOG.md` (the new 2.5.7 section and compare links). The README
  coverage step ran but left `README.md` unchanged. `0bf805a` is the current head of `main`.
- The release (id 421497473) is published at 08:58 EEST, not a draft and not a prerelease, name `v2.5.7`,
  author github-actions[bot], target `main`: the same as v2.5.6.

### 3. Source archive

- `apotheke-2.5.7.tar.gz` from codeload: 32,298,026 B, sha256
  `22a61607bf0dcb279ccdd57529804674ac45ade9253812c250e3c11930312164` (GitHub generates it on request, so
  the hash is not stable over time).
- `VERSION` reads `2.5.7`.
- `Dockerfile` line 2 is `FROM php:8.3.36-fpm-alpine`, under the comment
  `# Bump this patch tag on each PHP security release.` `tests/Unit/DockerfileTest.php` asserts the same pin.
- Against the `944737f` tree the archive differs only in `CHANGELOG.md` and `VERSION`.
- `CHANGELOG.md` has `## [2.5.7] - 2026-10-23` with `### Fixed` (the docker pin, #57) and `### Maintenance`
  (#56 changelog and QA doc, #57 CI assert and QA doc), plus the `[2.5.7]` compare link and `[Unreleased]`
  moved to `v2.5.7...HEAD`. The release body's `### Fixed` and `### Maintenance` text is identical to this
  section.
- `## [Unreleased]` still holds the hand-written #57 line: "**docker:** the Docker image now builds on PHP
  8.3.36, which fixes the PHP security issues released on 21 Oct 2026. Self-hosters should rebuild with
  `docker compose build --pull` and restart." (L-1).
- No `.git`, `.env*`, private keys or certificates in the archive.
- gitleaks 8.30.1 with the repo's `.gitleaks.toml`, run from the archive root: **no leaks found**. (Run from
  the parent directory it reports the known `SecretMissingEq99` fixture in `tests/Unit/MigrateDsnTest.php`,
  only because the allowlist path is anchored at the repo root.)

### 4. v2.5.6...v2.5.7

9 commits ahead, 0 behind:

| Commit | Subject |
|---|---|
| `cececdf` | docs(changelog): write the 2.5.6 notes in plain language (#56) |
| `2836c3d` | docs(qa): add the v2.5.6 release QA report (#56) |
| `85c8c64` | Merge pull request #56 |
| `9389450` | fix(docker): pin the PHP 8.3.36 security release (#57) |
| `f59e7d6` | Merge origin/main into the #57 branch |
| `76f4985` | ci(docker): assert the image runs the pinned PHP patch (#57) |
| `37034a0` | docs(qa): add the PR 57 PHP pin QA report (#57) |
| `944737f` | Merge pull request #57 |
| `0bf805a` | chore(release): v2.5.7 [skip ci] |

Changed files (11): `.github/workflows/ci.yml` (+9, the pin assert step), `Dockerfile` (+2/-1, the pin),
`README.md`, `docs/ci.md`, `docs/deployment-and-operations.md`, `docs/getting-started.md` (the image name
and the `docker compose build --pull` upgrade note), `tests/Unit/DockerfileTest.php` (new),
`docs/qa/apotheke-pr57-php-pin-qa.md` and `docs/qa/apotheke-v2.5.6-release-qa.md` (new; both are
byte-identical to the QA originals, sha256 `28925471…` and `881bac13…`), `CHANGELOG.md` and `VERSION`.
Nothing outside #56, #57 and the release commit shipped; no application code (`src/`, `public/`, `bin/`,
`migrations/`) changed.

gitleaks on the 11 changed files: no leaks. A grep for addresses, tokens, Worker hosts and long hex strings
found only commit hashes, file checksums, the existing Dockerfile tarball checksums and loopback addresses
in docs.

### 5. Release body

The body now opens with `### Upgrade`: Apotheke publishes no image, so rebuild with a fresh base image,
`docker compose build --pull && docker compose up -d`, then `docker compose exec apotheke php -v` should
report PHP 8.3.36. **The `--pull` note is present.** It is followed by the generated `### Fixed`,
`### Maintenance` and the full-changelog link to `v2.5.6...v2.5.7`.

## Findings

- **L-1 – leftover Unreleased line (known #57 L-1).** At the tag, the hand-written docker/PHP 8.3.36 line is
  still under `## [Unreleased]`, above `## [2.5.7]`, so the next release will list it again. v2.5.8 should
  move it into 2.5.7 (or have `scripts/release.php` move hand-written Unreleased lines into the new section).
- **N-1 – Upgrade note only in the release body.** The `### Upgrade` text was added to the release by hand
  and is not in the CHANGELOG 2.5.7 section. The v2.5.8 changelog fix could carry it there.
- **N-2 – no assets or images.** v2.5.7 ships only GitHub's source archives, the same as earlier releases.
  QA did not build the image locally; the PHP version check relies on CI's pin assert, which passed.
