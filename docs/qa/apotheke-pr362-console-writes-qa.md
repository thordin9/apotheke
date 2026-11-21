# QA: apotheke #362, enable operator console writes in production

- PR: https://github.com/thordin9/apotheke/pull/362 (Coder), base `main`. 6 commits, 14 files, +123/−66: the writes flag to `"1"` on apotheke-ops and apotheke-api, the unused ops `AUTOFILL` binding dropped, follow-ups N-2553, L-2554 and N-2555 from #359. No PHP, migration or `VERSION` change.
- Reviewed head: `7ddb9f55d6f86bc4172fb262b484c53a067afb20`, 0 behind main `993c0ac0` (#363, on v3.68.0). Content reviewed at `05d58614` (on `4ac97ed4`); `7ddb9f55` is a plain merge of `993c0ac0` into `2cab0a31` (the first QA doc commit, now void), read merge-only. CI on `7ddb9f55`: 23 success, 3 skipped (the three deploy jobs, as on every PR), 0 failed.
- Gate: full (security-relevant: turns on production writes). Diff read in full; edge tests, `tsc`, `wrangler types --check`, the PHP deploy-workflow test, mutants, links and commit subjects run locally. No Docker or PostgreSQL (no PHP code changed).
- Reviewed 2026-11-21 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Owner approval: the switch-on approval reached QA only through agents (Coder, and the PM quoting its 1:1 with the owner). QA did not verify it and does not gate on it; the PM's gate owns that.
- [x] Writes flag exactly `"1"` on both Workers: ops `writesEnabled()` is still `=== "1"` and runs after Access, before CSRF; the Workflow `preflight()` still refuses unless `=== "1"`, before any step and on every resume. Access, the email allowlist, CSRF and both primary refusals (ops writes by id and by row; Workflow by job id and by row incl. `do_name`) are unchanged. `GET /config` reports `writes_enabled` as `=== "1"`.
- [x] Nothing else turned on: `OPS_PROVISIONING` and `LIMITS_ENABLED` stay `"0"` in both configs and d.ts; `ops-deploy-config.mjs` still fails on anything but `"0"` for both and now accepts only `"0"` or `"1"` for writes (`"true"`, `""`, `" 1"`, `"2"` refused, tested). Provision jobs are still 403 `provisioning_disabled` on create and confirm. No route, custom domain, cron, secret, queue, R2 or Durable Object binding added; `edge/storage.jsonc` untouched; ops `services` is now only `OPS`.
- [x] `AUTOFILL` on ops: nothing in `edge/ops/src`, `ops/console/src` or the ops tests reads it. Both `worker-configuration.d.ts` regenerate clean on wrangler 4.164.0 (`types --check` up to date in `edge` and `edge/ops`); the ops dry-run list in `deploy-edge.yml` and its test drop it; apotheke-api keeps its own `AUTOFILL` for `purge.autofill`.
- [x] Tests: 329/329 pass (`deploy-scripts.test.mjs` + `r2.test.mjs`, which imports the lifecycle and ops-worker suites). `tsc --noEmit` clean in `edge` and `edge/ops`. `tests/Unit/DeployWorkflowsTest.php`: 28 tests, 477 assertions, OK.
- [x] Mutants: 20/20 killed. Ops writes check truthy, `!== "0"`, removed; Workflow writes check truthy, `=== "0"`; deploy config accepting `"1"` for provisioning, for limits, for both, any writes value, writes only `"1"`; preflight `!row` refusal dropped and replaced by `row?.`; Workflow primary by id, by row, `do_name` half; ops primary by id, by row; provision gate on create, on confirm; `/config` `writes_enabled` truthy.
- [x] Docs vs code: the stuck-jobs section (L-2554) matches `writes.ts` and `lifecycle.ts`: confirm sets `queued` and creates the instance with the job id; a second confirm of a `queued` job answers 200 when the instance exists; `running` is 409 `not_paused`; a refused preflight leaves the state; `startJob` moves `queued` to `running` and lets `running` carry on; steps are idempotent. Rollback is ops `"0"` first, then review instances before apotheke-api. `operator-console.md`, HANDOFF and comments no longer say writes are off; nothing says provisioning or limits are on (both "stay off"/"remain refused").
- [x] CHANGELOG `[Unreleased]`: an *Added* and a *Fixed* bullet, both linking the full PR URL.
- [x] Commits: all 6 subjects pass `check-commits.sh`. Squash subject `feat(console): enable operator console writes in production (#362)` is 66 characters.
- [x] Links: 469 relative links in 346 files, 0 broken.
- [x] Public wording: secret scan of the 123 added lines: 0 UUIDs, 0 account or D1 ids, 0 tokens or keys, 0 emails; 2 32-hex matches are both wrangler type hashes in the d.ts headers; 2 `database_id` matches are prose. The operator email stays `<operator email>`.
- [x] Cloudflare phase: config and docs only; no new storage access, no per-request global state, DB config unchanged.
- [x] Merge-only read (`git merge-tree` of `2cab0a31` and `993c0ac0` against `7ddb9f55`): the only hand-resolved file is `docs/HANDOFF.md`. It keeps #363's rewrite, adds one current-state bullet for the writes flag (both configs `1`, api then ops, Access/CSRF/primary refusals stay, provisioning and limits `0`, rollback by writes `0`) and widens the #362 open-PR line. CHANGELOG and `operator-console.md` auto-merged; both #362 bullets stay in `[Unreleased]`; `VERSION` 3.68.0. #363's L-2561 is moot now that both configs declare writes on.

## Findings

- **N-2566:** `docs/HANDOFF.md` still lists the repo documentation sweep under *Open pull requests* as "No pull request yet"; it merged as #363 (`993c0ac0`). Drop the line in the next docs PR.
- **N-2565:** `tests/Unit/DeployWorkflowsTest.php:92` still asserts `'env.AUTOFILL (apotheke-autofill#HostedAutofill)'` in the `check` job, meant for the ops dry run. It now passes only through apotheke-api's merged dry-run list, so it pins nothing on ops. Suggest asserting the ops dry-run step does not list `env.AUTOFILL`.

## Verdict

**PASS** at `7ddb9f55` (content at `05d58614`). No High or Medium. N-2565 and N-2566 can ride with a later PR. Writes go live at the next approved Deploy edge (apotheke-api first, then apotheke-ops); a fix commit or main move gets a delta read and a reissued doc.
