# QA: apotheke #224, run npm ci with --ignore-scripts in frontend.yml (N-1489)

**Gate:** light (diff read, CI and the cheap local checks; no e2e; targeted mutations). **Head:** `e9c127d2dbaf6ccd7ad1bad207720610d1fe88d3`, one commit, +4/-3 across 2 files (`.github/workflows/frontend.yml`, `tests/Unit/DeployWorkflowsTest.php`). 0 behind main (`05ffb6a`). **Reviewer:** Apotheke QA 2.
**Verdict: PASS at `e9c127d`.** No Highs or Mediums. N-1489 is fixed. N-1549, N-1550 and N-1551 are new and optional; they don't block.

## Checklist

- [x] **CI.** All 21 check runs are green at `e9c127d`, CodeQL and Conventional Commits included.
- [x] **Diff.** Both `npm ci` steps in `frontend.yml` (build at line 49, e2e runner at line 57) now run `npm ci --ignore-scripts --no-audit --no-fund`. `frontend.yml` was added to the `npmWorkflows` provider. There are no unrelated changes.
- [x] **Lifecycle scripts.** Neither `package.json` has install, preinstall or postinstall scripts. In the frontend lockfile, only `fsevents` has an install script, and it is optional and macOS-only. The root and `tests/e2e` lockfiles have none. Vite's native bundler comes from optional per-platform `@rolldown/binding-*` packages, and those need no postinstall.
- [x] **Playwright.** The browser install is still explicit: `npx playwright install --with-deps chromium` runs before the e2e step.
- [x] **Local run (Node 24.22.1).** `npm ci --ignore-scripts --no-audit --no-fund` and `npm run build` succeed in `frontend/`, and `check-dist.mjs` passes. `node --test` passes 49/49 on the frontend and deploy-scripts tests.
- [x] **PHP 8.4.24.** `DeployWorkflowsTest` passes 24 tests and 312 assertions. The provider test passes 4/4.
- [x] **Mutations.** 6 of 6 are caught. Removing `--ignore-scripts`, `--no-audit` or `--no-fund` on its own from either step fails the provider test, because it requires the whole flag string on every counted `npm ci`.
- [x] **Commit.** `ci(frontend): run npm ci with --ignore-scripts --no-audit --no-fund` is 67 characters and a valid Conventional Commit. No CHANGELOG edit is needed because `release.php` builds the entry from the squash subject.

## Findings

- **N-1489: fixed in `e9c127d`.**
- **N-1549: new, optional (hardening).** The provider covers 4 workflows. The `npm ci` calls in `ci.yml` (lines 168, 208, 253, 509), `snapshots.yml:39` and `scripts/update-snapshots.sh:30` still run without `--ignore-scripts`, and no test checks them. Their lockfiles have no install scripts, so the flag is safe to add there. Fix: add the flag and add those workflows to the provider.
- **N-1550: new, optional.** The test only counts `run: ... npm ci` written on one line. It misses `npm ci` inside a `run: |` block and misses `npm install` and `npm i`. Fix: count `\bnpm (ci|install|i)\b` anywhere in the file.
- **N-1551: new, optional.** `docs/ci.md:104` still describes the build as `npm ci && npm run build` and the e2e install as a root `npm ci`. Fix: mention `--ignore-scripts --no-audit --no-fund` there.

## Conditions

Squash-merge with the PR title as the subject. No secrets, tokens or credentials appear in the diff, the tests or this doc.
