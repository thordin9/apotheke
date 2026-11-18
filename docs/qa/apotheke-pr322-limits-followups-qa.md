# QA: apotheke #322, plan limits follow-ups 2 (after #321)

- PR: https://github.com/thordin9/apotheke/pull/322 (Coder), base `main`. Follow-ups to #321 for L-2201, L-2202, N-2203 and N-2205, plus N-2198, N-2200 and N-2204 (docs and tests). `LIMITS_ENABLED` stays "0"; no new vars.
- Reviewed head: `a369cfda4be5d7d43f2739e1d50a58ef6924f404`. 0 behind main `33ca7c49` (v3.46.1), 9 ahead; merge base is `33ca7c49`. `mergeable_state` clean.
- Gate: full (security-relevant edge code). Diff read, the edge suite as CI runs it, `tsc`, `wrangler types --check`, `check-links`, 27 mutants, migration mutants for N-2200, probes against the real PHP app (php -S, SQLite), docs, commits and a secret scan. No PHP files are touched, so no local PHP suites and no Cloudflare-phase note.
- CI on `a369cfda`, all green: CI 44383158804 (all 18 jobs, including the required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql)), Deploy edge 44383158766 (Check and dry run green; Deploy apotheke-api skipped), and CodeQL 44382473618 (Analyze actions and javascript-typescript, and the CodeQL check). 0 open code-scanning alerts on the PR ref; the slow-regex alert at `ec47c6b0` is gone after `0d3c7cc5`.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main: 7 files, +316/−31: `edge/src/{index,installation,limits}.ts`, `edge/tests/{limits,control-migrations}.test.mjs`, `docs/operator-console.md` and CHANGELOG. `parseLimits` and every export #317 imports are unchanged.
- [x] Last merge `a369cfda` (parents `2fc8bdce` and `33ca7c49`; main gained only the v3.46.1 release). Git's auto-merge put the #322 bullet into the released [3.46.1] section; the head moves it back to [Unreleased]. That is correct, but the merge message doesn't declare it. Otherwise the head tree equals `git merge-tree`.
- [x] L-2201: `isPreflight` is `OPTIONS` (the runtime normalises the case) and a path of `/api` or `/api/…`. `admit()` never counts a preflight, and answers it 204 instead of 402/429, before waking the container. `withCors` adds the preflight headers (Allow-Methods, Allow-Headers, Max-Age), the same set PHP's `Cors` sends. A forwarded preflight is metered by `activity()`.
- [x] L-2202: a failed listing sets `failed = started` and returns true, so it is saved (`changed()` before a 507, `flush(true)` in the alarm). `lastRecount` is max(counted, failed). Both waits run from it.
- [x] N-2205: a valid length is at most the cap, so a longer body hits the mismatch branch before the cap. A shorter body fails in `flush`. Both give a 400 with CORS. `phpTrim` strips exactly `" \t\n\r\0\x0B"` with two bounded index loops (linear, no regex). The remaining `corsNormalise` regex is anchored with one quantified class (linear) and only sees validated origins.
- [x] Edge suite: 205/205 (Node 24, `node --test --experimental-strip-types scripts/deploy-scripts.test.mjs edge/tests/r2.test.mjs`). `tsc --noEmit` and `wrangler types --check` are clean. `check-links` reports 390 links and 0 broken.
- [x] Mutants: 27 run, 24 killed and 3 surviving. Surviving: M11 (a failed recount returns false, so it isn't saved), M19 (mismatch ignored when the forward resolves) and M27 (`toUpperCase` dropped; equivalent, since fetch normalises `options`). Killed: M01 to M10, M12 to M18 and M20 to M26. These cover `isPreflight` and `isApiPath`, the 204, no-count and metering branches, the index.ts flag, `lastRecount`, `failed`, both waits, the longer and shorter checks, 400 vs 413, CORS on the 400, the trim set and loops, and the exact thresholds.
- [x] N-2200: the two new tests fail against a scratch 0004 mutated to drop `'photos'` ("keeps every key") and to add `ON DELETE CASCADE` ("every reference is NO ACTION"). N-2198 and N-2204 are docs only; 0001 to 0004 are unchanged and sha-pinned.
- [x] CHANGELOG: one `### Fixed` bullet under [Unreleased] linking https://github.com/thordin9/apotheke/pull/322. The header and everything from `## [3.46.1]` down are byte-identical to main. VERSION equals main (3.46.1).
- [x] Commits: the three new subjects (62, 32 and 53 characters) are Conventional. The merges are exempt, and the #321-era commits are already in main through its squash.
- [x] Secret scan of the 294 added lines: 0 secrets.

## Prior findings

- **L-2201 closed**, with the residual in L-2206. **L-2202 closed.** **N-2203 closed**: M05, M11, M21, M36, M37, M43 and M45 are pinned. New gaps are in N-2209.
- **N-2205 closed** for limited installations. With no `import_file_bytes` or limits off, nothing changes, which is still fine behind Cloudflare's framing. **N-2198, N-2200 and N-2204 closed.**

## New findings

- **L-2206:** `requests_month` no longer bounds `OPTIONS` traffic.
  - Any `OPTIONS` under `/api` is exempt, not only a real preflight: no `Origin` or `Access-Control-Request-Method` is needed.
  - Until the month is used up, each one wakes the container and is never counted, so an `OPTIONS`-only flood never reaches the request cap. Only `container_hours_month` bounds it, and no plan has rows yet.
  - Before `LIMITS_ENABLED` is "1", do one of these:
    - Answer every `/api` `OPTIONS` at the edge with `withCors`. PHP's preflight is static, so it never needs the container.
    - Count preflights without refusing them.
    - Require `container_hours_month` on any plan that sets `requests_month`.
- **N-2207:** `isPreflight` is not exactly `ApiFilter::before`, because PHP uses `rawurldecode(parse_url(REQUEST_URI))`. Probed with php -S:
  - `OPTIONS /%61pi/v1/items` gets 204 with CORS from PHP, but the edge counts and refuses it. That direction is safe. "PHP gives no CORS on /%61pi" holds for GET (404, no CORS) but not for `OPTIONS`.
  - `OPTIONS /api/a:1` (`parse_url` fails, giving `/`) is an edge preflight, but PHP answers 405. It is uncounted and does no app work; covered by L-2206.
- **N-2208 (pre-existing, PHP, out of scope):** a path `parse_url` can't parse (a `:digits` segment, e.g. `/stats:1` or `/api/a:1`) is routed as `/`. It serves the home page with a 200, not legacy's 404, and an `/api/…` spelling skips the API filter. This needs its own issue.
- **N-2209:** Test gaps and doc wording.
  - M11: "the failure is saved with the counter" is untested.
  - M19: a forward that resolves before the mismatch is seen returns the app's answer, not the 400.
  - "never reaches the app" is too strong: the app can start on a partial body before the stream aborts (as with the over-cap case).
- Stated risks accepted: the edge 204 lacks PHP's other API security headers (empty body), and a failed alarm recount waits a day (writes before a 507 still retry hourly).

0 High, 0 Medium, 1 Low and 3 Nit, none blocking (next free id: 2210).

## Verdict

**PASS** at `a369cfda`, 0 behind main `33ca7c49` (v3.46.1). Highs and Mediums block; none are open.

Not yet cleared to merge. This doc must land byte-exact as the last commit ("docs(qa): add QA 2 review for #322"), then the hash check and marker, and full CI on the final head must be green, including both required Tests jobs. If main moves first, the PR needs a plain catch-up, a merge-only read and a reissued doc.

Squash as "fix(edge): preflights, recount back-off and Content-Length 400" (62 characters, 69 with " (#322)"). Fix L-2206 before `LIMITS_ENABLED` is "1".
