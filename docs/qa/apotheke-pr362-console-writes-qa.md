# QA: apotheke #362, enable operator console writes in production

- PR: https://github.com/thordin9/apotheke/pull/362 (Coder), base `main`. 6 commits, 14 files, +123/−66: the writes flag to `"1"` on apotheke-ops and apotheke-api, the unused ops `AUTOFILL` binding dropped, follow-ups N-2553, L-2554 and N-2555 from #359. No PHP, migration or `VERSION` change.
- Reviewed head: `05d58614373af880d1c1cbab0ef1a1d195f59ced`, 0 behind main `4ac97ed4` (v3.68.0). CI on the head: 23 success, 3 skipped (the three deploy jobs, as on every PR), 0 failed.
- Gate: full (security-relevant: turns on production writes). Diff read in full; edge tests, `tsc`, `wrangler types --check`, the PHP deploy-workflow test, mutants, links and commit subjects run locally. No Docker or PostgreSQL (no PHP code changed).
- Reviewed 2026-11-21 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Owner approval: the switch-on approval was relayed by agents (Coder, via the PM). QA did not verify it and does not gate on it; the PM's gate owns that.
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
- [x] Overlap: #363 (open) also edits `docs/HANDOFF.md` and conflicts with this head there; whichever merges second needs a catch-up. Once this lands, #363's L-2561 (writes-off wording) is moot.

## Findings

- **N-2565:** `tests/Unit/DeployWorkflowsTest.php:92` still asserts `'env.AUTOFILL (apotheke-autofill#HostedAutofill)'` in the `check` job, meant for the ops dry run. It now passes only through apotheke-api's merged dry-run list, so it pins nothing on ops. Suggest asserting the ops dry-run step does not list `env.AUTOFILL`.

## Verdict

**PASS** at `05d58614`. No High or Medium. N-2565 can ride with a later PR. Writes go live at the next approved Deploy edge (apotheke-api first, then apotheke-ops); a fix commit or main move gets a delta read and a reissued doc.
