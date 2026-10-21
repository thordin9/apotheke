# QA: apotheke v2.5.5 tag, GitHub release and post-release changelog PR

- **Release:** [v2.5.5](https://github.com/thordin9/apotheke/releases/tag/v2.5.5), from #44 (`fix(db): skip CREATE
  SCHEMA when DB_SCHEMA already exists`, closing #41), together with #45 and #47, which merged after v2.5.4.
- **Merge:** `b9396e7248b45cc2600e96de4cad80dcea327874` "Merge pull request #44", parents `7a20646` (`main` with #45)
  and `f566541` (#44's last commit, `docs(qa): add QA report for the DB schema fix`).
- **Post-release PR:** [#49](https://github.com/thordin9/apotheke/pull/49) `docs(changelog): write the 2.5.5 notes in
  plain language`, opened as a draft at 23:06. It was reviewed at `c5e3d12` and rechecked at
  `0d9160951d893a10b8611ff3d0158b6d77ae2ec8` (the L-1 swap). It merged at 23:32 as
  `138d593501098e9ee11e33f7f3bf3723b03e3781`, and the release body was then edited to match. See
  [§7](#7-post-release-pr-49-merged-release-edit-done).
- **Date:** 2026-10-21. All times are Europe/Athens (EEST). No credentials were used.

## Plan

1. **Workflow runs.** Main CI on `b9396e7`, then the Release workflow (`workflow_run`).
2. **Tag and commits.**
   - Check the tag object and its target, the release commit's parent, and the #44 merge parents and tree.
   - Verify the tarballs with `git get-tar-commit-id`.
   - Diff the release commit against `b9396e7` and check the version strings.
   - Replay `scripts/release.php` on `b9396e7` and compare `CHANGELOG.md` byte for byte.
3. **GitHub release.**
   - Title, draft and prerelease flags, latest, author and assets.
   - Compare the body with the generated notes and the `[2.5.5]` section.
   - Check that v2.5.4 is untouched.
4. **Tag tree.** gitleaks on the tree and the history, then a smoke of the released tree (`tests/run.sh`, migrate and
   `/health`). The v2.5.4 QA did no image build, so none was done here; the Docker build + smoke job ran in main CI.
5. **#41.** Closed by the merge, and referenced.
6. **Post-release `docs(changelog):` PR.** Only `CHANGELOG.md` in the `[2.5.5]` section, green CI, a release body
   edited to match, accurate facts and links, and the tag unmoved.
7. **Public safety.** No secrets, addresses, account ids or hostnames in the delta or the release body.

## Results

### 1. Workflow runs (PASS)

| Run | Commit | Window | Result |
|---|---|---|---|
| CI (`pull_request`) 39659317509 | `f566541` (#44's final head) | 22:10–22:20 | success, 10/10 |
| CI (push to `main`) 39661094681 | `b9396e7` (#44 merge) | 22:22–22:31 | success: 9 jobs green; `Conventional Commits` skipped, because it is PR-only |
| Release 39662453552 (`workflow_run`) | `b9396e7` | 22:31:56–22:32:15 | success: "Version, changelog, tag and GitHub Release" |
| — | `db983c8` (release commit) | — | no CI, because of `[skip ci]` (expected) |

### 2. Tag and commits (PASS)

- **Tag `v2.5.5`:**
  - The ref points at annotated tag object `a1e82a24…`, created by `github-actions[bot]` at 22:32:09 with message
    `v2.5.5`. It is unsigned, as tags created by the release workflow always are.
  - The tag object points at commit `db983c86f51ce5ecb3bbe30660610ded3c0c38b5` `chore(release): v2.5.5 [skip ci]`,
    which is also the tip of `main`.
  - Re-read at 23:02: unmoved.
- **Parents:**
  - `db983c8` has one parent, `b9396e7`, the merge of #44.
  - `b9396e7` has the parents `7a20646` (`main` with #45 and #47) and `f566541`.
  - `f566541` is `6986578` plus `docs/qa/apotheke-pr44-db-schema-qa.md` only. That file is byte-identical to the final
    #44 QA report.
  - `git merge-tree --write-tree 7a20646 f566541` gives tree `d9e17d78…`, which is `b9396e7`'s tree, so the merge has
    no conflict edits.
- **Tarballs:**
  - `git get-tar-commit-id` on the codeload tarball of `refs/tags/v2.5.5` reports `db983c8`, and a local
    `git archive v2.5.5` does too.
  - The `v2.5.4` tarball still reports `e071921`.
- **Release commit diff against `b9396e7`:** 3 files, the same set as earlier release commits.
  - `VERSION`: `2.5.4` → `2.5.5`.
  - `CHANGELOG.md`: the new `## [2.5.5] - 2026-10-21` section, plus the `[Unreleased]` and `[2.5.5]` compare links.
    Nothing else in the file changes, so `[2.5.4]` with #40's edits is intact.
  - README coverage tables, refreshed by the workflow. "All PHP" is now 6689 lines, 62.5 / 92.1 / 95.3 %, and
    "Bootstrap, config, QR, seed" drops from 90.0 % to 79.1 % combined (I-1).
- **Version strings:** `app_version()` reads `VERSION`. On the released tree, `/health` returns
  `{"status":"ok","version":"2.5.5"}`.
- **Replay:**
  - On a clone at `b9396e7` (the real merge, so the real commit dates), `scripts/release.php plan` gives
    `current=2.5.4 last_tag=v2.5.4 commits=15 bump=patch next=2.5.5 release=true`.
  - `apply` writes a `CHANGELOG.md` **byte-identical** to the one at the tag, and `VERSION` 2.5.5. It touches only
    those two files; the README coverage refresh is the workflow's own step.
  - The section has the expected content:
    - **Fixed:** the four #44 lines. Those are the two `fix(db)` lines (skip CREATE SCHEMA; ignore duplicate schema
      create races) and the two `fix(migrate)` lines (omit passwords; print only allowlisted DSN fields).
    - **Changed:** `qr: rename qr_php84_jit_is_tracing (#45)`.
    - **Maintenance:** 10 lines:
      - #40's three docs and changelog commits, plus its `docs(qa)` commit
      - the #45 QA report
      - #47's `build(docker)` commit and its `docs(qa)` commit
      - #44's `test(db)`, `chore(ci)` and `docs(qa)` commits

### 3. GitHub release (PASS)

- **Title and flags:**
  - Title `v2.5.5`, tag `v2.5.5`, target `main`, author `github-actions[bot]`.
  - Not a draft or a prerelease. `releases/latest` returns `v2.5.5`.
- **Timing:** created at 22:32:09 and published at 22:32:11. At 23:02 it was still not edited (`updated_at`
  22:32:11).
- **Assets:** none, as for v2.5.4.
- **Body:**
  - It is **byte-identical** to the generated notes from the replay, which is the `[2.5.5]` section without its
    heading, plus the `**Full changelog:** …/compare/v2.5.4...v2.5.5` footer.
  - There are no CR characters, no trailing whitespace and no doubled blank lines.
- **v2.5.4 is untouched:** tag object `cab6ffc` still points at `e071921`. The release is still published, not a
  draft, with `updated_at` still the 18:37:52 edit from the previous QA (2026-10-20).
- **Links:**
  - The release, `compare/v2.5.4...v2.5.5`, PRs #40, #44, #45 and #47, and issue #41 all return HTTP 200.
  - The CHANGELOG compare links for `[Unreleased]` and `[2.5.5]` are correct.

### 4. Tag tree (PASS)

- **gitleaks 8.30.1** (the pinned release binary, checked against the workflow's SHA-256) finds no leaks in three
  scans:
  - the extracted tag tree (`gitleaks dir`)
  - the full history at `v2.5.5` with CI's arguments (`gitleaks git --log-opts="--all" --redact`)
  - `v2.5.4..v2.5.5`

  The tree carries #44's `.gitleaks.toml`, so the one allowlisted test fixture stays suppressed as designed.
- **Smoke of the released tree** (codeload tarball, clean environment):
  - `tests/run.sh` (lint and HTTP integration, SQLite): **1423 passed, 0 failed**.
  - `bin/migrate.php` on a fresh SQLite file exits 0, with the banner `Database: sqlite – <path>`.
  - `php -S`: `/healthz` returns 200 `ok`, and `/health` reports version 2.5.5.
- **Image:** the v2.5.4 QA did no local image build, so none was done here. Main CI's `Docker build + smoke test` job
  passed on `b9396e7`, whose tree differs from the tag only by `VERSION`, `CHANGELOG.md` and the README tables.

### 5. #41 (PASS)

#41 (`fix(db): CREATE SCHEMA IF NOT EXISTS 500s for users without CREATE`) was closed as **completed** at 22:22:40,
by the #44 merge, which says "fixes #41". The generated CHANGELOG and release lines cite #44 only. A direct #41 link is
one of the post-release edits expected in §7.

### 6. Public safety (PASS)

The release body, the `[2.5.5]` section and the release commit contain only `github.com/thordin9/apotheke` PR and
compare URLs. There are no IP addresses, other hostnames, tokens, keys, account ids or e-mail addresses.

### 7. Post-release PR #49 (merged; release edit: done)

No PR had opened within 30 minutes of the tag, by 23:02. Coder opened #49 as a draft at 23:06: one commit, `c5e3d12`, on
`db983c8`, which is still `main`. It is mergeable.

**PR reviewed (PASS, with one Low and two Nits):**

- **Diff:**
  - Only `CHANGELOG.md` changes, with 5 lines added and none removed. The brief said 6 lines; the diff has 5: 2 `db`,
    1 `migrate`, 1 `qr` and 1 `docker`.
  - All the added lines are inside `[2.5.5]`. Everything outside that section, including `[Unreleased]`, `[2.5.4]`
    and the compare links, is byte-identical to the tag.
  - All 17 generated lines stay byte-identical and in order. Each new line sits directly under the generated line it
    explains.
  - There are no CR characters and no trailing whitespace.
- **Facts:**
  - **db, #41/#44:** "CREATE SCHEMA is skipped when DB_SCHEMA already exists, so a role without CREATE no longer gets
    a 500". This matches #41 and the #44 QA (`/`, `/healthz`, `/health` and `/labels` went from 500 to 200).
  - **db, races:** "concurrent first-start creation of the same schema no longer fails". This matches the 23505/42P06
    handling: 320 of 320 connections OK in the #44 race test, against 68 failures before.
  - **migrate:** "prints only the driver, host, port, dbname and DB_SCHEMA, never the user or the password". This
    matches the code and the docs at the tag. SQLite prints the file path instead, and an unparsed DSN prints
    `<unparsed dsn>`, which the line doesn't need to cover.
  - **qr:** "renamed from qr_php84_jit_is_tracing to qr_jit_is_tracing". At the tag, `src/qr.php` defines
    `qr_jit_is_tracing()`, and the old name survives only in the CHANGELOG and older QA reports.
  - **docker:** "the image builds Imagick from a pinned source tarball". At the tag, the `Dockerfile` fetches the
    Imagick 3.8.0 GitHub tag tarball pinned by SHA-256 and builds it from source.
- **Links:** issue #41 and PRs #44, #45 and #47 all return HTTP 200, and each link points at the right item.
- **Format:**
  - The bullet style, the bold scope and the inline `([#n](…/issues/n), [#m](…/pull/m))` links match #40's 2.5.4
    edit.
  - Unlike #40, the generated lines are kept rather than replaced, so each change appears twice (L-1).
- **Commits:** the subject passes `scripts/check-commits.sh --message`, and the Conventional Commits job is green.
- **Replay after merge:**
  - With a `--no-ff` merge of #49 onto `db983c8`, `plan` gives `current=2.5.5 last_tag=v2.5.5 commits=1 bump= next=
    release=false`.
  - With a `docs(qa):` commit added: `commits=2 … release=false`.
  - Neither case releases.
- **gitleaks 8.30.1:** no leaks in the full history at `c5e3d12` (CI's arguments), in `db983c8..c5e3d12`, or in the
  diff.
- **CI:** run 39667463295 on `c5e3d12`, **10/10 success**: Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Conventional Commits, Headless mobile
  checks, Docker build + smoke test, Visual E2E shards 1/3, 2/3 and 3/3, and Secret scan. The last job finished at 23:15.
- **Tag:** unmoved, `a1e82a24` → `db983c8`.

**Delta recheck at `0d91609` (PASS, L-1 resolved):** Coder pushed one commit, `0d9160951d89`
`docs(changelog): replace generated 2.5.5 lines with notes` (57 characters, with a `Co-authored-by` trailer), on top
of `c5e3d12`, at 23:21. Only that delta was rechecked.

- **Compare `c5e3d12...0d91609`:** `ahead`, 1 ahead and 0 behind, 1 commit. Only `CHANGELOG.md` changes (+0/−6): the six
  generated lines that #49 had explained are removed. The commit's tree, `4dd9bdf…`, is exactly `c5e3d12`'s tree with
  that `CHANGELOG.md` swapped in, as rebuilt locally. GitHub shows the commit as verified.
- **Against the release commit `db983c8`:** only `[2.5.5]` changes, +5/−6, as Coder claimed. Everything before and
  after the section, including `[Unreleased]`, `[2.5.4]` and the compare links, is byte-identical to the tag.
  - **Fixed (3 lines):** the #41/#44 schema line, the concurrent-creation line, and one `migrate` line ("bin/migrate.php
    prints only the driver, host, port, dbname and DB_SCHEMA, never the user or the password"). The migrate line
    replaces both generated `fix(migrate)` lines (omit passwords; print only allowlisted DSN fields), which it covers
    in full.
  - **Changed (1 line):** the `qr` rename line.
  - **Maintenance (10 lines):** the docker note ("the image builds Imagick from a pinned source tarball") replaces the
    generated `build: fetch imagick from a pinned GitHub tarball` line in the same position. The other 9 lines are
    byte-identical and in their generated order.
  - There are no duplicate lines, no CR characters and no trailing whitespace. The facts are the ones already
    verified at `c5e3d12`, and the #41, #44, #45 and #47 links still return HTTP 200.
- **CI:** run 39669631260 (`pull_request`) on `0d91609`, 23:21:44–23:31:08, **10/10 success**: Lint, Tests (PHP 8.3,
  sqlite), Tests (PHP 8.3, pgsql), Conventional Commits, Secret scan, Headless mobile checks, Docker build + smoke
  test, and Visual E2E shards 1/3, 2/3 and 3/3.
- **gitleaks 8.30.1** (pinned binary): no leaks in the CHANGELOG at the head, the `db983c8` diff, or the expected
  release body. The delta has no URLs other than `github.com/thordin9/apotheke`.
- **Replay after merge:** I built `0d91609`'s tree locally (identical tree hash) and made a `--no-ff` merge onto
  `db983c8`, using `scripts/release.php` at the head. `plan` gives `current=2.5.5 last_tag=v2.5.5 commits=2 bump=
  next= release=false`. With a `docs(qa):` commit added, it gives `commits=3 … release=false`.
- **State at 23:31:** #49 is open, still a draft, and mergeable (`unstable`, only because it is a draft). `main` is
  still `db983c8`, the tag is unmoved (`a1e82a24` → `db983c8`), and `releases/latest` is `v2.5.5`. The live release
  body is still byte-identical to the 22:34 capture of the generated notes, so it has not been edited yet.

**Merge (PASS):**

- #49 merged at 23:32:45, pinned to head `0d91609`, with `merge_commit_sha` `138d593`. It was marked ready (not a
  draft) and retitled `docs(changelog): write the 2.5.5 notes in plain language`.
- `138d593` is a `--no-ff` merge with the parents `db983c8` and `0d91609` and the message "Merge pull request #49 from
  thordin9/cursor/changelog-db-schema-255-9d44". Its tree, `4dd9bdf…`, is `0d91609`'s tree, so the compare
  `0d91609...138d593` has 0 files. `CHANGELOG.md` at `138d593` is byte-identical to `0d91609`'s, and `VERSION` is
  still `2.5.5`.
- `main` is `138d593`.

**Main CI and Release (PASS, no release):**

- CI (push) run 39671239017 on `138d593`, 23:32:48–23:42:59: **9 jobs success**. `Conventional Commits` was skipped,
  because it is PR-only (as on `b9396e7`).
- Release run 39672700931 (`workflow_run`), 23:43:01–23:43:15: success. "Only release the commit CI tested" and
  "Plan" ran. "Refresh README coverage table", "Update VERSION and CHANGELOG.md, commit, tag and push" and "GitHub
  Release" were all **skipped**, which is `release=false`, as the replay predicted.
- At 23:44, there were no new tags or releases. The newest are still `v2.5.5` → `db983c8` and `v2.5.4` → `e071921`,
  and there is no `v2.5.6`.

**Release edit (PASS):**

- **Body:** the live v2.5.5 body (`releases/tags/v2.5.5`) is **byte-identical** to the expected body, 1,904 B, SHA-256
  `13c12bb9b359b6287cdea2b0a9a0b91ada9f5f4a2bd485e7dca9c54fddf71aa9`.
- **Regenerated at `138d593`:** the `[2.5.5]` section without its heading and trimmed, plus `**Full changelog:**
  https://github.com/thordin9/apotheke/compare/v2.5.4...v2.5.5`, gives the same bytes. The body was re-read after
  the Release run and is unchanged.
- **Release:** title `v2.5.5`, published at 22:32:11 (it was edited, not re-created), with no assets. Not a draft or a
  prerelease. `releases/latest` returns `v2.5.5`.
- **Tag:** unmoved. The ref is the annotated tag `a1e82a24`, which peels to `db983c8`.
- **v2.5.4:** untouched. The tag `cab6ffc` still points at `e071921`. The release is published, not a draft, and its
  body is byte-identical to the 22:34 capture.

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| I-1 | Info | The README coverage refresh shows "Bootstrap, config, QR, seed" at 79.1 % combined, down from 90.0 %, and "All PHP" at 95.3 %, down from 96.3 %. #44's new DSN parser in `src/config.php` is tested only through a `bin/migrate.php` subprocess (`proc_open` in `MigrateDsnTest`), which coverage doesn't count. The behaviour is well tested (21 cases, mutants killed in the #44 QA). | Optional: call `config_migrate_where()` in-process in a few `MigrateDsnTest` cases. |
| L-1 | Low (resolved at `0d91609`) | #49 keeps every generated line and adds a plain-language line under it, so each `[2.5.5]` change appears twice, for example "skip CREATE SCHEMA when DB_SCHEMA already exists" and "CREATE SCHEMA is skipped when DB_SCHEMA already exists, …". #40 *replaced* the generated 2.5.4 line instead, as N-3 asked then. | Either replace each generated line with its plain-language version, as #40 did, or accept the duplicates as the new convention and say so in `docs/releasing.md`. Not blocking. **Resolved:** `0d91609` replaces each generated line, as #40 did, and the two migrate lines become one. |
| N-1 | Nit | The brief describes #49 as adding 6 lines. It adds 5. | None, the diff is right. |
| N-2 | Nit | The fenced notes block in #49's description is the raw section, with a leading blank line and no `**Full changelog:**` footer, so it isn't the agreed release body. | For `gh release edit`, use the trimmed section at the merged head plus the existing footer. |
| N-3 | Nit (closed) | #49 is still a draft, and its title is still `docs(changelog): add the DB schema and migrate notes to 2.5.5`, although the PR now also replaces lines. | Mark it ready for review before the merge. The title can stay, because it doesn't affect the release (non-`feat`/`fix`/`perf`). **Closed:** #49 was marked ready and retitled `docs(changelog): write the 2.5.5 notes in plain language` before the merge. |
| I-2 | Info | The next release will list #49, and this report's `docs(qa):` commit if added, under Maintenance. This is the usual pattern. | None. |

No high or medium findings.

## Verdict

**PASS. The v2.5.5 tag and the release are correct, #49 merged as `138d593` without releasing, and the release body
now matches the plain-language `[2.5.5]` section byte for byte.**

- **Tag:** `v2.5.5` (`a1e82a2`) points at `db983c8`. Its parent is `b9396e7`, the conflict-free merge of #44
  (`f566541`) into `7a20646`.
- **Release commit:** changes only `VERSION` (2.5.5), `CHANGELOG.md` and the README coverage tables. The CHANGELOG
  matches the replay byte for byte.
- **GitHub release:** published, latest, not a draft or prerelease, with no assets. Its body is the generated notes,
  byte-identical to the `[2.5.5]` section. v2.5.4 is untouched.
- **Tag tree:** gitleaks is clean, `tests/run.sh` passes 1423 of 1423, and the served version is 2.5.5. #41 is closed
  by the merge.
- **#49 at `0d91609`:** only `CHANGELOG.md` changes, only inside `[2.5.5]` (+5/−6 against `db983c8`). Each generated
  line is replaced by its plain-language version, as in #40: Fixed has 3 lines, Changed has 1, and Maintenance has
  10, with the docker note in the place of the build line. There are no duplicates, CI run 39669631260 is 10/10,
  gitleaks is clean, and merging it doesn't release.
- **Merge and edit:** `138d593` changes nothing beyond `0d91609`. Main CI run 39671239017 is green, and Release run
  39672700931 skipped the release steps, so there is no new tag or release. The live body equals the trimmed section
  at `138d593` plus the footer (1,904 B, `13c12bb9…71aa9`). The tag is still `db983c8`, latest, draft and prerelease
  are unchanged, and v2.5.4 is untouched.
