# QA: apotheke #314, lifecycle jobs for the operator console (E4), delta re-gate

- PR: https://github.com/thordin9/apotheke/pull/314 (Coder), base `main`. Operator console E4.
- Reviewed head: `3b1634239f5bccef2cc7bf9ac14d55f464a5462a`, a plain merge of main `2510138e` (#313) into the fix `0daa3fa4`. 0 behind, 6 ahead.
- Prior review: FAIL at `70f558fc` on main `85c6ad8c` (M-2157). This doc replaces that one.
- Gate: a delta re-gate of the fix `70f558fc...0daa3fa4` (8 files, +220/-23), with probes, 19 mutants, the CI edge steps run locally and a secret scan. Plus a merge-only read of `3b163423`.
- CI on `0daa3fa4`: CI 43452899471, Deploy edge 43452899299 and CodeQL 43452890108 all succeeded. On `3b163423`, Deploy edge 43456169536 and PR #314 (CodeQL) 43456161608 succeeded. CI 43456169678 was still running at review time, including Tests (PHP 8.4, sqlite) and (PHP 8.4, pgsql).
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only read: the parents of `3b163423` are `0daa3fa4` and `2510138e`. `git merge-tree` gives the same tree (`417c4f73`). The merge brought only main's 5 #313 files, the same numstat as `85c6ad8c..2510138e`. `gh api compare` shows behind_by 0. The per-file numstat of `main...3b163423` equals `85c6ad8c...0daa3fa4` (12 files, +1151/-7).
- [x] M-2157 fix: `retentionOver` needs a `done` `delete` job for this installation with a recorded export key and `retention_days >= 1`. `now` must be at or after its finish time plus that many days, on D1's clock, with both sides written in the same `strftime` format. It's checked at create, confirm, start and in `begin` for every purge step. A finished job is never written again (every UPDATE is state-guarded), so the gate can't flip back, and TOCTOU isn't an issue.
- [x] Probes: P1 (a failed delete, aged 400 days) is refused. P2 (an immediate purge after a successful 30-day delete) is refused. At the boundary, 30 days less 1 s is refused and exactly 30 days (and 720 h) is allowed. An `export` job or another tenant's delete doesn't satisfy the gate. A purge that was confirmed and then had its delete flipped to failed is refused at its step, with nothing removed.
- [x] Purge scope: the export stays under `installations/<do_name>/` until a purge after retention, which deletes it with everything else, as documented. Nothing leaks after the final purge. Mutant "skip backups/" is killed.
- [x] L-2159: `LifecycleError` extends `NonRetryableError` from `cloudflare:workflows`, the module the runtime exports it from. The test stub has the same shape. Its `name` stays `NonRetryableError`, which is what the Workflows engine matches on (miniflare `binding.worker.js`). `r2_interrupted` is a plain `LifecycleRetry`, so it gets the default retries (bounded) and then `failed`.
- [x] L-2161: in `installationEnv`, a container for any installation other than primary starts only if a fresh read shows `provisioning` or `active`. Primary returns before the query, and provisioning still starts. A warm container isn't re-gated, but the stop step destroys it.
- [x] Run as CI does (Node 24, `npm ci --ignore-scripts`) on `3b163423`: deploy-scripts + edge tests pass 167/167. `wrangler types --check` (4.160.0) and `tsc --noEmit` pass. `wrangler.jsonc`, `storage.jsonc`, both `worker-configuration.d.ts` and `.github/` are byte-identical to main. 0003 is still `932738cb…6fd83` and is now pinned.
- [x] Mutants, 19 (each restored): 14 killed and 5 survived.
  - Killed: the create, confirm and step gates; `>=`→`>`; days→hours and days→seconds; accepting a failed delete; measuring from `created_at`; `LifecycleError` as a plain Error; `r2_interrupted` non-retryable; the start gate allowing `suspended` or `deleting` or dropped; and purge skipping backups.
  - 4 survivors are equivalent redundant layers: the start gate (every step re-checks), the export-key clause (a `done` delete always has a key), `kind = 'delete'` (other kinds have no retention) and `retention_days >= 1` (createJob enforces it).
  - 1 survivor is a real gap: N-2170.
- [x] Docs and CHANGELOG: `operator-console.md` covers the retention gate, the lack of an immediate purge, the 30 s routing window, the start gate, NonRetryableError and the step cap. The `[Unreleased]` bullet is updated, with the full #314 URL.
- [x] Commits: 5 Conventional subjects, 46 to 62 chars. The merge subject (74) is exempt. Secret scan of the 220 added lines: 0 hits (the only hex is the public 0003 pin).

## Findings

- **M-2157:** closed. Covered by P1, P2, the boundary probes and the mutants above.
- **L-2158, L-2159, L-2160, L-2161:** closed. L-2160's test asserts the whole snapshot is unchanged, and that mutant is now killed.
- **N-2162:** closed (exact host and out-of-prefix export are tested). **N-2163:** closed with no change. **N-2164:** closed (the cap is documented).
- **L-2165 (open, hardening):** a second confirmed `delete` job with a shorter retention shortens it. In the probe, a 30-day delete plus a 1-day second delete allowed a purge on day 2, because `retentionOver` takes any qualifying row. Fix: measure from the longest retention or the first finished delete, or refuse a delete on `deleting`.
- **L-2167 (open, latent):** the delete job's export runs after `deleting` and stop, and `suspended` export needs the container. The new start gate refuses both, so once the backup endpoint is wired, every delete would fail and no purge could ever run. This fails closed. Fix it in the backup endpoint PR: export before the status change, or a backup-only start.
- **N-2166 (open):** the gate checks that an export key was recorded, not that the object exists (probe: export removed, purge allowed). Fix: a `head()` on the key at create and confirm.
- **N-2168 (open, pre-existing):** `runJob` derives `error_code` with `instanceof` after `step.do`. On Cloudflare the step error crosses the engine as a `WorkflowFatalError`, so the stored code is probably `error`. This affects diagnostics only. Fix: record the code inside the step. Not verified on Cloudflare.
- **N-2169 (open):** a residual race. A start that read `active` before the status change can call `c.start()` after a concurrent `stopContainer()` no-op, which leaves a container running until its inactivity timeout. The window is one D1 read, inside the 30 s route-cache window. Fix: re-check after start, or a stopped flag.
- **N-2170 (open):** the L-2159 test checks `instanceof NonRetryableError`, but the engine matches `name === 'NonRetryableError'`. A `this.name = "LifecycleError"` mutant survives. Fix: assert `e.name`.

Also noted, from Coder: `json_set`, `ON CONFLICT` and NonRetryableError subclassing are untested on remote D1 and Workflows.

0 High, 0 Medium, 2 Low and 4 Nit open, none blocking.

## Verdict

**PASS** at `3b1634239f5bccef2cc7bf9ac14d55f464a5462a`, 0 behind main `2510138e`, once CI 43456169678 on that head is green (both required Tests jobs included). Squash as "feat(edge): lifecycle jobs for the operator console". L-2165 and the Nits can follow in a later PR. L-2167 must be fixed in the backup endpoint PR.
