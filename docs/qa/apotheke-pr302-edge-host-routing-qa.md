# QA: apotheke #302, route requests by host

- PR: https://github.com/thordin9/apotheke/pull/302 (Coder), base `main`. Operator console E2: host routing over the #299 control-plane schema. Fixes L-2104, L-2105, N-2106 and N-2107 from the `08c2657a` gate.
- Reviewed head: `58bb4496a9462d26fc4dc51d610d78dd4203f573`. Plain merge of main `56c530b9adf0fd50df154b17f1b0a4a4e284e3df` (chore(release): v3.38.2, 00:55 Europe/Athens, UTC+2) with parents `71ff7613` and `56c530b9`. The only conflict was `CHANGELOG.md`. The head is 0 behind and 17 ahead of current main, mergeable_state clean. The PR title (34) suits the squash.
- Merge-only read, light gate (no suites or mutants). Per-file +/- of `56c530b9...58bb4496` equals `9e4a0ee7...71ff7613` (the PR diff at the previous head vs its merge-base then) for all 7 files: `CHANGELOG.md` +4/-0, `docs/operator-console.md` +22/-4, `edge/src/index.ts` +5/-3, `edge/src/route.ts` +84/-0, `edge/tests/control-migrations.test.mjs` +1/-1, `edge/tests/r2.test.mjs` +1/-0, `edge/tests/route.test.mjs` +152/-0. The 6 non-CHANGELOG files are byte-identical to `71ff7613`. Total vs main: 7 files, +269/-8.
- CHANGELOG: `[Unreleased]` holds only the #302 `### Added` bullet, linking the full PR URL, the same bullet as at `71ff7613`. The header and everything from `## [3.38.2]` down (187951 bytes) are byte-identical to main `56c530b9`. The #294 bullets that sat under [Unreleased] at `71ff7613` are already released on main as `[3.38.1]`.
- Earlier content review: PASS at `71ff7613` (this doc's previous sha256 `059ee96f…`). `71ff7613` fix(edge): cap the route cache and strip all trailing host dots (63 chars) is unchanged in this catch-up. That version of this doc, and the earlier `59fa777b…` (`08c2657a`), are void.
- Gate: merge-only read. Reviewer: Apotheke QA 2

## Checklist

- [x] CI on `58bb4496` is green: 23 check runs, 22 success and 1 skipped (Deploy apotheke-api, as on PRs). Runs: CI 43324649839 (includes Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql)), Deploy edge 43324649835 (Check and dry run; deploy skipped), PR #302 / CodeQL 43324642826.
- [x] Commit subjects: non-merge Conventional Commits are at most 72 chars. Merges use git's default subject and are exempt. The squash uses the PR title.
- [x] L-2104 (unchanged since `71ff7613`): the isolate cache is capped at 1000 (`ROUTE_CACHE_MAX`). `remember` deletes the key first (so a refresh after expiry is re-inserted as newest; Map insertion order is the oldest). At cap it drops expired entries, then the insertion-oldest keys, then `set`s. The test fills the cap, checks the oldest is evicted, then advances past TTL and checks all expired go and nothing newer. 4 mutants of cap, eviction order, expired-first and the trailing-dot loop were all killed.
- [x] L-2105: the intro says the Worker routes by host through this schema and that the console is not built yet (`docs/operator-console.md:4–5`).
- [x] N-2106: `normalizeHost` uses `\.+$` (port first, then all trailing dots). `b.example.test..` and `B.Example.Test..:443` are tested. A host of only dots, or empty after strip (`''`, `...`, `...:443`), is a miss and falls back to `primary`. A suspended host with extra trailing dots is gated (402), not forwarded to `primary`.
- [x] N-2107: the Routing section says a status or route change can take up to 30 seconds to show, names the 1,000-host cap, and that only the request URL host is used (`X-Forwarded-Host` is ignored). Tests assert Retry-After, Content-Type, body non-leak, and that a spoofed `X-Forwarded-Host` / `Host` still routes on the URL host.
- [x] No secrets. `edge/wrangler.jsonc` and `edge/storage.jsonc` are unchanged.

## Findings

- **L-2104, L-2105, N-2106, N-2107:** closed.
- **N-2126:** at cap, `remember` walks the whole map (at most 1000) to drop expired entries, then the insertion-oldest keys (`edge/src/route.ts:22–28`). That walk is bounded and runs only after a D1 miss, not on a live hit. Live hits do not re-insert, so a hot host cached first can be evicted before a one-shot unique Host. Hardening: bump the key on hit (LRU), or evict without a full scan.

0 High, 0 Medium, 0 Low, 1 Nit.

## Verdict

**PASS** at `58bb4496`, 0 behind main `56c530b9` (v3.38.2). N-2126 can go in a follow-up. The squash subject is the PR title. Commit this doc last as a docs(qa) commit, byte-exact. The earlier versions of this doc (sha256 `059ee96f…` at `71ff7613`, and `59fa777b…`) are void.
