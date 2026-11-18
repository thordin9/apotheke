# QA: apotheke #313, wrangler 4.157.0 → 4.160.0 (/edge) plus regenerated worker types

- PR: https://github.com/thordin9/apotheke/pull/313 (Dependabot, with a main merge and one types commit from DevOps)
- Reviewed head: `bd005dc3694568fa7bf88848b89fccde1023ba9f`, parent `93c07a6c` (types regen). Merge `93c07a6c` parents: Dependabot `80151df2` and main `85c6ad8c` (plain --no-ff, v3.43.0). 0 behind main `85c6ad8c58144cda2a1098a142f847fc5fb4af31`, 3 ahead, mergeable (clean).
- History: 3 commits. Non-merge subjects: `ci: bump wrangler from 4.157.0 to 4.160.0 in /edge in the edge group` (68), `build(deps): regenerate worker types for wrangler 4.160.0` (57). Merge exempt (69). PR title is the `ci:` subject (68).
- Gate: light (dependency bump + merge: diff read, CI, merge-tree; no mutants, no Docker/PG suites, no image rebuilds).
- CI on `bd005dc3`: CI 43445514816, Deploy edge 43445514821 (dry run) and Deploy frontend 43445514924 all succeeded. 25 check runs: 23 success incl. Tests (PHP 8.4, sqlite) and (PHP 8.4, pgsql), 2 skipped (Deploy apotheke-api, Deploy getapotheke-app, PR event).
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope: 4 files, all under `edge/`: `package.json` +1/−1, `package-lock.json` +44/−36, `worker-configuration.d.ts` +89/−70 and `app/worker-configuration.d.ts` +89/−70. No app, worker, workflow or wrangler.jsonc changes.
- [x] `package.json`: only `wrangler` 4.157.0 → 4.160.0 (exact pin, no range). `typescript` stays 7.0.2. `testWranglerIsPinnedExactly` covers package.json, the lock root and `node_modules/wrangler`, and the workflows; no workflow edit was needed.
- [x] Lockfile: wrangler, workerd 1.20261110.1 (plus 5 platform binaries) and miniflare 5.20261110.0-beta move together. No added or removed packages. sha512 integrities of wrangler, workerd, workerd-linux-64 and miniflare match the npm registry.
- [x] Main merge `93c07a6c`: `git merge-tree` of `80151df2` and `85c6ad8c` is a clean tree identical to the merge commit (`e94c8673…`). No file overlap with main's `b58cc830..85c6ad8c` changes; package.json and the lock on the merge and the head are byte-identical to the Dependabot commit.
- [x] Regenerated types (`bd005dc3`, parent `93c07a6c`): workerd version header 1.20261103.1 → 1.20261110.1 (`compatibility_date` 2026-10-29 unchanged). Env binding blocks and config hashes are unchanged. Runtime type additions: `DurableObjectRetryOptions`, `OnHibernateEventInfo` / `TraceItemOnHibernateEventInfo`, `withActiveSpan`, rate-limit `delta`. `edge/wrangler.jsonc` and `edge/app/wrangler.jsonc` are byte-identical to main.
- [x] Local: after `npm ci --ignore-scripts` in a scratch `edge/` (locked wrangler 4.160.0), `npx --no-install wrangler types --check --config wrangler.jsonc` reports both `edge/worker-configuration.d.ts` and `edge/app/worker-configuration.d.ts` up to date.
- [x] CHANGELOG is identical to main. Per #243 (`docs/qa/apotheke-pr243-wrangler-4157-qa.md`): release.php generates the Maintenance entry from the squash subject; no hand-written bullet is needed.

## Findings

No findings.

0 High, 0 Medium, 0 Low, 0 Nit.

## Verdict

**PASS** at `bd005dc3`, 0 behind main `85c6ad8c`. Highs and Mediums block; none are open. Squash-merge with the PR title as the subject once the docs(qa) commit is on the branch and CI is green. If main moves before merge, the PR needs a plain catch-up (conflicts reported as conflicts), a merge-only read and a reissued doc.
