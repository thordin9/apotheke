# QA: apotheke #315, plan limits in the Worker (operator console E5)

- PR: https://github.com/thordin9/apotheke/pull/315 (Coder), base `main`. Operator console E5.
- Reviewed head: `bb207db0241dc99adffdcba50e25cbb9b2e98d81`, a plain merge of `8414bd71` and main `49dd44f953c00a986e9dda2e82d5b8206d30860e` (#314 squash). 0 behind, 6 ahead. Parents are `8414bd71` and `49dd44f9`.
- Prior review: **PASS on content** at `8414bd717979792d6cf95711c035ff2c110ce87d` (12 files, +665/−32; doc sha256 `1268a545…`). This reissue supersedes that doc.
- Gate: the full gate at `8414bd71` (diff, CI edge steps, probes, 31 mutants, a trial merge onto `49dd44f9`, docs, commits and a secret scan), plus this merge-only read of the catch-up.
- CI on `bb207db0`: Deploy edge 43466167034, Deploy frontend 43466167057 and CodeQL 43466154887 succeeded. CI 43466167040 was still running at this read (15 passed, 2 skipped, 8 in progress). The required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql) were in progress. Prior CI on `8414bd71` (CI 43460588773, Deploy edge 43460588858, Deploy frontend 43460588805, CodeQL 43460582978) was green, including both Tests jobs.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only read of `bb207db0`: parents are `8414bd71` and `49dd44f9`. behind_by is 0 against current main. `git merge-tree --write-tree` conflicts only on `CHANGELOG.md` (auto-merge tree `751bd356`); every other path in the head tree equals that auto-merge. The CHANGELOG resolution keeps both [Unreleased] ### Added bullets, #314 then #315; released sections are identical to main, and the only diff vs main is the #315 bullet. `gh api compare 8414bd71...bb207db0` brings in exactly the #314 squash files, including the CHANGELOG resolution.
- [x] Scope vs main: 12 files, +662/−32 (3 fewer than `8414bd71` vs its then-main, the shared CHANGELOG context). Of the non-CHANGELOG files, `limits.ts`, `r2.ts`, `route.ts`, `limits.test.mjs`, `worker-configuration.d.ts`, `wrangler.jsonc` and `deploy-scripts.test.mjs` are blob-identical to `8414bd71`. `docs/operator-console.md`, `index.ts`, `installation.ts` and `r2.test.mjs` equal the clean auto-merge; both sides' unique lines are kept (0 missing). Nothing dropped.
- [x] Full-gate findings at `8414bd71` are unchanged: switch off forwards the same Request; primary is exempt; texts match the docs; body cap and storage metering behave as before. No High or Medium.
- [x] Run as CI does (Node 24, `npm ci --ignore-scripts` in `edge/`): deploy-scripts + edge pass 180/180. `tsc --noEmit` passes.

## Findings

- **L-2171:** Limits headers aren't stripped unconditionally.
  - With the switch off, a client's `X-Apotheke-Limits` reaches PHP (`limits.ts:88`, pinned by `limits.test.mjs:111`).
  - `Apotheke-Limit-Warning` is never removed from requests or container responses (`limits.ts:194`).
  - Strip both in `toContainer` (`index.ts:252`) and on `out` before C1 trusts them.
- **L-2172:** The Content-Length branch trusts the header and isn't tested.
  - Node's `Request` sets no Content-Length, so the "by Content-Length" test runs the stream path, and M13 survives.
  - A lying or invalid value ("5" for 50 B, "abc", "") skips the stream cap (`limits.ts:60`).
  - Cloudflare's framing likely blocks this, but keep the stream cap in every case and add a real Content-Length test.
- **L-2173:** Refusals and the soft header don't reach the hosted SPA, which is cross-origin from `getapotheke.com`.
  - The Worker and DO 413, 429 and 402 responses carry no CORS headers (`limits.ts:39`).
  - PHP's `Access-Control-Expose-Headers` (`modules/Core/Http/Cors.php:59`) lacks `Apotheke-Limit-Warning`.
- **L-2174:** The stored-bytes counter can drift for good. Head, add and put aren't atomic (`r2.ts:405`), so concurrent writes to one key both count against the old size, and there's no reconciliation yet. This can cause false 507s. Docs mention only overshoot. Reconcile from a list before enabling, or document it.
- **L-2175:** Test gaps from surviving mutants.
  - M18: every change pushes the alarm out, which starves flushes.
  - M31: `flush` keeps dirty, so a second alarm is never set (`limits.ts:121-132`).
  - M22: a multipart complete over an existing key counts the full size (`r2.ts:389`).
  - M24: no negative clamp (`installation.ts:105`).
  - M02: `"true"` counts as on.
- **N-2176:** Docs omit four behaviours:
  - up to 60 s of counts lost on eviction (Coder's own risk);
  - what a failed lookup does;
  - that `import_file_bytes` caps every request body and that CORS preflights count as requests;
  - that a 507 shows in PHP as a generic storage failure (`R2Bridge.php:67`) until C1 maps `storage_full`.
- **N-2177:** Active-time edge cases (`limits.ts:156-171`).
  - A crash or `destroy()` still counts until `lastActivity + SLEEP_AFTER_MS`.
  - One request longer than `SLEEP_AFTER_MS` is undercounted.
  - A run across UTC month start puts up to `SLEEP_AFTER_MS` of the old month into the new one.
- **N-2178:** Storage write edge cases.
  - `part` uploads aren't reserved (`r2.ts:374`): parts of an upload that's never completed sit beyond `storage_bytes` until the 24 h sweep. The declared `size` could be reserved.
  - A same-size overwrite at exactly full gets a 507 (`installation.ts:104`).
- **N-2179:** Post-#314, `wireStorage` (`index.ts:79`) rewires the outbound props on every fetch.
  - A future internal caller without `X-Apotheke-Limits`, such as C2's backup export, would turn metering off for concurrent tenant writes.
  - A purge's `deleteAll` keeps the in-memory counters. That's harmless after the 30-day retention.

0 High, 0 Medium, 5 Low and 4 Nit, all open, none blocking.

## Verdict

**PASS** at `bb207db0241dc99adffdcba50e25cbb9b2e98d81`, 0 behind main `49dd44f953c00a986e9dda2e82d5b8206d30860e` (#314), confirmed by the merge-only read, once CI 43466167040 on that head is green (both required Tests jobs included). Squash as "feat(edge): plan limits in the Worker (operator console E5)". Fix L-2171 and L-2173 before `LIMITS_ENABLED` is set to "1", and L-2174 before storage is enforced for existing tenants, in a follow-up PR. The other findings can follow in that PR. The earlier doc at `8414bd71` (sha256 `1268a545…`) is superseded and will be replaced by this reissue in a new docs(qa) commit.
