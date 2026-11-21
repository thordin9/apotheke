# QA: apotheke #352, the apotheke-ops deploy wiring (D1)

- PR: https://github.com/thordin9/apotheke/pull/352 (DevOps), base `main`. Email allowlist and read-only switch for `apotheke-ops`, the PR dry run and the gated `deploy-ops` job.
- Reviewed head: `94820105c856bacec3464352f4258c8485f6eb27`, 0 behind main `4637fa18` (v3.61.0), merge state `CLEAN`. Content PASS at `7980b7c5` (0 behind `815a5abd`); catch-up merge `9482010` (onto v3.61.0) read merge-only. Full gate at `1ff05228` (FAIL on M-2520); delta re-verified: `ffbbfd3` (fix, M-2520), `b5a7c8f` (fix, N-2521), merges `c67c425` (onto #350) and `88efa9a` (onto v3.60.0), `7980b7c` (CHANGELOG). Earlier commits: `5801837` (feat), `dfef67f` (ci), `96433e0` (docs), `d15ad25` (CHANGELOG), `964b9ab` (ci), merges `e26b316` and `1ff0522`.
- Gate: full (auth). Full diff read, edge and deploy tooling suites, the PHP workflow test, 27 mutants, ad-hoc checks and a secret scan.
- CI on `1ff05228`, all green: CI 44816174972, Deploy edge 44816174885, Deploy frontend 44816174936, PR #352 44816160885 (23 pass, 3 deploy jobs skipped). On `7980b7c5`: CI 44827803058, Deploy edge 44827802876, Deploy frontend 44827802979, PR #352 44827796535. On `94820105`: CI 44835149030, Deploy edge 44835149036, Deploy frontend 44835149023, PR #352 44835142002, all green.
- Reviewed 2026-11-20, 23:15 to 23:45; delta 2026-11-21 01:00 to 01:10; merge-only read 01:35 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope (main...head): 14 files, +636/−72 (the same 14 files as at `1ff05228`). No `edge/src`, migrations or PHP app code.
- [x] Merges: parents `[964b9ab, d5e36e3a]` and `[e26b316, 1a9c9a3d]`. Each differs from `git merge-tree` only in `CHANGELOG.md` (conflicts declared in the merge bodies). The catch-ups `[b5a7c8f, 86a5e904]` and `[c67c425, 815a5abd]` read the same way (`88efa9a` is identical to its merge-tree); `7980b7c` moves the bullet back to `[Unreleased]`. `9482010` (parents `[7980b7c, 4637fa18]`) differs from `git merge-tree` in `CHANGELOG.md` and `edge/tests/ops-worker.test.mjs`, both declared: the conflict markers are dropped, keeping both sides (the D1 tests, then #351's users tests). End state: main...head has the same 14 files and +636/−72; edge suite 302/302 and `tsc` clean on the merged head; `VERSION` equals main (3.61.0). #351's users route is GET-only, so the writes gate doesn't touch it. End state: CHANGELOG differs from main only by the `[Unreleased]` / Maintenance bullet with the full PR URL; `VERSION` equals main.
- [x] Access: a non-empty header first, else the `CF_Authorization` cookie (exact name); an empty or blank header falls through to the cookie, verified the same way, and with no cookie stays 401 (N-2521 test). RS256 only, `kid` required, aud, iss, exp, nbf and iat checks unchanged from #346. An email outside `OPS_ALLOWED_EMAILS` (case-insensitive, trimmed) gives 403 `forbidden`; an empty list makes `accessConfig` null, so 503 `not_configured` on everything.
- [x] Writes: 403 `writes_disabled` unless `OPS_WRITES_ENABLED` is exactly "1", checked after Access and before CSRF; `wrangler.jsonc` ships "0"; `/config` reports `writes_enabled`.
- [x] Routing: `run_worker_first: true`, so assets stay behind Access; `/api/ops/v1` goes to the API. No route, custom domain, workers.dev or ids in the config. `crons: []`, no Workflow.
- [x] Deploy: `deploy-ops` is skipped unless `vars.OPS_DEPLOY_ENABLED == '1'`, re-checks the variable in-job, runs in `deploy-edge`, needs a full release tag or main, masks the account and D1 ids. `ops-deploy-config.mjs` refuses missing or malformed values, a committed id or Access value, and any of the three flags other than "0"; it prints only a count. The guard allowlists `apotheke-ops`.
- [x] PR dry run: dummy D1 id and Access values; pins `DB`, `OPS (apotheke-api#Ops)`, `AUTOFILL (apotheke-autofill#HostedAutofill)`, `ASSETS` and the three "0" flags; restores the config.
- [x] Tests: `node --test` deploy scripts plus edge 297/297 (again at `7980b7c5`; `tsc` clean in `edge/ops`); `DeployWorkflowsTest` 28 tests, 477 assertions (PHP 8.4).
- [x] Mutants: 27/27 killed (allowlist, empty list, case folding, cookie fallback and name, alg, writes gate and its default, `/config`, the shipped "0", crons, `run_worker_first`, the AUTOFILL entrypoint, each config-script refusal, the logged count, the guard allowlist, the job `if:`, the in-job gate, the environment, the config step, the dry-run AUTOFILL pin). The four workflow mutants are killed by the PHP test. Delta: reverting `b5a7c8f`'s check to `!== null` is killed; dropping `.trim()` survives but is equivalent (`Headers` already strips surrounding whitespace).
- [x] Cloudflare: no per-request global state; config from vars and bindings.
- [x] Docs: `docs/operator-console.md#deploying-the-console` matches the code, and the access comment now covers the empty header. Every address in the diff is a placeholder (`example.invalid`, `example.test`, `x.test`); the real operator address appears 0 times in the tree at head.
- [x] Secret scan: 0 secrets; the hex and UUID values are action pins, the `wrangler types` hash and all-zero dummies.

## Findings

- **M-2520 (fixed in `ffbbfd3`):** the PR added a real operator email address 6 times (deploy docs table, the go-live steps, the check step, and two tests) to this public repository. Public docs use placeholders. All 6 now use placeholders; the real value lives only in the `deploy-edge` variable.
- **N-2521 (fixed in `b5a7c8f`):** a present but empty `Cf-Access-Jwt-Assertion` header disabled the cookie fallback. It now falls through to the cookie, with a test.
- **N-2522:** the branch has a `feat(edge)` commit, but the squash is `ci(edge)` with a Maintenance bullet, so no release. Acceptable while the Worker is off; say so in the PR body.
- **N-2523:** the merge subjects are "Merge remote-tracking branch 'origin/main' into …". The squash replaces them.

0 open High, Medium or Low; M-2520 and N-2521 fixed; 2 Nits open (next free id: 2524).

## Verdict

**PASS** at `94820105`, 0 behind main `4637fa18` (v3.61.0). (FAIL at `1ff05228` on M-2520, now fixed.) This doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #352"), followed by the hash check and green CI on that head. Squash as "ci(edge): wire the apotheke-ops deploy, read-only and off (#352)" (64 characters).
