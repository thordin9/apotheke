# QA: apotheke #300, apply control-plane D1 migrations before the edge deploy

- PR: https://github.com/thordin9/apotheke/pull/300 (DevOps), base `main`. E1 of the operator console: the deploy job applies `edge/migrations/control/` to D1 `apotheke-api` before `wrangler deploy`.
- Reviewed head: `3dbcf3f63a54dfa0b9e259e106d6d868245bb28e` (merge: sync main into ci/d1-control-migrations). Parents `b451eb60622a4befc35b409dba7f4245fb3dc093` (previous PASS) and main `960ab746604be7ec21062b541f25dd30c882c5c2` (chore(release): v3.42.1 / #308, 08:14:50 Europe/Athens, UTC+2). 0 behind, 14 ahead (the previous 13 plus this merge), mergeable_state clean, open. This is a merge-only read. The earlier doc at `b451eb60` (sha256 `940eae04…`) is void.
- The PR title (60 chars) is unchanged and suits the squash. The merge subject is exempt.
- Gate: merge-only read (diff compare, CHANGELOG / HANDOFF, CI, D1 Edit hold). No suites, mutations or Docker. Reviewer: Apotheke QA 2

## Checklist

- [x] CI on `3dbcf3f6` is green. 25 check runs: 23 success, 2 skipped (Deploy apotheke-api and Deploy getapotheke-app, as intended on PRs). mergeable_state clean. Runs: CI 43377703108 (includes Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql); completed 08:30:50 Europe/Athens, UTC+2), Deploy edge 43377703202 (Check and dry run, job 131727957431), Deploy frontend 43377703203, and PR #300 43377696650 (CodeQL 131728137476).
- [x] Merge-only read of `3dbcf3f6`. A plain merge of main. Per-file +/- of `960ab746...3dbcf3f6` equals `4786e44d...b451eb60` (9 files, +172/−39, same counts). Unique added/removed lines are identical on every file. Three files also touched on main (`docs/adr/002-edge-storage.md`, `docs/deployment-and-operations.md`, `scripts/deploy-scripts.test.mjs`) differ only in hunk line numbers and surrounding context. `.github/workflows/deploy-edge.yml`, `edge/storage.jsonc`, `edge/migrations/control/.gitkeep` and `tests/Unit/DeployWorkflowsTest.php` have byte-identical patches. Nothing else drifted.
  - `CHANGELOG.md`: the #300 bullet stays under `[Unreleased]` / `### Added`, with the full PR URL, and sits above `## [3.42.1]`. The header (478 bytes) and everything from `## [3.42.1]` down (190897 bytes) are byte-identical to main `960ab746`. `#300` appears only in `[Unreleased]`, never in a released section.
  - `docs/HANDOFF.md`: head equals main plus the same #300 open-PR bullet as at `b451eb60`. Main's wording on the #308 restore and worker mode is kept (`Backup restore on d1 is in v3.41.0; #308 fixes its confirm…` / FrankenPHP worker mode on from #297). The unique bullet still says the PR is awaiting *Account > D1 > Edit*; that sentence is now stale given the hold is released, and it drops when the open-PR bullet is removed after merge. No new finding.
- [x] D1 Edit hold released. Reported by DevOps: at 08:15 Europe/Athens the `deploy-edge` environment's `CLOUDFLARE_API_TOKEN` was switched to a new token with *Account > D1 > Edit*, which #300's migrations step needs. Observed: that environment secret's `updated_at` is 08:15:15 Europe/Athens (UTC+2). Name and timestamp only; the permission itself is not verifiable here. The earlier merge precondition is cleared.

## Findings

- **L-2060: closed.** Both ids are now `deploy-edge` environment secrets, which are masked in step headers as well. The dry-run log contains neither id.
- **L-2034: superseded** by L-2060's fix.
- **L-2083:** still open, unchanged. `deploy-frontend.yml` still reads `vars.CLOUDFLARE_ACCOUNT_ID` (the `deploy-site` environment, as the docs state). Hardening: make it a `deploy-site` environment secret too, in a follow-up. Non-blocking; the file is outside this PR's scope.
- **N-2084:** still open, unchanged. Two variables are now unused by the PR's workflow: `CLOUDFLARE_ACCOUNT_ID` in the `deploy-edge` environment and the repo variable `APOTHEKE_D1_DATABASE_ID`. Remove them after the merge.
- **N-2085:** still open, unchanged. No test pins `secrets.CLOUDFLARE_ACCOUNT_ID` in the deploy step (`deploy-edge.yml:241`). Pin it as the apply step is pinned (`DeployWorkflowsTest.php:106`).

0 High, 0 Medium, 1 Low, 2 Nit open.

## Verdict

**PASS** at `3dbcf3f6`, 0 behind main `960ab746` (v3.42.1 / #308). No High or Medium. The D1 Edit hold is released (reported by DevOps; `updated_at` observed). L-2083 is recommended soon; it affects the frontend workflow, not this PR's. The earlier version of this doc (sha256 `940eae04…` at `b451eb60`) is void.

Squash-merge with the PR title as the subject. Commit this doc last as a docs(qa) commit, byte-exact. If main moves, the PR needs a catch-up, a merge-only read and a reissue.
