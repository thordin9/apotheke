# QA: apotheke #329, the AUTOFILL service binding to HostedAutofill (L-2190, deploy side)

- PR: https://github.com/thordin9/apotheke/pull/329 (DevOps), base `main`. Step 3 of the 5-step order in `docs/operator-console.md`, after apotheke-autofill-service #42 and apotheke #327.
- Reviewed head: `6e5d62a0f893168db9d3f9262182f158446fd193`. 0 behind main `db161057` (v3.50.0), 4 ahead, `mergeable_state` clean.
- Gate: config change. Full diff read, a merge-only read of `c118c609`, the edge suite, both dry runs from CI's log, and 2 config mutants. No PHP or edge source files are touched.
- CI on `6e5d62a0`, all green: CI 44508029031 (all jobs, including the required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql)), Deploy edge 44508029551 (Check and dry run green; Deploy apotheke-api skipped) and PR #329 44508024215. 22 pass, 1 skipped.
- Reviewed 2026-11-19, 12:59 to 13:05 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main: 4 files, +14/−5. `edge/wrangler.jsonc` adds `"services": [{ "binding": "AUTOFILL", "service": "apotheke-autofill", "entrypoint": "HostedAutofill" }]` with a comment. `edge/worker-configuration.d.ts` is regenerated (`AUTOFILL: Service`, new hash). The diff also touches `docs/operator-console.md` and CHANGELOG. No var, secret, token or route is added, and `LIMITS_ENABLED` stays "0".
- [x] Dry runs (CI log, Deploy edge 44508029551): both the plain and the merged storage-config dry run list `env.AUTOFILL (apotheke-autofill#HostedAutofill)` and `env.LIMITS_ENABLED ("0")`. `scripts/edge-storage-config.mjs` keeps every config key it does not merge, so `services` survives the hosted merge. The Worker guard passes on the merged file.
- [x] Fail-closed until the flip: `src/autofill.ts` (#327) still answers 503 with limits off, so the binding alone does not reach the service. Prereq met: apotheke-autofill-service main `3faec1e9` (#44, which includes #42's `HostedAutofill`) had Deploy Worker green at 06:20 (UTC+2).
- [x] Merge `c118c609`: parents exactly `[bffb0b4e, db161057]`. `git merge-tree` conflicts only in CHANGELOG.md, and every other path equals git's own merge. The CHANGELOG keeps the #329 bullet under `[Unreleased]` and #327's bullet in `[3.50.0]`. `6e5d62a0` touches only `docs/operator-console.md`.
- [x] Tests on `6e5d62a0`: CI's edge command gives 241/241 on Node 24.22.1. `wrangler types --check` and `tsc --noEmit` are clean, and `check-links` reports 400 links and 0 broken.
- [x] Mutants: both were killed by `wrangler types --check`. M1 dropped the `services` line and M2 misspelled the entrypoint.
- [x] Docs: the step 3 wording in `operator-console.md` matches the config, and its CHANGELOG bullet links the full PR URL.
- [x] Commits: `fe610f9a` (58 characters), `bffb0b4e` (50) and `6e5d62a0` (58) are Conventional, and `check-commits` is ok. gitleaks with the repo config on the new commits found 0 leaks.

## Findings

- **N-2320 (new):** the merged dry run step in `deploy-edge.yml` doesn't list `env.AUTOFILL (apotheke-autofill#HostedAutofill)` among the bindings it requires. Today `wrangler types --check` would catch the binding being removed. It would not catch it being removed together with a regenerated `worker-configuration.d.ts`. Add it to the required list in a later PR.
- Stated risks accepted: the Cloudflare account is the trust boundary for `HostedAutofill`, as documented, and the deploy now depends on `apotheke-autofill` existing with that entrypoint on the same account.

0 High, 0 Medium, 0 Low and 1 Nit (N-2320) open from this PR (next free id: 2321).

## Verdict

**PASS** at `6e5d62a0`, 0 behind main `db161057` (v3.50.0). This doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #329"), followed by the hash check and green CI on that head. Squash as "feat(edge): add AUTOFILL service binding to HostedAutofill" (65 characters with " (#329)"). Steps 4 (`autofill_url`) and 5 (`LIMITS_ENABLED` "1") stay separate.
