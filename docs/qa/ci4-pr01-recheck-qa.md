# QA recheck: CI4 PR 1 (apotheke #29), reviewer-fix delta

- PR: #29 `docs!: allow CodeIgniter 4 (AGENTS.md, README)`, draft, base `feature/ci4` (`87850e3` = v2.5.0)
- Previous QA PASS: `57ebe9b31e4f28d7c928e4ea5fbc1dc73ee4a8be` ([ci4-pr01-qa.md](ci4-pr01-qa.md))
- Rechecked head: `5733bf92c283fba2fb59a8e3aacd5ad8f4ec502d`
- Date: 2026-10-20 (EEST). Docs-only delta, no live calls.

## 1. Plan

1. Confirm the full head sha from the codeload tarball (`git get-tar-commit-id`) and list the commits after `57ebe9b`.
2. `docs/qa/ci4-pr01-qa.md` at the head: sha256 `b68693e8…a9a3d2`, 14406 bytes, added in a commit of its own.
3. Tree diff `57ebe9b` → head: docs only, no code, workflow or test changes.
4. Check each finding of the original report (L-1, L-2, L-3, N-1, N-2; N-3 deferred). Appendices A and B unchanged.
5. Links in the edited docs (files and `#anchors`), plus `node scripts/check-links.mjs`.
6. CI run 39389017177; gitleaks and a hostname/IP/email/token grep on the delta.

## 2. Results

### Commits after `57ebe9b`

| Commit | Files | Content |
|---|---|---|
| `191ef36` docs(qa): add CI4 PR 1 QA report | `docs/qa/ci4-pr01-qa.md` (new) only | QA report |
| `cb07198` docs: qualify legacy docs and CI4 constraints | `AGENTS.md`, `README.md`, `docs/adr/001-codeigniter4.md`, `docs/architecture.md`, `docs/index.md` | L-1, L-2, N-2 |
| `5733bf9` docs: fix CI4 plan status and doc paths | `docs/plans/feature12-ci4/PLAN.md` only | L-3, N-1 |

### Checks

- **QA report:** `docs/qa/ci4-pr01-qa.md` at `5733bf9` is sha256 `b68693e8f504a4914d9facdfad260d6b8b9b44f2a983cf066ad69964a1a9a3d2`,
  14406 bytes, identical to QA's copy. It was added alone in `191ef36`. **PASS**
- **Docs only:** `57ebe9b` → `5733bf9` changes 6 Markdown files and adds 1. No PHP, JS, CSS, workflow, test, `VERSION`,
  `CHANGELOG.md` or Composer change. **PASS**
- **Appendices:** `APPENDIX-A-inventory.md` (`9b6d33bb…d022`) and `APPENDIX-B-schema.sql.md` (`36d13904…3f05`) are
  unchanged. `PLAN.md` changes in two lines only (3 and 440). It is now `c5dd06a1…a52f` (47658 bytes) and
  replaces `1980d2e2…a20e`. **PASS**
- **Links:** QA's checker over the 8 rule-change files (`AGENTS.md`, `README.md`, `CONTRIBUTING.md`,
  `docs/contributing.md`, `docs/index.md`, `docs/architecture.md`, ADR-001, `PLAN.md`) found **142 links, 0 broken**,
  including the new `adr/001-codeigniter4.md` links in `docs/index.md` and `docs/architecture.md`.
  `node scripts/check-links.mjs` found 199 links in 16 files, 0 broken. **PASS**
- **CI:** run 39389017177 (`pull_request`, head `5733bf9`, attempt 1) succeeded, **10/10 jobs**: Lint, Tests PHP 8.3
  sqlite and pgsql, Headless mobile checks, Visual E2E 1/3, 2/3 and 3/3, Docker build + smoke test,
  Conventional Commits, Secret scan. **PASS**
- **Secrets and hosts:** gitleaks on the added lines and on the head tree found no leaks. The added lines contain no
  IPs, URLs, emails, tokens or account ids. The production Worker hostname appears nowhere in the head tree, and
  there is no `workers.dev`. **PASS**

### Findings

| Id | Original finding | Status at `5733bf9` | Evidence |
|---|---|---|---|
| L-1 | `docs/index.md` and `docs/architecture.md` state the no-framework rule without saying it is legacy-only | **Fixed** | `docs/index.md:11-13` now opens "The legacy app (`main`, 2.5.x) is plain PHP 8.2+ …" and links ADR-001. `docs/architecture.md:3-5` now opens "The legacy app is deliberately small …" and adds "The 3.0 port adopts CodeIgniter 4; see ADR-001" |
| L-2 | ADR-001 and the README don't restate the constraints that stay. In AGENTS.md, "offline on a LAN" reads as legacy-only | **Fixed** | ADR-001 decision 7 "Unchanged constraints": no CDN, no JS build step, offline on a LAN, runtime Composer only CI4 and its pinned deps. README:219 has the same line. AGENTS.md:3 and :21 now say both the legacy app and CI4 |
| L-3 | The plan's docs-plan table names `docs/ci4/PLAN.md` and `docs/ci4/adr/*` | **Fixed** | PLAN.md:440 now names `docs/plans/feature12-ci4/PLAN.md` and `docs/adr/*`, which match the real files. No `docs/ci4` path remains outside the original QA report. **Correction:** the original report said §5, but the table is in **§6 "Docs plan"** (PLAN.md:435) |
| N-1 | PLAN.md status line still reads "local draft" | **Fixed** | PLAN.md:3 reads "*Status: approved rev. 3 (PM + QA), committed in PR 1.*" |
| N-2 | AGENTS.md's PHP 8.4 line reads as all CI on `feature/ci4` | **Fixed** | AGENTS.md:19: "The CI4 leg on `feature/ci4` runs PHP 8.4 (legacy@8.3 also runs on that branch)" |
| N-3 | `check-links.mjs` skips `AGENTS.md`, `docs/adr/`, `docs/plans/` | **Deferred to PR 2** (agreed) | Covered here by QA's own checker |

New, info only (non-blocking; fold into PR 2 with N-3 if convenient):

- **I-3:** ADR-001:24 (decision 6) still says "The CI4 image and CI on `feature/ci4` run PHP 8.4". This is the wording
  N-2 fixed in AGENTS.md. N-2 also doesn't mention the legacy@8.4 leg that PR 3 adds; that can be stated when PR 3
  adds it.
- **I-4:** AGENTS.md:3 and :21 now say the same constraint twice, in the intro and the hard-constraints list.
  Harmless.

No H, M or L findings remain open.

## 3. Verdict

**PASS at `5733bf92c283fba2fb59a8e3aacd5ad8f4ec502d`.** The delta from `57ebe9b` is docs only and contains exactly
the QA report (byte-identical, its own commit) and the fixes for L-1, L-2, L-3, N-1 and N-2. N-3 is deferred to
PR 2. CI is 10/10, links resolve, and there are no secrets or hostnames.
