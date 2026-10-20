# Apotheke PR #31: Feature 12 PR 2, `ci(ci4): branch wiring, PHP 8.4`, QA

- PR: thordin9/apotheke#31 (draft), "ci(ci4): branch wiring, PHP 8.4", branch `cursor/ci4-branch-wiring-76ed` →
  `feature/ci4`
- Head tested: **`5df232c4e5749de283ee50c568c942dd1b59d404`** (full review at `e9ebe501345a9bdb9dfcf1997cddedecb30ccab3`,
  then a delta check of the one PLAN.md commit on top)
- Base: `feature/ci4` = **`8b87609c1b74a1f778fbcb0763f29a8508f2c892`** (merge of #29, pinned to `21b1e68`)
- Commits: `881acf2` fix: check links in AGENTS.md, ADRs and plans → `8ef2274` ci(ci4): wire feature/ci4 and the PHP 8.4
  matrix → `e9ebe50` docs: use CI4-leg wording and one offline rule → `5df232c` docs(plan): note early legacy 8.4 runs
  in PR 2
- Date: 2026-10-20 (Europe/Athens)
- Scope: CI wiring review. Read-only: codeload tarballs of base, `e9ebe50` and `5df232c` (each confirmed with
  `git get-tar-commit-id`), plus the GitHub API (PR, commits, compare, check runs, job logs). Local runs only in a
  scratch copy. No comment, review, push or merge was made. No Neurons used.

## 1. Test plan

### 1.1 Environment

- Tarballs unpacked side by side and compared with `diff -r`, `diff -u` and `sha256sum`.
- actionlint **1.7.7**, shellcheck, PyYAML `safe_load`, PHP **8.4.26** CLI, PHPUnit from `composer install` in the
  scratch copy, Node 20, gitleaks (`detect --no-git`).

### 1.2 Acceptance checks

1. Diff `8b87609` → head is limited to plan row 2 plus PR 1's N-3, I-3 and I-4. Every file listed.
2. Workflows: actionlint on all three; triggers and permissions parsed with `safe_load`. `ci.yml`: push on `main` and
   `feature/ci4`, `pull_request` unchanged, no `pull_request_target` or other fork-unsafe trigger, `contents: read`
   only. Matrix expands to the expected cells, legacy@8.3 present, `fail-fast: false`. `release.yml` byte-identical
   and unable to fire from `feature/ci4`.
3. `scripts/ci4-leg.sh`: exit codes and the `GITHUB_OUTPUT` line, with and without `ci4/public/index.php` (created in
   a scratch copy), with and without the `APOTHEKE_APP` switch in `tests/lib.php`.
4. `scripts/ci4-arch.php`: the empty configs pass; a parse error, any other edit, a missing file and a missing tool
   fail; a filled config runs the tool and passes its exit code through.
5. CI: all 16 check runs `success` on the head; the ci4 cells log the skip; the legacy cells run PHPUnit with real
   test counts on the right PHP version.
6. PR 1 N-3: `node scripts/check-links.mjs` over the new scope, plus negative tests. PR 1 I-3 and I-4 fixed. Wording of
   PLAN.md, ADR-001, AGENTS.md and the CI docs consistent with `ci.yml`.
7. Secrets and hosts: gitleaks on the added lines and on the head tree; grep the added lines for IPs, emails, URLs,
   hostnames, tokens and account ids. The only Worker URL allowed is `https://autofill.example`.
8. The new tests (`Ci4BranchCiTest`, `LinkCheckTest`) run locally.
9. PM decision on legacy@8.4 (plan L-b): PR description and PLAN.md rows 2 and 3 match what `ci.yml` does.

## 2. Results

### 2.1 Scope (`8b87609` → `5df232c`): 20 files, +522 / −26 at `e9ebe50`, plus PLAN.md

| File | Change |
|---|---|
| `.github/workflows/ci.yml` | push adds `feature/ci4`; matrix `app × php × db` + legacy@8.3 include; `deptrac` and `DI rule` jobs; `APOTHEKE_APP` env; suite steps gated on `steps.suite.outputs.run`; coverage only on legacy@8.3 SQLite |
| `scripts/ci4-leg.sh` (new) | decides `run=true/false` per cell |
| `scripts/ci4-arch.php` (new) | empty-config pass / fail-closed runner for deptrac and the DI rule |
| `deptrac.yaml`, `phpstan-di.neon` (new) | empty configs |
| `scripts/install-actionlint.sh` (new), `scripts/lint.sh` | actionlint 1.7.7, version and sha256 pinned, run by lint |
| `scripts/check-links.mjs` | PR 1 N-3: adds `AGENTS.md`, `docs/adr/`, `docs/plans/` (recursive), `--list` |
| `tests/Unit/Ci4BranchCiTest.php`, `tests/Unit/LinkCheckTest.php` (new) | 12 + 1 tests |
| `AGENTS.md`, `docs/adr/001-codeigniter4.md` | PR 1 I-4 and I-3 |
| `README.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, `docs/ci.md`, `docs/contributing.md`, `docs/releasing.md`, `docs/testing.md` | docs for the above; `[Unreleased]` Maintenance entry |
| `docs/plans/feature12-ci4/PLAN.md` (`5df232c` only) | rows 2 and 3, §5.2, R13 and the plan changelog for the PM decision |

No app code (`src/`, `public/`, `bin/`, migrations), `VERSION`, `Dockerfile`, `release.yml` or `snapshots.yml`
change. **PASS**

### 2.2 Workflows

- **actionlint 1.7.7** on `ci.yml`, `release.yml`, `snapshots.yml`: 0 issues. shellcheck on the three changed shell
  scripts: clean. **PASS**
- **Triggers (`safe_load`):** `ci.yml` `on: {pull_request: null, push: {branches: [main, feature/ci4]}}`,
  `permissions: {contents: read}`, no job-level permissions, `concurrency: ci-${{ github.ref }}`. No
  `pull_request_target`, `workflow_run` or `secrets.*` in `ci.yml`. `snapshots.yml` unchanged. **PASS**
- **Matrix:** 6 cells, `fail-fast: false`: legacy@8.4 and ci4@8.4 on sqlite and pgsql, and legacy@8.3 on sqlite and
  pgsql (the two `include` entries add new cells because 8.3 is not in the base `php` list). With lint, deptrac, DI
  rule, mobile, 3 E2E shards, Docker, Conventional Commits and Secret scan that is the expected 16 jobs. **PASS**
- **`release.yml`:** byte-identical to base (`eb8d3bdb…5dd3`). Trigger `workflow_run` on CI, `types: [completed]`,
  `branches: [main]`; job `if` needs `workflow_dispatch` or a successful `push` run; its only checkout is `ref: main`.
  A green `feature/ci4` run cannot start it, and `workflow_run` is read from the default branch. **PASS**
- **Lint, mobile, E2E, Docker** stay on PHP 8.3 (`php-version: '8.3'`, `e2e-container-setup.sh` installs
  `php8.3-*`, `FROM php:8.3-fpm-alpine`). That matches row 3 at `5df232c`. **PASS**

### 2.3 `scripts/ci4-leg.sh` (scratch copy)

| Case | Exit | stdout | Result |
|---|---|---|---|
| `legacy` | 0 | `run=true` | runs |
| `ci4`, no `ci4/public/index.php` (as in this PR) | 0 | `run=false`, reason on stderr | skip, logged |
| `ci4`, index.php created, `tests/lib.php` without `APOTHEKE_APP` | **1** | empty | job fails |
| `ci4`, index.php, `tests/lib.php` missing | **1** | empty | job fails |
| `ci4`, index.php, `APOTHEKE_APP` in `tests/lib.php` | 0 | `run=true` | flips to run |
| no argument | 1 | usage | fails |
| `bogus` | 0 | `run=true` | treated as legacy (N-1) |

Step emulation with `bash -e` and `>> "$GITHUB_OUTPUT"`: the skip case writes exactly `run=false`; the refuse case
exits 1 and writes nothing, so the step fails. The explanation goes to stderr, so the output file only ever holds
the one `key=value` line. With index.php present the skip can no longer hide a failure: the leg either runs or fails.
**PASS** (L-1, N-1, N-2 below)

### 2.4 `scripts/ci4-arch.php` (scratch copy)

| Case | deptrac | DI rule |
|---|---|---|
| committed empty config | exit 0, "empty config … Pass" | exit 0, "empty PHPStan config … Pass" |
| YAML / NEON parse error (unclosed `[`) | exit 1, tool not on PATH | exit 1, tool not on PATH |
| inline comment / `level: 0` | exit 1 | exit 1 |
| full-line comment, CRLF line endings | exit 0 (stripped) | n/a |
| config missing | n/a | exit 1, "missing config" |
| filled config, no tool | exit 1 | n/a |
| filled config, stub tool exiting 3 | exit 3 (passed through) | n/a |
| no argument / `foo` | exit 2, usage | exit 2, usage |

The empty pass is an exact shape match after dropping blank and comment lines, not a parse. Anything else, including
a broken file, fails closed. It is not a no-op that always passes. **PASS** (N-3 below)

### 2.5 CI

- **`e9ebe50`, run 39395341171 (`pull_request`, attempt 1): 16/16 `success`.** Job logs:
  - `Tests (ci4 @ PHP 8.4, sqlite|pgsql)`: PHP 8.4.26 installed; log shows "ci4/public/index.php is not in this
    tree." and "The CI4 leg skips PHPUnit until the skeleton exists (Feature 12 PR 4)."; 9 suite steps skipped; 24 s
    and 37 s.
  - `legacy @ PHP 8.4`: Runtime PHP 8.4.26, **OK (281 tests, 1905 assertions)** sqlite, **OK (281 tests, 1906
    assertions)** pgsql.
  - `legacy @ PHP 8.3`: Runtime PHP 8.3.35, **OK (281 tests, 1905 assertions)** sqlite (plus the coverage run, 280
    tests), **OK (281 tests, 1906 assertions)** pgsql.
  - `deptrac` and `DI rule` print the empty-config pass. `Lint` installs actionlint (`actionlint_1.7.7_linux_amd64.tar.gz:
    OK`), runs it, and the link checker reports 220 links in 21 files, 0 broken.
- **`5df232c`:** run 39397272182 (`pull_request`): **16/16 `success`** (last job done at 12:09 EEST). The delta from
  `e9ebe50` is PLAN.md only and `ci.yml` is byte-identical, so the job set and the PHPUnit cells are unchanged. **PASS**

### 2.6 Links, PR 1 notes and wording

- **PR 1 N-3 fixed.** `check-links.mjs --list` now includes `AGENTS.md`, `docs/adr/001-codeigniter4.md` and the three
  `docs/plans/feature12-ci4/*.md` files: **220 links in 21 files, 0 broken** at `e9ebe50` and at `5df232c` (base: 199
  in 16). Negative tests: a missing file and a missing anchor appended to `AGENTS.md`, and a bad relative path in
  PLAN.md, are each reported and exit 1. **PASS**
- **PR 1 I-3 fixed:** ADR-001:24 now reads "The CI4 image and the CI4 leg on `feature/ci4` run PHP 8.4 (legacy@8.3 also
  runs on that branch)", in line with AGENTS.md:19. **PR 1 I-4 fixed:** the offline / no-CDN / no-JS-build line is gone from
  AGENTS.md:3 and kept once at AGENTS.md:21. **PASS**
- **PLAN.md at `5df232c`** (`272113e6…6cdc`, 48486 B; the only file changed since `e9ebe50`, one commit, confirmed by
  the compare API and `diff -r`): row 2 lists the CI4 leg, legacy@8.3 and legacy PHPUnit on 8.4 on both databases, with
  the acceptance criterion "legacy PHPUnit on PHP 8.4 is green on both databases". Row 3 now holds only the remaining
  8.4 suites (lint, mobile, Playwright, Docker), the `fix(php84)` commits, the parity recorder and the allowlist. §5.2,
  R13 and the plan changelog say the same. That matches `ci.yml` cell for cell. No "legacy@8.4 exists from PR 3",
  "move to PR 3" or "runs only the CI4 leg" text remains. **PASS** (N-4 below)
- **New tests (local, PHP 8.4.26):** `Ci4BranchCiTest` OK (12 tests, 41 assertions); `LinkCheckTest` OK (1 test,
  7 assertions). **PASS**

### 2.7 Secrets and hosts

gitleaks on the 522 added lines and on the `e9ebe50` tree: no leaks. The added lines hold no IP, email, URL (other than
the pinned actionlint release), hostname, token or account id. No `workers.dev` and no Worker URL other than
`https://autofill.example` anywhere in the tree. The `5df232c` delta is plan prose only, with no host or token.
**PASS**

### 2.8 PM decision: legacy PHPUnit on 8.4 in PR 2

The approved plan (L-b) put only the CI4 leg and legacy@8.3 in PR 2. `e9ebe50` also ran the legacy PHPUnit suite on
PHP 8.4. The PM accepted that early on 2026-10-20. The PR description has a section "Deviation from the plan
(accepted by the PM)", and `5df232c` updates PLAN.md to match. PR 3 is left with lint, headless mobile checks,
Playwright and the Docker build on 8.4, the `fix(php84)` commits, the parity recorder and the allowlist. Recorded as
I-5, not as an open finding.

## 3. Findings

| Id | Sev | Finding | Suggested fix |
|---|---|---|---|
| L-1 | Low | Once `ci4/` exists, the guard in `ci4-leg.sh` is `grep -q 'APOTHEKE_APP' tests/lib.php`. A comment or an unused string satisfies it, and the legacy suite would then run under the ci4 label. Harmless now (no `ci4/`), but it is the only thing between a green ci4 cell and a false parity claim. | **By PR 4:** make the harness prove it, e.g. the ci4 cell asserts that the server it started answers as CI4 (a header or `/health` field), or `tests/lib.php` exposes the selected app and a test checks it equals `ci4`. |
| N-1 | Note | `ci4-leg.sh` treats any value other than `ci4` as legacy (`bogus` → `run=true`). The matrix is fixed, so no impact today. | Accept only `legacy` and `ci4`; exit 2 otherwise. |
| N-2 | Note | The skip keys on one path. After PR 4 lands, deleting or moving `ci4/public/index.php` turns the ci4 cells green again without running anything. | In PR 4, drop the `run=false` branch (or make a missing index.php a failure once `ci4/` exists). |
| N-3 | Note | `ci4-arch.php` looks for `deptrac` / `phpstan` on `PATH`, and both jobs use `tools: none` with no `composer install`. A tool installed through Composer lands in `vendor/bin`. The job fails closed, which is correct, but PR 4 must add an install step or call `vendor/bin`. | Note for PR 4. |
| N-4 | Note | Wording after the PM decision: ADR-001:24 and AGENTS.md:19 still say only "legacy@8.3 also runs on that branch", not legacy PHPUnit on 8.4. Row 3 says "Docker build", where the old row said "Docker smoke" (`ci.yml` job is "Docker build + smoke test"). The plan changelog adds a PM entry and also rewrites the L-b and rev. 3 entries in place, so the history reads as if L-b had always said this. | Fold into PR 3: "legacy@8.3 and legacy@8.4 PHPUnit also run"; "Docker build + smoke"; leave the L-b text as it was and keep the new PM entry. |
| N-5 | Note | The link checker still skips `docs/qa/` and `docs/superpowers/`. QA ran it with both added: 221 links in 28 files, 0 broken. | Optional: walk all of `docs/` recursively. |
| N-6 | Note | The hand-written `[Unreleased]` Maintenance entry sits where `release.php` inserts the next main release section, so the main → `feature/ci4` merge after v2.5.1 will likely conflict in `CHANGELOG.md`. | Resolve by keeping both; expect it on the first main merge. |
| N-7 | Note | `881acf2` is typed `fix:` for a CI/lint change. No effect on `feature/ci4` (no release), but it will show as a Fixed item when the branch reaches `main`. | Use `ci:` for later CI-only commits. |
| I-5 | Info | **Accepted deviation, decided by the PM (2026-10-20):** legacy PHPUnit on PHP 8.4 (SQLite and PostgreSQL) runs from PR 2, not PR 3 as L-b planned. Noted in the PR description; PLAN.md updated in `5df232c`. | None. |
| I-6 | Info | Pre-existing on `main`, not this PR: `[Unreleased]` still carries hand-written items that `2.5.0` already lists. `release.php` leaves hand-written Unreleased text in place by design. | Main-side cleanup with the next release. |

PR 1 N-3, I-3 and I-4 are closed. No H or M findings.

### Concerns for later CI4 PRs

- **PR 3:** move lint, mobile, Playwright and the Docker smoke test to 8.4 while keeping 8.3 cells; `fix(php84)` commits
  stay 8.3-compatible; N-4 wording.
- **PR 4:** L-1, N-2, N-3. The first PR where the ci4 cells run PHPUnit must show non-zero ci4 test counts in the log,
  and `APOTHEKE_APP=ci4` must start the CI4 server.
- **Every PR:** the ci4 cells are green by design until PR 4, so a green check run alone is not evidence for the CI4
  leg. QA will read the job logs.

## 4. Verdict

**PASS at `5df232c4e5749de283ee50c568c942dd1b59d404`.** The diff from `feature/ci4` (`8b87609`) is limited to plan
row 2, PR 1's N-3, I-3 and I-4, and the PLAN.md update for the PM's decision. `ci.yml` runs on pushes to `feature/ci4`
with `contents: read` and no fork-unsafe trigger; the matrix gives the six expected cells with `fail-fast: false`;
`release.yml` is unchanged and cannot fire from the branch. The ci4 skip is logged and cannot mask a failure once
`ci4/public/index.php` exists (the leg then runs or fails), and the empty deptrac and DI configs fail closed on any
edit or parse error. CI is 16/16 on `e9ebe50` and on `5df232c`, links are 220/220, and gitleaks is clean. The early
legacy@8.4 PHPUnit runs are an accepted deviation (I-5). L-1, N-2 and N-3 are due by PR 4; the rest are notes.
