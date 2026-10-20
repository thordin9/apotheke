# Apotheke PR #29: Feature 12 PR 1, `docs!: allow CodeIgniter 4`, QA

- PR: thordin9/apotheke#29 (draft), "docs!: allow CodeIgniter 4 (AGENTS.md, README)", branch
  `cursor/ci4-pr1-allow-ci4` → `feature/ci4`
- Head tested: **`57ebe9b31e4f28d7c928e4ea5fbc1dc73ee4a8be`**
- Base: `feature/ci4` = v2.5.0 = **`87850e379d85d6690e232ba107373208f9b7ffbd`**
- Approved plan: rev. 3, PLAN.md `0dcad0f7…ca14b`, APPENDIX-A `9b6d33bb…d022`, APPENDIX-B `36d13904…3f05`
- Date: 2026-10-20 (Europe/Athens)
- Scope: docs-only review. Read-only: codeload tarballs of base, `e4fc41c`, `a477465` and head, plus the GitHub API
  (PR, commits, files, check runs). No comment, review, push or merge was made. No Neurons used.

## 1. Test plan

### 1.1 Environment

- Tarballs from codeload for `87850e3`, `e4fc41c`, `a477465` and `57ebe9b`, unpacked side by side and compared with
  `diff -r`, `sha256sum` and `diff -u`.
- GitHub API: PR object, commit list, changed-file list, check runs for the head.
- gitleaks **8.30.1** (`gitleaks dir`), ripgrep, the repo's `scripts/check-links.mjs` (Node), and a QA link checker that
  also covers `AGENTS.md`, `docs/adr/` and `docs/plans/`.

### 1.2 Acceptance checks

1. Commits are `e4fc41c` → `a477465` → head, on base `87850e3`; changed-file list.
2. `e4fc41c` holds the three plan files byte-identical to the approved rev. 3. The PLAN.md diff `e4fc41c` → head holds
   only L-a, L-b, the "PM L-2" / "QA L-2" labels, the base-verified note and a changelog line.
3. Diff `87850e3` → head is docs only: `AGENTS.md`, `README.md`, `docs/contributing.md`, `CONTRIBUTING.md`, ADR-001,
   `docs/plans/feature12-ci4/*`. `VERSION`, `CHANGELOG.md` and `.github/workflows/*` untouched; no code.
4. Rule wording matches Stathis's decisions: CI4 allowed with pinned dependencies (runtime Composer only for CI4 and its
   pinned set); still no CDN, no JS build, offline on a LAN; Docker-only installs after cutover; one re-login at upgrade;
   old "no framework / no runtime Composer" wording gone or qualified. Whole-tree grep for leftover phrases. ADR-001
   consistent with the plan (side by side in `ci4/`, no schema change, Docker-only, re-login, PHP 8.4, 3.0.0, 2.5.x
   security fixes for 90 days).
5. Secrets and hosts: gitleaks on the head tree; grep for the production hostname, IPs other than 127.0.0.1, account
   ids and tokens. The only Worker URL allowed is `https://autofill.example`.
6. CI: every check run on the head.
7. Links: relative links in the changed docs resolve in the head tree (files and anchors).

## 2. Results

### Check 1: commits and files (PASS)

| # | Commit | Parent | Time (EEST) | Subject |
|---|---|---|---|---|
| 1 | `e4fc41c932d4b467e3118f131c823fcbf74e0ed5` | `87850e3` | 10:23:50 | `docs: add Feature 12 CI4 plan verbatim` |
| 2 | `a47746588ef60fb6a24cc93f2f95f2d52baf0630` | `e4fc41c` | 10:25:04 | `docs: apply reviewer fixes to the CI4 plan` |
| 3 | `57ebe9b31e4f28d7c928e4ea5fbc1dc73ee4a8be` | `a477465` | 10:26:48 | `docs!: allow CodeIgniter 4 (AGENTS.md, README)` |

Linear history, 3 commits, all three signature-verified. PR base sha `87850e3`, draft, mergeable state clean.
8 files, +1138 / −12:

| File | Status | + / − |
|---|---|---|
| `AGENTS.md` | modified | 31 / 3 |
| `CONTRIBUTING.md` | modified | 4 / 2 |
| `README.md` | modified | 14 / 4 |
| `docs/contributing.md` | modified | 16 / 3 |
| `docs/adr/001-codeigniter4.md` | added | 34 / 0 |
| `docs/plans/feature12-ci4/PLAN.md` | added | 519 / 0 |
| `docs/plans/feature12-ci4/APPENDIX-A-inventory.md` | added | 310 / 0 |
| `docs/plans/feature12-ci4/APPENDIX-B-schema.sql.md` | added | 210 / 0 |

Per commit: `e4fc41c` adds only the three plan files; `a477465` changes only PLAN.md; `57ebe9b` changes only the four
rule docs and adds ADR-001.

### Check 2: plan files (PASS)

| File | Approved rev. 3 | `e4fc41c` | head |
|---|---|---|---|
| PLAN.md | `0dcad0f7…ca14b` | `0dcad0f7…ca14b` (identical) | `1980d2e20f4dc1d22a2dbd24b9845eb11d4deacd5417e5a73427708afd26a20e` |
| APPENDIX-A-inventory.md | `9b6d33bb…d022` | identical | identical |
| APPENDIX-B-schema.sql.md | `36d13904…3f05` | identical | identical |

PLAN.md `e4fc41c` → head (`a477465`; head adds nothing to it): 17 hunks, 38 changed lines. Every change maps to one
of the approved fixes:

- **Base note** (header): "The v2.5.0 tag check is verified (QA PASS at 10:15 EEST, 87850e3, only VERSION, CHANGELOG
  and README coverage tables changed)"; the sentence around it reflowed, PM re-scan kept.
- **L-a**: new line above the §4.2 table, "19 CI4 PRs (1–10, 11a, 11b, 12–18), plus the main-side `release/2.5` PR and
  L-4", and the same phrase replaces "PRs renumbered 1–18 (with 11a/11b)" in the rev. 3 changelog entry.
- **L-b**: PR 2 row "runs only the CI4 leg (PHP 8.4) plus legacy@8.3 … the legacy@8.4 runs move to PR 3"; PR 3 row
  starts "legacy@8.4 runs (moved from PR 2)". The PR 2 acceptance criteria are unchanged. §2.6, R13 and §4.6 already
  said legacy@8.4 exists from PR 3, so nothing else contradicts L-b.
- **PM L-2** (migration backport rule): §3.4, §3.6 rollback A, §4.5, §5 `docs/database.md` row, R14, rev. 3 changelog.
- **QA L-2** (broken JPEG / >60 MP → 422 quirk): §2.7, PR 3 and PR 10 rows, §5.2 allowlist table, R12, rev. 3
  changelog. No bare "L-2" is left in PLAN.md.
- **Changelog line**: a new "Reviewer fixes (2026-10-20)" entry above rev. 3.

No other change. Both edits to the rev. 3 changelog entry are L-a/label edits (I-1).

### Check 3: scope of the diff (PASS)

`diff -r 87850e3 57ebe9b`: only the 8 files above. `VERSION` (2.5.0) and `CHANGELOG.md` are byte-identical
(sha256 `732f64d8…c61c` and `db504b22…b5b8`). `.github/` is identical, so `ci.yml` and `release.yml` are untouched.
No code, test, script, Docker or asset file changed.

### Check 4: rule wording (PASS, L-1, L-2, L-3, N-2)

| Decision | AGENTS.md | README | docs/contributing.md | CONTRIBUTING.md | ADR-001 |
|---|---|---|---|---|---|
| CI4 replaces the no-framework rule, pinned deps, no other framework | yes | yes | yes | yes | yes (1) |
| Runtime Composer only for CI4's pinned set | "Do not add any other runtime package" | legacy: none; CI4: pinned | "CI4 dependencies are the pinned CodeIgniter 4 set" | pinned | pinned |
| No CDN, no JS build | yes (hard constraint) | yes (intro) | yes | no JS build | **not stated** (L-2) |
| Offline on a LAN | sentence on the legacy app | intro | — | — | **not stated** (L-2) |
| Side by side in `ci4/`, legacy `main` unchanged to 3.0.0 | yes | yes | yes | yes | yes (2) |
| No schema change, 25 migrations, PDO runner + LegacyFunctions | yes (+ no 0026+ unless backported) | yes | yes | — | yes (3) |
| Docker-only after cutover, no vendored zip | yes | yes | yes | — | yes (4) |
| One re-login | yes | yes | yes | — | yes (5) |
| PHP 8.4 (CI4); legacy 2.5.x on 8.3 | yes (N-2) | yes | yes | yes | yes (6) |
| 3.0.0 cutover; 2.5.x security fixes on `release/2.5` for 90 days | yes | yes | yes | yes | yes (consequences) |
| deptrac graph, constructor injection, `service()` only in controllers | yes (full edge list, matches plan) | summary + link | summary + link | — | link to AGENTS |
| CI4 PRs target `feature/ci4` | yes | — | yes | — | yes |

The old wording is removed or qualified as "legacy app" in all four rule docs. ADR-001 records Stathis's approval
(10:09 EEST, "yes to all"), matching the plan's decisions log.

Whole-tree grep at head (`no framework`, `no-framework`, `no composer`, `no runtime composer`, `zero/no (runtime)
dependencies`, `shared hosting`, `without docker`, `non-docker`, `deliberately small`, `plain php`):

| Hit | Verdict |
|---|---|
| `docs/index.md:11–12` "It is plain PHP 8.2+ … no framework, no runtime Composer packages, no JavaScript build step and no CDNs." | **Unqualified, contradicts the new rule** (L-1) |
| `docs/architecture.md:3–4` "deliberately small … There is no framework, no autoloader, no template engine and no build step." | Unqualified, true of legacy only (L-1) |
| ADR-001:10 and :34; PLAN.md:22 decisions log; APPENDIX-A:292 (baseline inventory) | Historical / intentional |
| AGENTS.md:3, :5, :17; README:20, :214, :216, :220; docs/contributing.md:8–9, :81; CONTRIBUTING.md:3 | New wording, qualified as legacy |
| README "Install without Docker" (:33, :293), AGENTS.md:66, `docs/getting-started.md` "Without Docker", `docs/deployment-and-operations.md:40, :77` | Legacy install, still valid until 3.0.0 (README says so) |
| "no Composer" for `tests/run.sh` (AGENTS:79, README:394, :539, testing.md:14, getting-started.md:112) | About the test runner, still true |
| `docs/superpowers/*` (2026-10-12 spec and plan) | Historical design notes |
| `src/qr.php:5`, `scripts/release.php:5`, `public/assets/app.css:1` "no dependencies" | Code comments about those files, fine |
| "shared hosting" | 0 hits |

ADR-001 vs plan: side by side in `ci4/` with move to root at cutover (plan PR 18), no schema change with the PDO runner
and LegacyFunctions kept after cutover, Docker-only with no vendored zip, re-login, PHP 8.4 for CI4 and 8.3 for 2.5.x,
3.0.0, 2.5.x security-only on `release/2.5` for 90 days, rollback needs no data step. All consistent. Location differs
from plan §5 (L-3).

### Check 5: secrets and hosts (PASS)

- gitleaks 8.30.1 `dir` on the head tree: **0 findings** (base: 0). On the 8 changed files alone: 0.
- Production hostname: **0 hits** in the whole head tree.
- Lines added by the PR (rule docs + ADR, 99 lines): no IP, no URL, no 32-hex id.
- Changed files overall: IPs are 127.0.0.1, plus `0.0.0.0` and the `192.168.1.10` LAN example in unchanged README
  lines. The only Worker URL is `https://autofill.example` (README:71, PLAN.md:292, :446, :515, APPENDIX-A:220).
  Other URLs are GitHub, conventionalcommits.org, semver.org and playwright.dev. Long hex strings are commit SHAs.
  `secret`, `change-me` and `a-strong-password` are existing placeholders in unchanged lines.

### Check 6: CI (PASS)

10/10 check runs on `57ebe9b`, run 39386617659 (`pull_request`), all `completed` / `success`, 10:27–10:36 EEST: Lint,
Conventional Commits, Secret scan, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Docker
build + smoke test, Visual E2E shards 1/3, 2/3, 3/3.

### Check 7: links (PASS, N-3)

- `node scripts/check-links.mjs` at head: 197 relative links in 16 files, 0 broken (matches Coder).
- That script reads only `README.md`, `CONTRIBUTING.md` and `docs/*.md`, so it skips `AGENTS.md`, `docs/adr/` and
  `docs/plans/` (N-3). QA's checker over the 8 changed files (files and `#anchors`): **120 links, 0 broken**, including
  `AGENTS.md#ci4-module-rules`, `../../AGENTS.md#ci4-module-rules`, `../plans/feature12-ci4/PLAN.md`,
  `README.md#install-without-docker` and the appendix links.

## 3. Findings

| Id | Sev | Finding | Status / suggestion |
|---|---|---|---|
| L-1 | L | `docs/index.md:11–12` (the manual's landing page) and `docs/architecture.md:3–4` still say "no framework, no runtime Composer packages" without a legacy qualifier. ADR-001 says these pages describe the legacy app until the docs rewrite (plan PR 17), and PR 1's planned scope excludes them | Open, non-blocking. Cheapest fix: start the `docs/index.md` sentence with "The legacy app (`main`, 2.5.x) is …" and add one link to ADR-001. Otherwise accept until PR 17 |
| L-2 | L | ADR-001 doesn't record the constraints that stay: no CDN, no JS build step, fully offline on a LAN, runtime Composer only for CI4's pinned set. In AGENTS.md, "It must work fully offline on a LAN" follows the description of the legacy app, so it reads as legacy-only. The README CI4 block doesn't restate it either | Open, non-blocking. Add ADR decision 7: "Unchanged: no CDN, no JavaScript build step, works fully offline on a LAN; the only runtime Composer packages are CodeIgniter 4 and its pinned dependencies." Add the same line to the README CI4 block |
| L-3 | L | Plan §5 table names `docs/ci4/PLAN.md` and `docs/ci4/adr/*`, but PR 1 puts the plan in `docs/plans/feature12-ci4/` and ADR-001 in `docs/adr/` | Open, non-blocking. Settle the location once: fix the §5 row in a later reviewer-fix commit, or move the files. Later ADRs (layout, PDO runner, sessions, …) should use the same folder |
| N-1 | nit | PLAN.md:3 still says "Status: local draft by ITAdvisor. Nothing has been posted, pushed or opened." It is now committed and approved | Open. "Status: approved rev. 3 (PM + QA), committed in PR 1" |
| N-2 | nit | AGENTS.md says "PHP 8.4 … for CI on `feature/ci4`". After L-b, `feature/ci4` CI also keeps legacy@8.3, and legacy@8.4 from PR 3 | Open. "the CI4 leg on `feature/ci4`" |
| N-3 | nit | `scripts/check-links.mjs` skips `AGENTS.md`, `docs/adr/` and `docs/plans/`, so the "0 of 197 broken" doesn't cover the ADR and plan links. QA checked them separately: 120 links, 0 broken | Open. Extend the file list in PR 2 or PR 17 |
| I-1 | info | `a477465` also edits the historical rev. 3 changelog entry: the L-a phrase replaces "PRs renumbered 1–18 (with 11a/11b)", and the L-2 labels apply there too | In scope of L-a and the labels. Accepted |
| I-2 | info | The PR description's checklist link `../CONTRIBUTING.md#commit-messages` is relative to the PR page | PR body only, no file impact |

No H or M findings.

## 4. Verdict

**PASS at `57ebe9b`.** No open H or M findings.

- The commits are `e4fc41c` → `a477465` → `57ebe9b` on `87850e3`. The three plan files in `e4fc41c` are
  byte-identical to the approved rev. 3. `a477465` holds only L-a, L-b, PM/QA L-2, the base-verified note and the
  changelog line, giving PLAN.md `1980d2e2…a20e`.
- The PR is docs-only: 8 files. `VERSION`, `CHANGELOG.md` and the workflows are untouched.
- The rule wording in AGENTS.md, README, both contributing guides and ADR-001 matches Stathis's 10:09 decisions.
- gitleaks finds nothing, the production hostname has 0 hits, the only Worker URL is the placeholder, CI is 10/10
  green, and links are 0 broken (197 by the repo script plus 120 by QA's).
- Open, non-blocking: L-1 (two manual pages still state the old rule without a qualifier), L-2 (ADR-001 doesn't
  restate no CDN / no JS build / offline), L-3 (plan §5 doc paths vs `docs/plans/` and `docs/adr/`), N-1 to N-3.
  L-1 and L-2 are one-line fixes that could go into this PR. The PM decides whether they go in now or are tracked.
