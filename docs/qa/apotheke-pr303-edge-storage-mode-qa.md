# QA: apotheke #303, per-installation edge storage

- PR: https://github.com/thordin9/apotheke/pull/303 (Coder), base `main`, ready. Operator console E3a: each container object takes its database object and R2 prefix from its own name, and a failed route lookup is retried after 5 s instead of on every request.
- Reviewed head: `c47abc69a1419f5a0294ffe17238af9cdb554748`. Parents `ac532388` (L-2142) and main `2b3e4b92d2f6b47232a958cc2a4fa1efc0494b35` (chore(release): v3.39.0, 01:51 Europe/Athens, UTC+2). That sits on a plain merge of main `1e7beb93` (#302 squash) at `a6892d4a` (parents `a370fe3d` and `1e7beb93`), then `ac532388` test(edge): pin the failed route lookup retry at 5 s (52 chars, 01:43 Europe/Athens, UTC+2). The head is 0 behind and 28 ahead of current main, mergeable_state clean. The PR title is now `feat(edge): per-installation storage from props` (47), which suits the squash. The stacked-diff note is gone from the body.
- Merge-only read plus the L-2142 delta. Per-file +/- of `2b3e4b92...c47abc69` equals what a merge of current main into the previous head `a370fe3d` would give, plus only the L-2142 test lines and the CHANGELOG. Total vs main: 13 files, +344/-84 (was +337/-84 against main `1e7beb93` at the content gate: +4 in `edge/tests/route.test.mjs`, +3 in `CHANGELOG.md` because v3.39.0 emptied `[Unreleased]`). The 5 non-test, non-CHANGELOG files, and every test file except `route.test.mjs`, are byte-identical to `a370fe3d`. `route.test.mjs` is +4/-0 versus `a370fe3d` (now +42/-5 vs main; was +38/-5). `edge/wrangler.jsonc` and `edge/storage.jsonc` are unchanged vs main.
- CHANGELOG: `[Unreleased]` holds only `### Added` with the #303 bullet, linking the full PR URL, the same bullet as at `a370fe3d`. The header (478 bytes) and everything from `## [3.39.0]` down (188388 bytes) are byte-identical to main `2b3e4b92`. Coder's note that a conflict-free merge first placed the bullet inside `[3.39.0]` is consistent with the merge commit's CHANGELOG hunk; the pushed tree is correct.
- Earlier content review: PASS on content at `a370fe3d` (this doc's previous sha256 `67ce8011…`). That version is void.
- Gate: merge-only read plus L-2142 delta. Reviewer: Apotheke QA 2

## Checklist

- [x] CI on `c47abc69` is green: 23 check runs, 22 success and 1 skipped (Deploy apotheke-api, as on PRs). Runs: CI 43333367423 (includes Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql)), Deploy edge 43333367356 (Check and dry run; deploy skipped), PR #303 / CodeQL 43333361915.
- [x] Local suite (node 24.22.1): `node --test --experimental-strip-types edge/tests/r2.test.mjs` (which registers the `cloudflare:workers` stub and loads the other edge tests) passes 104/104. The Deploy edge command, `node --test --experimental-strip-types scripts/deploy-scripts.test.mjs edge/tests/r2.test.mjs`, passes 137/137 (33 deploy-scripts + 104 edge). The content-gate figure of 136 was that same Deploy edge command at `a370fe3d` (33 + 103), before the L-2142 pin test.
- [x] Commit subjects: non-merge Conventional Commits are at most 72 chars. Merges use git's default subject and are exempt. The squash uses the PR title. The longest of #303's own remains 65; the new L-2142 subject is 52.
- [x] L-2142: `edge/tests/route.test.mjs` now asserts `assert.equal(FAILURE_TTL_MS, 5_000)` in `E3a: a failed lookup is retried after exactly 5 s, as documented`. Mutating `FAILURE_TTL_MS` to `60_000` or `4_000` fails that test (`60000 !== 5000`, `4000 !== 5000`) and is then reverted.
- [x] N-2145: the title no longer ends with "(depends on #302)", the body's stacked-diff note is gone, and the PR is marked ready.
- [x] No secrets or ids (counts only). The diff and the PR body contain none.

## Findings

- **L-2142:** closed.
- **N-2145:** closed.
- **N-2143:** every lookup error is cached for 5 s, not only a missing table. Accepted: the result is `primary` either way, it lasts at most 5 s, it spares D1 and the logs during an outage, and it is documented. Hardening: on a transient error, keep serving an expired positive entry (last known good) instead of `primary`. Optional; can go in a follow-up.
- **N-2144:** in-flight lookups are not coalesced. Concurrent misses for one host in a cold isolate each query D1 and each log, so "one line per host per 5 s" holds only for sequential requests. The extra lines are bounded by concurrency. Hardening: share the pending lookup per key. Optional; can go in a follow-up.

0 High, 0 Medium, 0 Low, 2 Nits.

## Verdict

**PASS** at `c47abc69`, 0 behind main `2b3e4b92` (v3.39.0). L-2142 and N-2145 are closed. N-2143 and N-2144 can go in a follow-up. The squash subject is the PR title. Commit this doc last as a docs(qa) commit, byte-exact. The earlier version of this doc (sha256 `67ce8011…` at `a370fe3d`) is void.
