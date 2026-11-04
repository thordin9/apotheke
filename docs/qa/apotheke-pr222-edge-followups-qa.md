# QA: apotheke #222, edge storage merge and flip checklist follow-ups

**Gate:** light (diff read, CI and the cheap local checks; targeted mutations and probes). **Head:** `2c6ec4bf50a742825c7b10cd075ae2097ca2ecf9`, seven commits (`011bfcf`, `b86c9fc`, `385518a`, `e119f2b`, `f360d92`, `7e15a3f`, `2c6ec4b`). Four files: `CHANGELOG.md`, `docs/deployment-and-operations.md`, `scripts/edge-storage-config.mjs` and `scripts/deploy-scripts.test.mjs`. 0 behind main (`05ffb6a`). **Reviewer:** Apotheke QA 2.
**Verdict: PASS at `2c6ec4b`.** No Highs or Mediums. N-1546, N-1547, N-1548, L-1552 and N-1553 are all fixed. Full light gate at `f360d92`; `7e15a3f` and `2c6ec4b` are docs-only and were diff-read.

## Checklist

- [x] **CI.** At `f360d92`, 22 check runs are green and 2 deploys are skipped, as usual on a PR. Lint (PHP 8.4), CodeQL and Conventional Commits are included.
- [x] **Scope.** The diff touches only the four files above. The N-1544 and N-1545 tests are unchanged; the new test sits between them.
- [x] **Docs.** Step 5 now says that a deploy carrying a new Durable Object migration runs with the one-time Workers Scripts Edit token or is checked on a preview first. Step 2 uses the *Group > Permission* style. *D1 Edit*, *Workers R2 Storage Edit* and *Workers Scripts Edit* are all in Cloudflare's permissions list, and Cloudflare documents the per-Worker *Editor* role. No account IDs, tokens or internal hosts appear.
- [x] **N-1547 probes.** These are refused and leave the file unchanged:
  - an empty `new_sqlite_classes`;
  - a binding whose class no fragment migration creates;
  - a binding whose class is created only by the base migrations;
  - a fragment that re-creates a base class (refused as "already created").

  The real `storage.jsonc` still merges.
- [x] **Mutations.** 3 of 3 are caught by the unit test: removing the empty-list check, removing the binding and class check, and moving the binding check before the "already created" check.
- [x] **Local run (Node 24.22.1, PHP 8.4.24).** `deploy-scripts.test.mjs` passes 25/25. `DeployWorkflowsTest` passes 23 tests and 310 assertions. `LinkCheckTest` passes. `release.php check-links` passes.
- [x] **Commits.** All seven subjects are Conventional Commits of 72 characters or fewer.
- [x] **Delta `f360d92..2c6ec4b`.** `CHANGELOG.md`: the #222 bullet moves from `### Changed` to `### Maintenance`, with the text unchanged. `docs/deployment-and-operations.md`: steps 2 and 5 say *Specified Workers > Editor*. Nothing else changed.

## Findings

- **N-1546, N-1547 and N-1548: fixed** in `b86c9fc`, `011bfcf` and `385518a`.
- **L-1552: fixed** in `7e15a3f`. The CHANGELOG bullet is now under `### Maintenance`, where `release.php` files a `ci` subject, so #222 is listed once.
- **N-1553: fixed** in `2c6ec4b`. The token scope uses Cloudflare's API-token name, *Specified Workers > Editor*.

## Conditions

Squash-merge with the PR title as the subject, once CI on the final head is green. No secrets, tokens or credentials appear in the diff, the tests or this doc.
