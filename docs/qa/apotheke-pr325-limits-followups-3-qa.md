# QA: apotheke #325, plan limits follow-ups 3 (after #322)

- PR: https://github.com/thordin9/apotheke/pull/325 (Coder), base `main`. Follow-ups to #322 for L-2206, N-2207 and N-2209. `LIMITS_ENABLED` stays "0"; no new vars.
- Reviewed head: `ecf4475a9b36a7dbe194745eba617a4045068c79`. 0 behind main `184d4553` (v3.48.0), 2 ahead; merge base is `184d4553`. `mergeable_state` clean. No merge commits.
- Gate: full (security-relevant edge code). Diff read, the edge suite as CI runs it, `tsc`, `wrangler types --check`, `check-links`, `check-commits`, 23 mutants, a path differential against PHP's own `legacyPath()` logic, probes against the real PHP app (php -S, SQLite), docs, commits and a secret scan. No PHP files are touched, so no local PHP suites.
- CI on `ecf4475a`, all green: CI 44428937917 (all jobs, including the required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql)), Deploy edge 44428937699 (Check and dry run green; Deploy apotheke-api skipped), and CodeQL 44428930626 (Analyze actions and javascript-typescript, and the CodeQL check). 0 open code-scanning alerts on the PR ref.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main: 5 files, +150/−47: `edge/src/{index,limits}.ts`, `edge/tests/limits.test.mjs`, `docs/operator-console.md` and CHANGELOG. `installation.ts`, `parseLimits` and every export #317 imports are unchanged.
- [x] L-2206: `admit()` now always runs `u.requests += 1` for an admitted request. `isPreflight` needs `OPTIONS`, `Access-Control-Request-Method`, an `/api` path by `appPath()` and an Origin `corsAllowed()` accepts; index.ts passes `CORS_ALLOWED_ORIGINS`. In a used-up month a real preflight gets an empty 204 with CORS before the count, `activity()` and `changed()`, so it is uncounted, no alarm is set and the container isn't woken. Every other request, `OPTIONS` included, is counted and refused like a `GET`. `withCors` and `isPreflight` share `corsOrigins()`/`corsAllowed()`.
- [x] Probes of `isPreflight`: GET, POST, HEAD and PUT with preflight headers are never preflights. A lower- or mixed-case method normalises. With an empty, `" , "`, `*`, `null` or http-only list, nothing is a preflight. Origin `null`, a two-origin list, an http origin and an unlisted origin are rejected. Case, a trailing slash, PHP-trim whitespace and a trailing dot are accepted, and the echoed value matches PHP for each. A forged header set gets nothing a real browser doesn't: counted until the month is used up, then an empty edge 204 with no container wake.
- [x] N-2207: `appPath()` decodes `%XX` once, byte-wise, with either hex case, and never throws (`%`, `%zz`, `%FF` and `%00` are all tested). `'..'`, `%2e%2e` and `\` are resolved by the URL parser before the edge or the container sees them, and the container gets the same serialised URL (`toContainer`). Query strings are ignored. Differential over 9,170 generated paths, with the edge's `isApiPath(appPath())` against PHP 8.4 running `rawurldecode(parse_url())` on the URL the container receives: 8,821 agree. All 349 that differ fall in the two documented classes: a `:` plus 1 to 5 digits segment (edge: `/api`, PHP: `/`) and a leading `//` (edge: not `/api`). Real app (php -S): `OPTIONS` on `/%61pi`, `/%61%70%69`, `/api%2F…`, `/api/%3A1`, `/api/%00` and `/api/%FF%fe` gives 204 with CORS. `/%2561pi`, `/%41PI`, `/API`, `/apix`, `/index.php/api`, `/api%` and `/api%zz` give 404 with no CORS. `/api/a:1` gives 405. `//h/api/v1` gives 204. The edge matches each one apart from those two documented cases.
- [x] N-2209: the M11 test (`limits.test.mjs:728`) and the M19 test (`:754`) kill #322's two survivors (M21 and M22 below). operator-console.md no longer says such a body never reaches the app.
- [x] Edge suite: 217/217 on Node 22.23.5 (as Deploy edge runs it) and on Node 24.22.1 (`node --test --experimental-strip-types scripts/deploy-scripts.test.mjs edge/tests/r2.test.mjs`). `tsc --noEmit` and `wrangler types --check` are clean on both. `check-links` reports 394 links and 0 broken.
- [x] Mutants: 23 run, 21 killed and 2 surviving.
  - Killed: M01 to M14 and M16 to M22. These cover each `isPreflight` condition, the raw path in place of `appPath`, index.ts dropping the origins, the restored `!preflight` guard, a refused, counted, alarm-setting or non-empty used-up 204, no decode, double decode, lower-case-only hex, `%2F` left encoded, `withCors` on the raw path, the trailing-dot `corsNormalise`, http origins allowed, any origin allowed, and #322's M11 and M19.
  - Surviving: M15 (`decodeURIComponent`, with the raw path on an error; see N-2210) and M23 (an empty `Access-Control-Request-Method` no longer counts; near-equivalent, since PHP doesn't check that header).
- [x] CHANGELOG: one `### Fixed` bullet under [Unreleased] linking https://github.com/thordin9/apotheke/pull/325. The header and everything from `## [3.48.0]` down are byte-identical to main. VERSION equals main.
- [x] Commits: `cdf90f62` (66 characters) and `ecf4475a` (55 characters) are Conventional; `check-commits` is ok.
- [x] Secret scan of the 150 added lines: 0 secrets.

## Prior findings

- **L-2206 closed.** Every `OPTIONS` is counted. Only a real preflight from an allowed origin escapes refusal, and only as an empty edge 204.
- **N-2207 closed.** The decode matches `rawurldecode()`. The only `parse_url()` differences left are the two documented ones; both are counted, and the `//` case is the stricter direction.
- **N-2209 closed.** Both test gaps are pinned and the wording is fixed.
- **N-2208 still open** (pre-existing PHP routing; the PM's separate issue).

## New findings

- **N-2210:** Test gap. No test has an escape that is only `/api` after decoding together with a bad or non-UTF-8 escape (e.g. `/%61pi/%FF` or `/%61pi/v1%zz`). So a `decodeURIComponent` rewrite that falls back to the raw path (M15) passes the suite, even though it would refuse such a preflight and drop CORS from its refusal. Add one such path to the decode tests.
- **N-2211:** The CHANGELOG bullet's "and a failed stored-bytes recount is saved" describes #322's behaviour; #325 only adds the test for it, since `installation.ts` is unchanged. The clause can go in a later CHANGELOG edit.
- Stated risks accepted: a preflight now uses request budget (an uncached cross-origin call counts twice; Max-Age 600). Forged preflight headers are counted until the month is used up, then edge-answered with an empty 204 for free (DO only, like any refusal). An encoded `/api` 404 has no CORS from the app, but the edge refusal does. The edge mirrors the app's CORS rules, so they can drift.

0 High, 0 Medium, 0 Low and 2 Nit, none blocking (next free id: 2212).

## Verdict

**PASS** at `ecf4475a`, 0 behind main `184d4553` (v3.48.0). Highs and Mediums block; none are open.

Not yet cleared to merge. This doc must land byte-exact as the last commit ("docs(qa): add QA 2 review for #325"), then the hash check and marker, and full CI on the final head must be green, including both required Tests jobs. If main moves first, the PR needs a plain catch-up, a merge-only read and a reissued doc.

Squash as "fix(edge): count every OPTIONS, read /api paths as the app does" (63 characters, 70 with " (#325)"). The PR title is 66, so 73 with the suffix, which is over 72.
