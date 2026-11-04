# QA: apotheke #225, skip install scripts in every npm ci and widen the test

**Gate:** light (diff read, CI and the cheap local checks; targeted mutations). **Head:** `b5a7ff3dd0defb026bdcb0824d55bda9bd7ac967`, one commit, +19/-11 across 5 files: `ci.yml`, `snapshots.yml`, `scripts/update-snapshots.sh`, `docs/ci.md` and `tests/Unit/DeployWorkflowsTest.php`. 0 behind main (`9931286`), mergeable and clean. **Reviewer:** Apotheke QA 2.
**Verdict: PASS at `b5a7ff3`.** No Highs or Mediums. N-1549, N-1550 and N-1551 are fixed. N-1554 and N-1555 are new and optional; they don't block.

## Checklist

- [x] **CI.** All 20 check runs are green (CI and CodeQL).
- [x] **Coverage.** A repo-wide search finds every `npm ci`, `npm install` and `npm i` in 7 workflows and scripts, and each one carries `--ignore-scripts --no-audit --no-fund`: `ci.yml` 168, 208, 253 and 509, `snapshots.yml:39`, `update-snapshots.sh:30`, plus the 4 files covered earlier. All of them are in the provider. `release.yml` has no npm call, and no `package.json` script runs one.
- [x] **Lifecycle scripts.** The root and `tests/e2e` lockfiles have no install scripts. The frontend lockfile has only `fsevents`, which is optional and macOS-only. The edge lockfile (esbuild, workerd) was already installed with `--ignore-scripts` before this PR.
- [x] **Browsers.** Playwright browsers still come from an explicit source: the pinned `mcr.microsoft.com/playwright:v1.64.1-noble` image for snapshots, visual e2e and `update-snapshots.sh`, or `npx playwright install --with-deps chromium` for docker-smoke. `puppeteer-core` uses the runner's Chrome. The green CI run exercises these jobs with the new flags.
- [x] **Docs.** `docs/ci.md` lines 104 and 106 now give the full flags.
- [x] **PHP 8.4.24.** `DeployWorkflowsTest` passes 27 tests and 371 assertions.
- [x] **Mutations.** 13 of 13 are caught:
  - a single flag dropped at `ci.yml` 168, 208 and 509, `snapshots.yml` and `update-snapshots.sh`, including `--no-fund` alone;
  - an indented `npm install` inside a `run: |` block;
  - `npm install` with all three flags (refused because it isn't `ci`);
  - `npm ci` in a step after a `# comment` line;
  - a flow-style step `- { name: …, run: npm ci }`;
  - a `#` inside a run string;
  - `npm ci` in a folded `>` block;
  - `--ignore-scripts=false`;
  - `npm install-ci-test`.
- [x] **No false positives.** "npm instead", an `npm-install` URL and `npm ci` used only in a step name all leave the test passing. Stripping `name:` and comment lines can't hide a real `run:` line.
- [x] **Commit.** `ci(workflows): skip install scripts in every npm ci and widen the test` is 70 characters and a valid Conventional Commit.

## Findings

- **N-1549, N-1550 and N-1551: fixed in `b5a7ff3`.**
- **N-1554: new, optional.** The matcher only sees `npm ci`, `npm install` and `npm i` with one space after `npm`. `npm --prefix x ci`, `npm clean-install`, `npm add` and `npm  ci` (two spaces) pass unflagged. None appear in the repo today. Fix: match `\bnpm\s+(?:--?\S+\s+)*(ci|clean-install|install|i|add)\b`.
- **N-1555: new, optional.** `release.yml` isn't scanned, because the test requires at least one call per file. Fix: scan every `.github/workflows/*.yml` and require the flags on each call found, with the at-least-one check kept for the known npm workflows.

## Conditions

Squash-merge with the PR title as the subject. No secrets, tokens or credentials appear in the diff, the tests or this doc.
