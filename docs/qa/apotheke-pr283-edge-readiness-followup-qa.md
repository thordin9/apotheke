# QA: apotheke #283, share one readiness wait and replace a stuck container

- PR: https://github.com/thordin9/apotheke/pull/283 (Coder), base `main`. Follow-up to the #281 findings L-1911 to N-1916.
- Reviewed head: `960c8a124fab722caea1b8fc56f9dc5772bdb263`, 0 behind main `362e7477` (v3.36.4). Commits: `812bd768` fix(edge): share one readiness wait and replace a stuck container (65 chars), `960c8a12` fix(edge): add the readiness hardening to the changelog (55 chars).
- Gate: full (diff read, edge suite on Node 24, CI's deploy-edge command, wrangler types and typecheck, new tests against main's Worker, 22 mutants, 3 probe tests)
- Reviewer: Apotheke QA 2
- Merge-only read at `ff1639eb1d743b4ef8e3c958848e914b0486716e`, 0 behind main `b1d7e349` (v3.36.5, with #282). Since `960c8a12`: `4756633f`, a plain merge of main `b1d7e349` (parents `960c8a12`, `b1d7e349`), then `ff1639eb` docs(handoff): drop the stale forward and sleep follow-up note (62 chars). The +/- lines of `main...ff1639eb` match `362e7477...960c8a12` for CHANGELOG, `edge/src/index.ts` and the test, and `index.ts` and the test are byte-identical to `960c8a12`. #282's edge files (`r2.ts`, `r2.test.mjs`) come in from main unchanged. The CHANGELOG adds only `### Fixed` plus the #283 bullet under [Unreleased]; 3.36.5 and older are byte-identical to main. The HANDOFF diff is the 503/re-probe/90 s line plus one Next-steps item 1 edit: "fixes are in; the Worker's forward and start robustness and the inactivity sleep follow separately." becomes "fixes are in.". That is accurate now that #271 (sleep) and #281 (forward and start) have landed and #283 completes them. Still 4 files, +91/-1, no secrets. This supersedes `d0d7c22a` (void).

## Checklist

- [x] Scope is 4 files, all additions: `edge/src/index.ts` (+21), `edge/tests/container-ready.test.mjs` (+64), `docs/HANDOFF.md` (+1), `CHANGELOG.md` (+4). No unexpected files.
- [x] Shared wait: `readying ??= startAndWait(...).finally(() => readying = null)`. It is cleared on both paths, and the clear runs before any waiter resumes, so a rejected wait is never reused and a later request never reads a stale result. It is checked only after the warm path (`running && ready`).
- [x] Timed-out count: kept in memory on the DO instance, with no module-level state. It resets on a healthy probe, on a fresh start and after a destroy. The first timeout keeps #281's still-running behaviour; the second one in a row destroys the container (one `destroy()` per shared wait), and the next request starts fresh. A DO eviction drops the count; that is acceptable.
- [x] 503: both paths still go through `unavailable()` with `Retry-After: 5`, `Cache-Control: no-store` and the fixed sentence. The constructor still sets the inactivity timeout once for a running container (#271).
- [x] Tests: the edge suite passes 76/76, and CI's command (deploy-scripts plus edge) passes 109/109. `wrangler types --check` and `tsc --noEmit` are clean. Against main's `index.ts`, 2 of the 5 new tests fail (the shared wait and the destroy). The other 3 pin behaviour that already exists.
- [x] Mutations: 18 of 22 killed. Killed: never clearing the wait, clearing it on success only, no sharing, destroy at the 1st or 3rd timeout, never destroying, the error text on either 503, a changed body, no Retry-After, Retry-After 60, 502, no Cache-Control, `ready` not reset on a failed forward, and a 40/89/91/120 s timeout. Survived: the 3 under L-1917 and L-1918, plus no reset after a destroy, which is equivalent while the destroy succeeds.
- [x] CHANGELOG: one Fixed bullet under [Unreleased] with the full #283 URL. The released sections are byte-identical to main. The HANDOFF line matches the code. No secrets in the diff. CI run 43135798385 is green: 23 check runs on the head, 22 success and 1 skipped (Deploy apotheke-api, as on PRs).

## Status of the #281 findings

- **L-1911** closed: 3 cold requests give 1 start, 1 timeout call and 3 `/healthz` probes. **L-1912** closed. **L-1913** closed (the body is pinned). **N-1914** closed: 89.9 s gives 200 and 90.1 s gives 503, and 40/89/91/120 s are killed. **N-1915** closed. **N-1916** accepted.

## Findings

- **L-1917:** the two count resets aren't pinned by a test. The mutants that drop the reset on a healthy probe or on a fresh start both survive. Without the resets, a container that once timed out and then served would be destroyed after its next single timeout. A fresh container started after a self-exit would also be destroyed after its first wait, which removes the slow-first-start allowance. The code is right today; two probe tests kill both mutants. Hardening: add those tests.
- **L-1918:** no test covers clearing the wait on success after a cold start. A mutant that clears it only on failure survives. With that mutant, after a cold start and a failed forward, the next request reuses the settled wait and forwards without a re-probe. The existing re-probe test starts with a running container, so it never sets the wait. A cold start, then a failed forward, then a re-probe test kills it.
- **N-1919:** when `destroy()` fails, the count is still reset to 0, so a container that couldn't be destroyed costs two more 90 s waits before the next attempt. Hardening: reset only after a successful destroy.

0 High, 0 Medium, 2 Low, 1 Nit.

## Verdict

**PASS** at `960c8a12` (CI run 43135798385), confirmed at `ff1639eb` by the merge-only read; CI run 43143789706 on `ff1639eb` must be green before merge. Squash-merge with the PR title as the subject. L-1917 to N-1919 can go in a follow-up.
