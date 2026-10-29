# QA: PR #162, CI4 branch switch (docs, CI, tests) into feature/ci4

- **Head gated:** `499cb72f9c38e4b2d21342c9f9c9de6472ef1479`. 7 commits, base feature/ci4 `f1b4535` (the #161 merge), 0 behind, mergeable, same repo.
- The brief named `9203516` as the head. `499cb72` (docs only) was pushed during the gate and is covered below.
- **CI:** run 40959755014 passed 14/14 at `9203516`. Run 40961961345 at `499cb72` was still in progress at the end of the gate.
- **Verdict: PASS** at `499cb72`: no H/M findings; L/N items below.

## Checklist

- [x] **Scope.** All commits are plain. Only docs, CHANGELOG (Unreleased), `.github/workflows/ci.yml` and `snapshots.yml` (a comment), and 3 tests change. Nothing under app/, modules/, public/, bin/ or src/, and no change to the Dockerfile, compose, entrypoint, composer.json/lock or `.gitleaks.toml`.
- [x] **Workflows.**
  - The ci.yml push filter is now `[main]`. `pull_request` is unfiltered, so PRs into main and feature/ci4 still run CI.
  - Jobs, permissions and secrets are unchanged; snapshots.yml changes a comment only.
  - No workflow names feature/ci4.
  - No workflow publishes an image or deploys. release.yml is unchanged: it runs only after a green CI **push** run on main.
- [x] **3.0.0 auto-release (intended, documented in releasing.md).**
  - After the fast-forward, the first green CI push on main starts the release workflow.
  - A local `release.php plan` at the head (PHP 8.3, as in release.yml) gives `bump=major`, `next=3.0.0`. The breaking commits since v2.6.26 are `c49a865` and `57ebe9b`.
  - A scratch `apply` folds the Unreleased intro and bullets into `## [3.0.0]`. The 2.x sections are unchanged apart from the compare links.
  - main is an ancestor of feature/ci4, so the fast-forward is possible.
- [x] **Tests (SQLite).** Ci4BranchCiTest, the new N-965 restore-wrapper test and Ci4PagesGoldenTest pass. Ci4BranchCiTest has 1 incomplete case because it needs PG, so I also ran it against a scratch PG17 of my own: 12 OK.
- [x] **Mutations** (each must fail):
  - `feature/ci4` added to the push filter: fails.
  - a `feature/ci4` string in release.yml: fails.
  - the DataDirOwner pre-check removed from bin/restore.php: fails, with exit 0 instead of 1 (N-965 is now closed).
  - one byte changed in the edit-form heading: the edit-form golden fails.
- [x] **Edit-form golden on PG17** (my scratch DB): passes, with the same sha1 as SQLite.
- [x] **Parity record.** Run 40927086508 at `1b2e107` succeeded on attempt 2. Attempt 1 failed only on PG timing ("OVER BUDGET autofill: +26% > +20%"), with 0 differences in all areas. The HANDOFF and PLAN wording is accurate.
- [x] **Docs.**
  - L-963 leftovers are fixed: AGENTS.md, CONTRIBUTING.md and docs/index.md, and docs/deployment-and-operations.md now lists app/, modules/, vendor/ and writable/ in place of src/.
  - N-801 is fixed: Users & sign-in needs `users`.
  - N-860 is fixed: accounts commands go to `spark apotheke:accounts`. I checked that `list` creates `admin` from APP_PASSWORD when there are no accounts.
  - N-861 is fixed: the `pending` mechanism is described as unused since #151.
  - The `tests/run.sh`, `composer install` and PG env commands are correct. URLs are only github.com/thordin9 and `*.example`/LAN placeholders; there are no tokens or addresses.
- [x] **CHANGELOG.** Released sections are byte-identical to the feature/ci4 base and to main. The new text is one intro paragraph under Unreleased.
- [x] **gitleaks 8.30.1** on the PR range (`f1b4535..499cb72`): no leaks.

## Findings

**L-1060: the 2.x support line has no automation yet.** The docs now say 2.x security fixes ship as 2.6.x from the branch `old` (the former main). However:
- pushes to `old` do not run CI: the ci.yml on `old` filters pushes to main;
- release.yml runs only for main, and `workflow_run` is read from the default branch.

HANDOFF item 12 tracks this as a follow-up. **Fix:** before the first 2.6.x fix, add a CI push trigger and a release path for `old`, or document the by-hand release steps.

**N-1061: no push CI on feature/ci4 in the window.** Between this merge and the fast-forward of main, merges into feature/ci4 get PR CI only. Keep that window short. Pinning release.yml's `workflow_run.branches: [main]` in Ci4BranchCiTest would also be cheap.

## Security

- No secrets, hostnames or tokens appear in the diff; `.gitleaks.toml` is unchanged.
- No workflow gains permissions or secrets. Releases still require a green CI push run on main.
- N-965 (the restore wrapper pre-check) is now pinned by a test.
- **Testing setup:** private scratch dirs and a throwaway PG role/DB.
