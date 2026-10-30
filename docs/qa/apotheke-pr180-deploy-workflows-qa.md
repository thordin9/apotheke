# QA: PR #180, Cloudflare deploy workflows

- **PR / head:** #180 `cursor/deploy-pipelines` → `main` (DevOps), same repo, not draft. **Head: `0f123ec83ca8ec9472b4dc2ae8a33ef42645caaa`.** Merge-only re-check from my PASS at `2aa74df2d56da3cc29697791091d20302ef5ed54` (an ancestor; no force-push): one merge commit `0f123ec` of #178's final head `20df68addf69ba65b68be6294f0af6f7bcf5657a`, clean, with no hand edits. GitHub reports it **mergeable (clean)**. #178 has since been squash-merged to `main` as `7f86dc4`, whose tree equals `20df68a`'s, so the head is 1 commit behind `main` with no content difference to bring in.
- **CI at the head:** 24 check runs: 22 green, and both deploy jobs (`Deploy getapotheke-app`, `Deploy apotheke-api`) skipped as expected on a PR, incl. both required `Tests (PHP 8.4, sqlite)` and `Tests (PHP 8.4, pgsql)`.
- **Scope:** merge-only re-check of `0f123ec` (no suites or mutations re-run); the earlier review (tests, mutations, earlier merges) is below. Reviewed 2026-10-30 (EET).

## Checklist

| Check | Result |
|---|---|
| Merge `0f123ec` (this re-check) | Parents `2aa74df` and `20df68a`; merge base `627fb3e`. Across all 1437 paths, every file equals the side that changed it: this PR's 24 files (workflows, `edge/`, deploy tests, docs) are byte-identical to `2aa74df`, and #178's 46 delta files (main #182 consent, v3.4.0, the consent test fix, #178's docs/qa file) equal `20df68a`. One overlap, `docs/deployment-and-operations.md`: separate hunks (this PR's Cloudflare deploys section, #182's Cookie consent section); a three-way auto-merge has no conflict and equals the head byte for byte. `Routes.php` and `RoutesCompiled.php` equal `20df68a` (unchanged by this PR). #180 now also carries `docs/qa/apotheke-pr178-frankenphp-image-qa.md`, which is expected with merge order #178, then #180 |
| Merge `2aa74df` (previous re-check) | Parents `4915e2e` and `627fb3e`, message `Merge remote-tracking branch 'origin/cursor/frankenphp-image' into cursor/deploy-pipelines`. Single merge base `115162d`. A three-way merge of the two parents on that base (git `merge-tree`, from codeload tarballs) gives tree `f427ead`, exactly the head's tree, all 1428 paths, no conflict and no hand edit. It changes only #178's 4 delta files: `docker/frankenphp/Caddyfile` and both docker check scripts equal `627fb3e`; `docs/deployment-and-operations.md` combines this PR's N-1465 text with #178's N-1455/M-1454 text. No deploy workflow, test or script of this PR changed |
| Earlier delta `4cd73da..4915e2e` | 2 commits on top of `4cd73da`. Merge `d8f3414` (parents `4cd73da`, `115162d`): all 1428 paths equal a three-way merge against the merge bases `6cf12b1` and `288c44f` (criss-cross), with no conflict and no hand edit. Nits commit: 5 files, exactly both deploy workflows, `docs/deployment-and-operations.md`, `scripts/deploy-scripts.test.mjs`, `tests/Unit/DeployWorkflowsTest.php`. No identifiers or secrets |
| Release gate (L-1460) | The deploy job's first step, identical in both workflows: `main` exits 0 with no API call; any other ref fails; a tag must match `^v(0\|[1-9][0-9]*)\.(…)\.(…)$` **before** it is used, and is then passed quoted in `gh api "repos/$GITHUB_REPOSITORY/releases/tags/$tag" --jq …`. It passes only if the output is exactly `draft=false prerelease=false`. That endpoint returns published releases only, so a draft or a missing release is a 404, and the step fails. For `release: published` runs `github.ref` is the tag, so the API check runs there too, on top of the job `if:` prerelease check |
| Fail-closed behaviour | Ran the extracted step with the step's `shell: bash` (`-eo pipefail`) and a fake `gh`: a full release passes, and 404, a network error, a prerelease, a draft, `{}`, an empty 200, non-JSON and an array body all fail. Ref names with `/../`, a newline, spaces, `$(…)` or `?` are refused before any API call. An empty or failed output never equals the exact pass string |
| Token and order | `GH_TOKEN` and `github.token` appear exactly once per workflow, on this step, pinned by a test (N-1464 fixed). The Cloudflare token and account variable are not in its env. It is step 1, before the token check, any checkout, `npm ci` or wrangler call. The deploy job now declares `permissions: contents: read` itself (the workflow block is also `contents: read`); no `write`, no `id-token` |
| Test harness | `deploy-scripts.test.mjs` cuts the step's `run:` block out of each workflow with a regex (so it tests the real script) and runs it with a fake `gh` that takes the `--jq` argument from the actual call and applies it with `jq -r`. Cases: main (no call), five bad refs (no call), a full release (one call, exact path), a 404, prerelease, draft, both, missing fields. It asserts `shell: bash` on the step and runs it with `-eo pipefail`, as the runner does (N-1465 fixed) |
| N-1461 / N-1462 | `testNoStepMayFailOpen` rejects `continue-on-error` anywhere in both deploy workflows. Every `npm ci` in `deploy-frontend.yml`, `deploy-edge.yml` and `edge-local.yml` is `npm ci --ignore-scripts --no-audit --no-fund`, pinned by `testEveryNpmCiSkipsInstallScripts`. CI's real frontend build, `wrangler dev` check and edge dry run all pass with it |
| Local runs (PHP 8.4, Node 22.23.4; all Cloudflare env vars unset) | At `4915e2e`: `DeployWorkflowsTest` 18 tests, 213 assertions OK. `deploy-scripts.test.mjs` 13/13 pass. actionlint 1.7.7 clean |
| actionlint / zizmor | actionlint 1.7.7 is clean on all workflows. zizmor 1.31.1 (offline): no findings on the three deploy workflows (4 ignored, 1 suppressed, as before). Findings elsewhere (`ci.yml`, `frontend.yml`, `release.yml`, `snapshots.yml`) are unchanged from `8bc1e5f` and outside this PR |
| Mutations (3 delta + earlier 39) | Delta: `GH_TOKEN: ${{ github.token }}` added to the deploy step (PHP test fails), `shell: bash` removed (both suites fail), a draft treated as passing via the compare or the jq (caught; the jq hard-code by the node test). All 3 caught. Earlier N-1464 survivor is now caught |
| Docs | `deployment-and-operations.md` and `ci.md` match the workflows: tag shape, the release read with the read-only token, drafts and missing releases failing, `main` needing no release, and `--ignore-scripts` in every job of these workflows. The tests list in `ci.md` is updated. The draft reason now names the by-tag endpoint returning only published releases (N-1465 fixed) |
| Commits / CHANGELOG | 35 commits on GitHub, including #178's merged history. Conventional Commits is green. No CHANGELOG edit, which is correct per `docs/releasing.md` |

## Findings

### H
None.

### M
- **M-1430: fixed** (earlier).

### L
- **L-1431, L-1432, L-1433: fixed** (earlier).
- **L-1460: fixed.** A tag deploy, manual or from a release, now needs a published release for that exact `vX.Y.Z` tag that is neither a draft nor a prerelease. It fails closed on every error path I tried, and the tests run the workflows' own step. 10 targeted mutants are caught.

### N
- **N-1434 to N-1438: fixed** (earlier).
- **N-1461: fixed.** No `continue-on-error` anywhere in the deploy workflows, pinned by a test.
- **N-1462: fixed.** Every `npm ci` in the three workflows skips install scripts, pinned by a test.
- **N-1463 (outside this PR; owner Front-End): open,** unchanged. 404s under `/assets/` get the one-year `immutable` header.
- **N-1464: fixed.** `GH_TOKEN` and `github.token` each appear exactly once per deploy workflow, on the tag step, pinned by `DeployWorkflowsTest`.
- **N-1465: fixed.** The tag step sets `shell: bash` (`-eo pipefail`); the node test asserts it and runs the step the same way. The step comment and `deployment-and-operations.md` now say a draft is a 404 because the by-tag endpoint returns only published releases.

## Security

No secret reaches a PR run, a `run:` line, `npm ci` or the log. A deploy now needs `main`, or a `vX.Y.Z` tag with a published full release, checked with the job's read-only `github.token` before any secret, checkout or install. Each environment has one deploy at a time, with no cancellation. Each deploy has the allowlist guard, an explicit config and no install scripts. No credentials were used or needed. All Cloudflare variables were unset for the local runs, and nothing was printed or committed.

## Verdict

**PASS** at `0f123ec83ca8ec9472b4dc2ae8a33ef42645caaa`: 0H/0M/0L/1N open (N-1463, Front-End, outside this PR). Every earlier finding stays fixed. The merge of #178's final head `20df68a` (which passed its own re-check and is now on `main`) is clean, with no hand edits, and CI is 22 green with the 2 deploy jobs skipped. Merge order: #178 (merged), then #180.
