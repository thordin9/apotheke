# QA: apotheke #306, read the frontend account id from an environment secret

- PR: https://github.com/thordin9/apotheke/pull/306 (DevOps), base `main`. Hardening follow-up to L-2083 (#300 gate): the `deploy-site` deploy reads the Cloudflare account id from an environment secret instead of a variable.
- Reviewed head: `ad308d5ded191aa6acf3c63041f34058eaf70612`. 0 behind main `695a022a921403e21f5da93ae66c2b6a3a147b78` (chore(release): v3.42.2), 11 ahead, mergeable_state clean, open. This is a merge-only read. The previous content PASS was `56b8603afd9229e87b252c5a1eb5768ac15d3956` against `daf93c1f` (#300). The held doc at that head (sha256 `a1aa2068…`) is void.
- Commits since `56b8603a`:
  - `22217ced` merge: sync main into ci/frontend-account-id-secret (51 chars). Parents `56b8603a` and main `18033500` (#309). Merge subject exempt. `CHANGELOG.md` was the only conflict.
  - `a61749ef` merge: sync main into ci/frontend-account-id-secret (51 chars). Parents `22217ced` and main `695a022a` (v3.42.2). Merge subject exempt. Git's auto-merge emptied `[Unreleased]` and folded the #306 `### Changed` bullet into `## [3.42.2]`.
  - `ad308d5d` docs: keep the #306 changelog bullet under Unreleased (53 chars). Parent `a61749ef`. Touches only `CHANGELOG.md`: the #306 bullet is back under `[Unreleased]` / `### Changed`, and `[3.42.2]` matches main.
  - PR title (59 chars) suits the squash.
- Diff vs `695a022a` is 5 files, +14/−5, the same counts as `daf93c1f...56b8603a`:
  - `.github/workflows/deploy-frontend.yml` +2/−2.
  - `CHANGELOG.md` +4.
  - `docs/deployment-and-operations.md` +1/−1.
  - `scripts/cf-deploy.sh` +2/−2.
  - `tests/Unit/DeployWorkflowsTest.php` +5.
- Gate: merge-only read (diff compare, CHANGELOG, CI). No suites, mutations or Docker. Reviewer: Apotheke QA 2

## Checklist

- [x] PR head is exactly `ad308d5d`. `a61749ef` and `22217ced` are plain merges; one parent of each is main (`695a022a` and `18033500` respectively). `ad308d5d` touches only `CHANGELOG.md`.
- [x] CI on `ad308d5d` is green. 25 check runs: 23 success, 2 skipped (Deploy apotheke-api and Deploy getapotheke-app, as intended on PRs). mergeable_state clean, behind_by 0. Runs: CI 43389013052 (completed 10:08:58 Europe/Athens, UTC+2), Deploy frontend 43389012844 (Build and check; 09:54:03), Deploy edge 43389012751 (Check and dry run; 09:55:19), and PR #306 43389007535 (Analyze / CodeQL; 09:54:36).
- [x] Merge-only read of `ad308d5d`. Per-file +/- of `695a022a...ad308d5d` equals `daf93c1f...56b8603a` on every file (5 files, +14/−5). The four non-CHANGELOG patches are byte-identical, including hunk line numbers. Nothing else drifted.
  - `CHANGELOG.md`: only the #306 bullet sits under `[Unreleased]` / `### Changed`, with the full PR URL, above `## [3.42.2]`. The header through `## [Unreleased]` (494 bytes) and everything from `## [3.42.2]` down (191738 bytes) are byte-identical to main `695a022a`. `#306` appears only in `[Unreleased]`, never in a released section. The auto-merge misplacement in `a61749ef` (empty `[Unreleased]`, #306 folded into `[3.42.2]`) was fixed in `ad308d5d` and verified.
- [x] New commit subjects are Conventional Commits and at most 72 chars (merges exempt).

## Findings

- **L-2083 (from #300): closed for `deploy-site`**, unchanged. #300 covers `deploy-edge`.
- **N-2085 (from #300): closed.** The deploy-step pin covers both workflows (`DeployWorkflowsTest.php:269`).
- **N-2136:** still open, unchanged. The `deploy-site` variable `CLOUDFLARE_ACCOUNT_ID` is still set. Remove it after the merge, folded into the N-2084 cleanup. Earlier public frontend logs still contain the id. Removing them is the owner's decision.
- **N-2137: closed.** `cf-deploy.sh:16, 25` call the account id a secret.
- **N-2138: closed** (already closed at the catch-up onto `daf93c1f`). Later CHANGELOG catch-ups, including the `ad308d5d` fix-up of the `a61749ef` auto-merge, also resolved correctly.

0 High, 0 Medium, 0 Low, 1 Nit open.

## Verdict

**PASS** at `ad308d5d`, 0 behind main `695a022a` (v3.42.2). No High or Medium. N-2085, N-2137 and N-2138 remain closed. N-2136 remains, to be cleaned up after merge. The held doc (sha256 `a1aa2068…` at `56b8603a`) is void.

Squash-merge with the PR title as the subject. Commit this doc last as a docs(qa) commit, byte-exact. If main moves, the PR needs a catch-up, a merge-only read and a reissue.
