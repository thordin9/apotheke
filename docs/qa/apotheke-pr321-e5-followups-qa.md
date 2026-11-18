# QA: apotheke #321, plan limits follow-ups (E5, after #315)

- PR: https://github.com/thordin9/apotheke/pull/321 (Coder), base `main`. Follow-ups to #315 for L-2171 to L-2175 (and L-2197 from #319), to land before `LIMITS_ENABLED` is set to "1".
- Reviewed head: `bcb4bc8cc46e82753a320da69ee0085c9a0bb817`, a plain merge of `837b45b7f2490ea34f09c5cb819eb409a4f9a502` (the previously gated head) and main `d19b0a2037d79bef4930b676094d5383f855cfe0` (v3.46.0; parents of that release are `1df3ab20`). 0 behind current main `d19b0a20`, 4 ahead. Parents are exactly `837b45b7` and `d19b0a20`.
- Prior review: **PASS on content** at `837b45b7`, then 3 behind main after #316, #319 and the v3.46.0 release, so it was held for a catch-up. This reissue supersedes that uncommitted doc.
- Gate: the full, security-relevant gate at `837b45b7` (diff, probes, the edge suite as CI runs it, the PHP suite on SQLite and Postgres 17, 53 mutants, docs, commits and a secret scan), plus this merge-only read of the catch-up onto `d19b0a20`. No suites, mutants or Docker in this read.
- CI on `bcb4bc8c`: Deploy edge 44374420377 succeeded (Check and dry run green; Deploy apotheke-api skipped). CodeQL (PR #321) 44374409295 succeeded (Analyze actions and javascript-typescript green; the CodeQL check green). CI 44374420111 was still running at this read: both required Tests jobs (PHP 8.4, sqlite and pgsql) in progress; the other 16 CI jobs succeeded (Lint, deptrac, DI rule, D1 (local workerd), Conventional Commits, Secret scan, Headless mobile checks, all 3 Visual E2E shards, both Docker build + smoke jobs, both Docker Compose self-hosting jobs, and both Upgrade and rollback jobs). `mergeable_state` is blocked while CI runs. Prior CI on `837b45b7` (CI 44360133250, Deploy edge 44360133335, CodeQL 44360122604) was fully green, including both required Tests jobs. The slow-regex alert at the first head `93b4a0d3` is gone after `60549873`.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only read of the catch-up onto `d19b0a20`. Parents of `bcb4bc8c` are exactly `837b45b7` and `d19b0a20`. behind_by is 0 against current main `d19b0a20` (main has not moved). `git merge-tree --write-tree 837b45b7 d19b0a20` conflicts only on CHANGELOG.md (declared in the merge message) and auto-merges `docs/operator-console.md`. The merge-tree tree differs from the head tree only in CHANGELOG.md. The auto-merged `operator-console.md` blob equals the head's (`b3210093…`); the PR-only patch vs main equals `562bc22a...837b45b7` except for index hashes and hunk line numbers (main's #319 inserted earlier lines); no conflict markers. VERSION equals main (3.46.0; blob `850ac8f2…`). `gh api compare 837b45b7...bcb4bc8c` brings in only main's files from #316, #319 and the v3.46.0 release, plus the CHANGELOG resolution and the clean `operator-console.md` auto-merge. Per-file numstat of `d19b0a20...bcb4bc8c` equals `562bc22a...837b45b7` exactly (10 files, +410/−54), including CHANGELOG (+4/−0). Every non-CHANGELOG PR file except `operator-console.md` is blob-identical to `837b45b7`. Every incoming main file except CHANGELOG and `operator-console.md` is blob-identical to main. Nothing dropped.
- [x] Scope vs main `d19b0a20`: the same 10 files, +410/−54: `edge/src/{index,installation,limits,r2}.ts`, `edge/tests/limits.test.mjs`, `modules/Core/Http/Cors.php`, `tests/Modules/Core/CorsTest.php`, `tests/Unit/Ci4ApiHttpTest.php`, `docs/operator-console.md` and CHANGELOG. The six `edge/src` and PHP files are blob-identical to `837b45b7`.
- [x] Header stripping, Content-Length, CORS probes and recount (prior gate; those blobs unchanged). The same checks stand: `toContainer` deletes both headers on every forward; the stream cap runs on every body; `withCors` matches PHP `Cors` for the probed origins; the recount prefix cannot reach another tenant, paging and the `.mpu/` skip hold, and `Counter.get()` shares one load.
- [x] Edge suite: 191/191 passed at `837b45b7` (Node 24, as CI runs it). Not re-run here. A grep of `test(` in deploy-scripts + edge shows every such file except `edge/tests/control-migrations.test.mjs` is blob-identical to `837b45b7`. That file gains exactly #319's two tests (`0004 plan_limits: photos_per_item allowed, columns, checks and rows kept, nothing seeded` and `0004 applied twice: same schema and rows`), matching main, so 191 + 2 = 193. `tsc`, `wrangler types --check` and `check-links` at `837b45b7` were clean.
- [x] PHP 8.4 and mutants stand from the prior gate (CorsTest and Ci4ApiHttpTest, and the full 638-test suites on SQLite and Postgres 17, all OK; 53 mutants, 42 killed and 11 surviving as N-2203 or equivalent). Not re-run here. The PHP files and `edge/tests/limits.test.mjs` are unchanged.
- [x] CHANGELOG: `git diff d19b0a20 bcb4bc8c -- CHANGELOG.md` is only the four added lines under [Unreleased]: a `### Fixed` heading and the #321 bullet, same text as at `837b45b7`, linking https://github.com/thordin9/apotheke/pull/321. The header and everything from `## [3.46.0]` down are byte-identical to main. Main's [Unreleased] was empty after the release, so this is main's section plus exactly that Fixed bullet.
- [x] Commits: both feature subjects are 61 characters and Conventional. Both merges are exempt.
- [x] Secret scan of the 410 added lines at `837b45b7` stands (0 secrets). The added lines in the PR files other than CHANGELOG and `operator-console.md` are unchanged.

## Prior findings

- **L-2171 closed.** M01 to M04 are killed. No client copy of either header reaches PHP, and the only warning on a response is the edge's own.
- **L-2172 closed.** The stream cap runs whatever the length says, and M06 to M10 are killed. The remaining gaps are in N-2203.
- **L-2173 closed for the 413 and simple requests; the preflight part is open as L-2201.** PHP now exposes `Apotheke-Limit-Warning` (M26 is killed).
- **L-2174 closed.** Drift is reset daily and before a 507. The documented residual is that writes during a listing can leave the counter off. The retry cost is L-2202.
- **L-2175 closed.** The #315 mutants M02, M18, M22, M24 and M31 are now killed.
- **L-2197 closed.** No client value reaches PHP. See N-2204 for how the app gets the value.
- Out of scope and still open: N-2176 to N-2179 (N-2179 and the backup wiring are deferred).

## New findings

- **L-2201:** A refused preflight still fails in the browser.
  - `admit()` refuses and counts `OPTIONS` like any request. At a used-up month, the preflight gets the 429/402 (with CORS, `limits.test.mjs:396`; probed as 429).
  - Browsers need a 2xx preflight, so every preflighted SPA call (writes with `X-CSRF-Token`, JSON bodies) still sees a network error, not the refusal.
  - Let `OPTIONS` under `/api` pass uncounted, so PHP answers 204, or answer 204 plus CORS at the edge. Fix before `LIMITS_ENABLED` is "1".
- **L-2202:** A failed recount has no back-off.
  - `counted` is set only on success (`installation.ts:144-153`). While listing fails, every write at full re-lists the whole prefix: on R2 errors, or for a prefix too large for one invocation's limits.
  - A tenant at full can repeat this, which costs Class A operations and slows requests.
  - Record the attempt time on failure too, and consider a page cap.
- **N-2203:** Test gaps from surviving mutants.
  - Pin "1e3", "+5" and " 50 " as untrusted (M05, M11).
  - Test that `/apix` gets no CORS (M21).
  - Assert the 24 h and 1 h values (M36, M37).
  - Test concurrent pre-507 recounts sharing one listing (M43).
  - Test that a pre-507 recount is saved (M45).
- **N-2204:** PHP never sees `X-Apotheke-Limits`. Even the edge's own copy is removed by `takeLimits` and `toContainer`, so the app's `max_photos` cap can't "rely on" that header. The app-side PR needs the `/limits` bridge (C1) or a new header that the edge sets after the strip.
- **N-2205:** Edge cases.
  - A body that doesn't match a valid `Content-Length` makes FixedLengthStream fail the forward, which throws (a Worker error, not 400). Cloudflare's framing should prevent this.
  - Two CORS parity differences: the edge doesn't map `/index.php/api` as `LegacyPathRouter` does, and JS `trim()` strips more whitespace than PHP's. Neither affects a browser origin.

0 High, 0 Medium, 2 Low and 3 Nit, none blocking. No new findings on this catch-up (next free id: 2206). Coder will put L-2201, L-2202, N-2203 and N-2205 in a follow-up PR off main after #321 merges, ahead of `LIMITS_ENABLED=1`. N-2204 is noted: the `max_photos` PR will use the `/limits` bridge, or a header the edge sets after the strip.

## Verdict

**PASS** at `bcb4bc8c`, 0 behind main `d19b0a20` (v3.46.0), confirmed by the merge-only read of this catch-up. Highs and Mediums block; none are open.

Not yet cleared to merge. This docs(qa) reissue must land byte-exact as the last commit, then the hash check and marker, and full CI on the final head must be green, including both required Tests jobs. If main moves before merge, the PR needs a plain catch-up (conflicts reported as conflicts), a merge-only read and a reissued doc.

Squash as "fix(edge): plan limits follow-ups before LIMITS_ENABLED is on" (61 characters, 68 with " (#321)"). Fix L-2201 before `LIMITS_ENABLED` is "1"; L-2202 and N-2203/N-2205 are in the same agreed follow-up.
