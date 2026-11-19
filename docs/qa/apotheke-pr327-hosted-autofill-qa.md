# QA: apotheke #327, hosted autofill through the AUTOFILL service binding (L-2190, edge side)

- PR: https://github.com/thordin9/apotheke/pull/327 (Coder), base `main`. Edge half of the L-2190 pair. The service half is apotheke-autofill-service #42 (`docs/qa/apotheke-autofill-service-pr42-hosted-autofill-qa.md` in that repo, PASS at `bf9266eb`).
- Reviewed head: `15b95a56b6ec0a76fbabc5144c25dc891cd5557f`, a merge of main `b4835511` (v3.49.2) into `1ea35bd5` (the last content PASS), which merged main `b3c81498` into `5858ce51` (Nit fixes) on `ea5e61f5` (the first full gate). 0 behind, 7 ahead, `mergeable_state` clean.
- Gate: full (security-focused) at `ea5e61f5`, plus a full re-read of the `5858ce51` code delta and merge-only reads of `1ea35bd5` and `15b95a56`. The first gate covered the edge suite, 28 mutants across the pair and a local workerd end-to-end probe through a real `ctx.exports` entrypoint bound to #42's `HostedAutofill`. No PHP files are touched.
- CI on `15b95a56`, all green: CI 44476416768 (all jobs, including the required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql)), Deploy edge 44476416744 (Check and dry run green; Deploy apotheke-api skipped) and CodeQL (Analyze actions and javascript-typescript). 0 open code-scanning alerts.
- Reviewed 2026-11-19, 03:55 to 04:10 and 05:11 to 05:20 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main: 7 files, +275/−2: `edge/src/autofill.ts` (new), `edge/src/index.ts` (+14), `edge/tests/autofill.test.mjs` (new), fixture lines in `limits.test.mjs` and `r2.test.mjs`, `docs/operator-console.md` and CHANGELOG. This equals the `ea5e61f5` PR diff plus `5858ce51`. `limits.ts`, `installation.ts` and `wrangler.jsonc` are unchanged; no binding, var or secret is added.
- [x] Design (first gate): `autofill.internal` is wired only with limits on and never for primary (`takeLimits` returns null). Props are the object's own name plus `autofill_calls_month`, rewired on each request. `enableInternet: false`. It fails closed with 503 (limits off, primary, bad or missing name or props, no binding) and returns 404 outside `/v1/info` and `/v1/suggest`. Only `content-type`, `content-length` and `accept` are kept and the query is dropped. In the workerd probe, spoofed installation, limit, `Authorization`, `Connection` and underscore headers were all ignored, and a Content-Length mismatch got the service's clean 400. No bearer token reaches the container env, vars, config or D1.
- [x] `5858ce51` code: `count(value, round = Math.floor)` clamps to `[0, 999999999999999]`. Hard rounds down and soft rounds up. Since `plan_limits` holds `INTEGER >= 0` with `soft_value <= hard_value`, and the default soft is `0.8 * hard`, `ceil(soft) <= hard`. So soft can't exceed hard or 15 digits (the cap still applies afterwards). `-0.5` gives `Math.ceil` → `-0`, then `Math.max(0, -0)` → `+0`, sent as `"0"`; `-0` and `-3` also give `"0"`. Hard 7 with no plan soft sends 6, which matches the service's own 5.6 default (warning from call 6).
- [x] Mutants on the delta: M11 (clamp removed) and M29 (soft rounded down) are killed. M30 (hard rounded up) and M32 (cap removed) survive but are equivalent for valid D1 rows. M31 (`Math.round` for soft) survives (N-2294).
- [x] Merge `1ea35bd5`: parents exactly `[5858ce51, b3c81498]`. `git merge-tree` conflicts only in CHANGELOG.md and `edge/tests/r2.test.mjs`, and every other path equals git's own merge. `index.ts` and the docs carry exactly the PR's own hunks against main. Incoming main files left untouched by the PR (`lifecycle*.ts`, `ops-backup.test.mjs`, `lifecycle.test.mjs`, the #324 QA doc and VERSION) are blob-identical to main. `r2.test.mjs` is main's file plus the one autofill import after ops-backup. The CHANGELOG is main's file plus the #327 `### Added` bullet with the full PR URL. Everything from `## [3.49.0]` down, the header, VERSION and README are byte-identical to main. The merge body declares both conflicts. After #324, `opsBackup` calls `ensureReady()` without limits, so a backup-only start never wires autofill.
- [x] Merge `15b95a56`: parents exactly `[1ea35bd5, b4835511]`. `git merge-tree` conflicts only in CHANGELOG.md and `edge/tests/limits.test.mjs`, as the merge body declares, and every other path equals git's own merge. `readyBox` keeps this PR's `AutofillOutbound` export and takes main's `DB: controlDb()`; nothing else differs from the conflict sides. The main...head numstat equals the PR's own diff before the catch-up. The CHANGELOG is main's file plus the #327 `### Added` bullet; everything from `## [3.49.2]` down and VERSION are byte-identical to main.
- [x] Edge suite on `15b95a56`: 241/241 with CI's command on Node 24.22.1 (231/231 on Node 22.23.3 and 24.22.1 at `1ea35bd5`). `tsc --noEmit` and `wrangler types --check` are clean. `check-links` reports 400 links and 0 broken.
- [x] Commits: `295554bd` (66 characters), `20856119` (51) and `5858ce51` (66) are Conventional; the 3 merges are exempt; `check-commits` is ok. gitleaks with the repo config on the new commits found 0 leaks. Test values are labelled fakes.

## Findings

- **L-2190 closed by this pair.** It takes effect once #42 is deployed. Until the binding is added, hosted autofill is 503.
- **N-2290 closed:** soft rounds up.
- **N-2291 closed:** negative and fractional soft values are tested.
- **N-2293 closed:** `operator-console.md` now gives the required `autofill_url`, the rounding and clamp rules, the account as the trust boundary for `HostedAutofill`, and the 5-step order to turn it on (#42, #327, binding, `autofill_url`, `LIMITS_ENABLED`).
- **N-2292 deferred** (#42's CHANGELOG link) to the next autofill PR. **L-2191 open**, assigned to Coder's autofill PR before #329, and it must be fixed before the binding is added. N-2192 is open and N-2193 is reduced.
- **N-2294 (new):** Test gap. No case tells ceil apart from round for soft (e.g. hard 3, where 2.4 must send 3), so M31 survives. Add one case in a later PR.
- Stated risks accepted (unchanged): rebinding after an eviction, the per-call limits check, a streamed body with the service checking length, no edge timeout, and an unlimited month for a plan with no `autofill_calls_month` row.

0 High, 0 Medium, 0 Low and 1 Nit (N-2294) open from this PR (next free id: 2295).

## Verdict

**PASS** at `15b95a56`, 0 behind main `b4835511` (v3.49.2). This doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #327"), followed by the hash check and green CI on that head. Squash as "feat(edge): autofill.internal through the AUTOFILL binding" (65 characters with " (#327)"). Deploy order: #42 deployed before the `AUTOFILL` binding is added.
