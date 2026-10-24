# QA: apotheke PR #67, link the older ci4 Unreleased CHANGELOG bullets

- **PR:** [#67](https://github.com/thordin9/apotheke/pull/67), "docs(changelog): link the older ci4 Unreleased bullets", branch `ci4/changelog-links` into `feature/ci4`, by DevOps. Open, not a draft. It closes #65's QA L-1.
- **Base:** `feature/ci4` at `8863a5c44b9ff528c532e3844ec4303a55d9ff3e` (#64's merge), which is still the branch tip. The compare API says `ahead`, 1 ahead, 0 behind, merge base `8863a5c`.
- **Head tested:** `b0724a881e1b1e8c564fd78ac4c45414f70bde0a`. One commit, parent `8863a5c`. The codeload tarball's `git get-tar-commit-id` is the same SHA.
- **Changes:** 1 file, `CHANGELOG.md`, +11 / −11. No code, test, workflow or `ci4/` change.
- **CI:** run 40073257591 (`pull_request`, attempt 1) at `b0724a8`, **22/22 `success`**, 10:13–10:23 Athens time (UTC+3).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only. The GitHub API (PR, compare, PR files and patches, run and jobs), raw `CHANGELOG.md` at nine commits, `main`'s `scripts/release.php` at `a6e89c7`, and the head tarball. Local runs only in a scratch directory. No comment, review, push or merge was made.
- **Verdict: PASS.** No High, Medium or Low findings. All 11 links go to the PR that introduced the bullet, and `main`'s link check fails on the base and passes on the head. One Nit (N-1) and one Info item, both outside this diff.

## 1. Test plan

1. **Delta.** Resolve the full head SHA. Check it's 1 commit on the current `feature/ci4`, 0 behind, touching only `CHANGELOG.md`. Check the subject with `scripts/check-commits.sh --message` and its length.
2. **Byte check.** Every changed line must be the base line plus exactly ` ([#N](https://github.com/thordin9/apotheke/pull/N))`, and every other line, including all released sections, must be byte-identical.
3. **Mapping.** For each of the 11 bullets, check its linked PR (#31, #34, #38 or #53) really introduced it. Use two independent sources: the PR's own `CHANGELOG.md` patch from the PR files API (`+` line), and the raw `CHANGELOG.md` at the PR's base and merge commits (absent before, present after).
4. **Coverage.** No `- ` line under `## [Unreleased]` lacks a PR link. Also check the already-linked #61/#64/#65/#66 bullets.
5. **Link check.** `main`'s `php scripts/release.php check-links` (usage: `plan | apply | check-links`; it reads `CHANGELOG.md` from the script's parent directory) on the base and the head CHANGELOG, on PHP 8.3 and 8.4, plus one mutation. `feature/ci4`'s own `scripts/lint.sh` on the head tree.
6. **CI.** Run 40073257591's head SHA, event and job conclusions.
7. **Security and Cloudflare phase.**

## 2. Results

### 2.1 Delta (item 1)

- Head `b0724a881e1b1e8c564fd78ac4c45414f70bde0a`, parent `8863a5c`. `feature/ci4` is still at `8863a5c`, so the PR is 1 ahead and 0 behind.
- Only `CHANGELOG.md` changes (11 additions, 11 deletions).
- Subject `docs(changelog): link the older ci4 Unreleased bullets`: 54 characters, and `check-commits.sh --message` says `ok`. The body lines are 71, 70 and 72 characters. CI's Conventional Commits job is green.

**PASS**

### 2.2 Byte check (item 2)

- The base is 38,673 B and the head is 39,267 B, 503 lines each. That's +594 B, which is 11 × 54 B suffixes.
- Exactly 11 lines differ: 25, 26, 33, 39, 40, 45 and 47–51, all in `## [Unreleased]` (lines 8–55). Each head line starts with the full base line and adds only ` ([#N](https://github.com/thordin9/apotheke/pull/N))`. No words are removed or changed.
- Everything from `## [2.6.1]` down is byte-identical. The file still ends with one newline and has no CR.
- The line numbers in the PR body's mapping table match the changed lines.

**PASS**

### 2.3 Mapping (item 3)

All four PRs were merged into `feature/ci4`: #31 (`ef93566`), #34 (`d94465f`), #38 (`4b27ab0`), #53 (`b6b713e`). #38's base is #34's merge commit.

The "PR patch" column says whether the bullet's opening phrase is a `+` line in that PR's `CHANGELOG.md` patch. The base and merge columns say whether the phrase is in the raw `CHANGELOG.md` at those commits.

| Line | Bullet (start) | Link | PR patch | At PR base | At PR merge | On `main` |
|---|---|---|---|---|---|---|
| 25 | **ci4:** CodeIgniter 4.7.5 skeleton and Core | #38 | added | absent | present | absent |
| 26 | **ci4:** Migrations module | #53 | added | absent | present | absent |
| 33 | **ci4:** quote every `pg_connect` value | #38 | added | absent | present | absent |
| 39 | **ci4:** read `trust_proxy` from the `DB_SCHEMA` search path | #38 | added | absent | present | absent |
| 40 | **ci4:** a role without CREATE can use an existing schema | #53 | added | absent | present | absent |
| 45 | **ci:** `ci4-pgsql-proof.php` reports SQLSTATE 42P01 | #38 | added | absent | present | absent |
| 47 | **test:** a production CI4 boot with `APOTHEKE_TEST_DB` unset | #38 | added | absent | present | absent |
| 48 | **ci:** run CI on pushes to `feature/ci4` | #31 | added | absent | present | absent |
| 49 | **ci:** run lint, headless mobile checks, Playwright … | #34 | added | absent | present | absent |
| 50 | **test:** compare two legacy installs | #34 | added | absent | present | absent |
| 51 | **fix:** QR labels finish under PHP 8.4 function JIT | #34 | added | absent | present | absent |

- **No wrong mapping.** Each bullet first appears in the linked PR, and none came in through a `main` sync.
- **Later rewordings match the PR body.** #53's patch rewrites the line-25 bullet (a `−` and a `+` line), and #38's patch rewrites the line-48 bullet. The PR body lists both under "Later edit". Linking the introducing PR follows the house rule.

**PASS**

### 2.4 Coverage (item 4)

- There are 25 `- ` bullets under `## [Unreleased]`, and every one ends in a `([#N](https://github.com/thordin9/apotheke/pull/N))` link. The base had 11 without one, and the head has 0.
- #65 counted 12 at `aa64ac6`. The twelfth, the "PostgreSQL DSN may have spaces around `=`" bullet, was reworded by #66 and linked to #66. That's why the PR finds 11, and its body is right. It was introduced by #53, though (N-1).
- The other already-linked bullets (#61, #64, #65, #66) first appear in the PR they link to.

**PASS**

### 2.5 Link check and lint (item 5)

`main`'s `scripts/release.php` comes from `a6e89c7`, a single file with no requires. `main`'s `scripts/lint.sh` runs `php scripts/release.php check-links`. Each run was in a scratch directory holding only `scripts/release.php` and the `CHANGELOG.md` under test.

| CHANGELOG | PHP 8.3 | PHP 8.4 | Output |
|---|---|---|---|
| base `8863a5c` | exit 1 | exit 1 | `Unreleased bullets need a pull-request link:` and the 11 bullets above |
| head `b0724a8` | **exit 0** | **exit 0** | none |
| mutation: head with the `#34` suffix removed | – | exit 1 | lists the 3 #34 bullets |

`feature/ci4`'s own `scripts/lint.sh`, run on the head tarball:

- PHP lint, JS syntax and shellcheck are ok.
- Markdown links: 233 relative links checked in 62 files, 0 broken.
- actionlint needs a git checkout, so it stopped on the bare tarball. In a throwaway `git init` copy it's ok.
- The pipefail-safe grep step is ok.

`feature/ci4`'s `release.php` predates #62 and has no `check-links` yet (Info I-1).

**PASS**

### 2.6 CI (item 6)

- **Run 40073257591:** workflow CI, event `pull_request`, branch `ci4/changelog-links`, `head_sha` `b0724a881e1b1e8c564fd78ac4c45414f70bde0a`, attempt 1. It is `completed` / `success`, 10:13–10:23 Athens time (UTC+3).
- All **22/22** jobs are `success`, and every one is at `b0724a8`:
  - Lint, Headless mobile checks, Docker build + smoke test and Visual E2E shards 1–3: each on PHP 8.3 and 8.4.
  - Tests: legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql.
  - deptrac, DI rule, Conventional Commits and Secret scan.

**PASS**

## 3. Security

- The diff is docs only: 11 appended link suffixes, with no code, config, workflow or dependency change.
- **Added lines:** the only URLs are pull-request links in this public repository (`/pull/31` ×1, `/pull/34` ×3, `/pull/38` ×5, `/pull/53` ×2). There are no tokens, keys, passwords, IP addresses, email addresses, service hostnames or account IDs. The words "password" and "DSN" appear only in the unchanged base text of the bullets.
- CI's Secret scan job is green at the head.
- No High or Medium security finding.

**PASS**

## 4. Cloudflare-phase note

The PR body has a "Cloudflare phase" section. It says there is no effect, because this is a docs-only change to `CHANGELOG.md` with no Worker code, wrangler config, workflow or deploy. That's correct:

- **Storage:** no change.
- **Per-request global or static state:** none added.
- **DB config:** unchanged, still DSN-driven.

**PASS**

## 5. Findings

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| N-1 | Nit | Outside this diff: the Unreleased bullet "a PostgreSQL DSN may have spaces around `=`" was introduced by #53, then reworded by #66, which linked it to #66. #67 links its other bullets to the introducing PR, so this one is the only exception. The #66 link is defensible, because #66 changed what the bullet says. | Optional: leave it, or link both (`#53`, `#66`) in a later CHANGELOG touch. Not needed for `check-links`. |
| I-1 | Info | `feature/ci4` gets `check-links` only with the next `main` → `feature/ci4` sync. Until then, nothing on ci4 stops a new unlinked bullet. Locally, `scripts/lint.sh`'s actionlint step needs a git checkout. | Keep asking ci4 PRs to link their bullets. Re-run `check-links` in the sync review. |

No High, Medium or Low findings.

## 6. Verdict

**PASS at `b0724a881e1b1e8c564fd78ac4c45414f70bde0a`.**

- **Diff:** 1 commit on `feature/ci4` `8863a5c`, 0 behind. Only `CHANGELOG.md` changes, and only by 11 appended PR links in `[Unreleased]`. Released sections are byte-identical.
- **Mapping:** all 11 links name the PR that introduced the bullet, confirmed both from the PR patches and from the CHANGELOG before and after each merge.
- **Link check:** `main`'s `check-links` fails on the base with exactly these 11 and passes on the head, so #65's L-1 is closed.
- **Lint and CI:** ci4's lint is clean, and CI is 22/22 green on the head.
- **Security and Cloudflare phase:** no secrets, and no Cloudflare-phase effect.
