# QA: apotheke #334, require AUTOFILL in the merged dry-run bindings (closes N-2320)

- PR: https://github.com/thordin9/apotheke/pull/334 (DevOps), base `main`. Follow-up to #329 (N-2320).
- Reviewed head: `e14442cc0be732e6c98c331ce3376ded898659ed`. 0 behind main `624148bf` (v3.53.2), 2 ahead, `mergeable_state` clean.
- Gate: docs/ci. Full diff read, the merged dry-run check in `deploy-edge.yml`, CI's dry-run log, and one config mutant. No PHP or edge source files are touched.
- CI on `e14442cc`, all green: CI 44582183560, Deploy edge 44582183653 (Check and dry run green; Deploy apotheke-api skipped), Deploy frontend 44582183574 (Build and check green; Deploy getapotheke-app skipped) and PR #334 44582172465. 23 pass, 2 skipped, as expected on a pull request.
- Reviewed 2026-11-19, 20:12 to 20:16 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main: 5 files, +13/−1. `.github/workflows/deploy-edge.yml` adds `env.AUTOFILL (apotheke-autofill#HostedAutofill)` to the required merged dry-run bindings. `scripts/deploy-scripts.test.mjs` and `tests/Unit/DeployWorkflowsTest.php` pin that string. `docs/operator-console.md` and CHANGELOG only. No wrangler, var, secret or route change, and `LIMITS_ENABLED` is untouched.
- [x] Merged dry-run logic: the check job greps wrangler's binding list and exits 1 with `::error` when any required entry is missing, so a missing `env.AUTOFILL (apotheke-autofill#HostedAutofill)` fails the job.
- [x] Dry run (CI log, Deploy edge 44582183653): the merged storage-config dry-run step ran and listed `env.AUTOFILL (apotheke-autofill#HostedAutofill)` among the Worker bindings (also present on the plain dry run). The job finished green, with no `::error`. `env.LIMITS_ENABLED ("0")` is still listed.
- [x] Tests on `e14442cc`: CI's edge command gives 244/244 on Node 24.22.1. `check-links` reports 401 links and 0 broken. The PHP pin was not run locally (no `vendor/` in the worktree); both CI Tests jobs (PHP 8.4, sqlite and pgsql) passed.
- [x] Mutant: dropping the AUTOFILL requirement from `deploy-edge.yml` was killed by `deploy-scripts.test.mjs` (`assert.match` for `env.AUTOFILL (apotheke-autofill#HostedAutofill)`), 243/244, exit 1. The workflow file was restored afterwards.
- [x] Docs: `operator-console.md` states that production `edge/wrangler.jsonc` has `max_instances` 1, which only fits `primary`, and that the value must be raised (an owner-approved production change) before onboarding a real hosted tenant. That matches the config comment and value. No account, D1 or other real ids. This PR does not change the setting.
- [x] CHANGELOG: both bullets sit under `[Unreleased]` / Maintenance and link the full PR URL. Released sections and `VERSION` are byte-identical to main.
- [x] Commits: `f21a3750` (57 characters, 64 with " (#334)") and `e14442cc` (46, already includes #334) are Conventional, and `check-commits` is ok. gitleaks 8.30.1 with the repo config on the new commits found 0 leaks (2 commits scanned).

## Findings

None. N-2320 is closed by this PR.

0 High, 0 Medium, 0 Low and 0 Nit open from this PR (next free id: 2321).

## Verdict

**PASS** at `e14442cc`, 0 behind main `624148bf` (v3.53.2). This doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #334"), followed by the hash check and green CI on that head. Squash as "ci(edge): require AUTOFILL binding in merged dry run" (59 characters with " (#334)").
