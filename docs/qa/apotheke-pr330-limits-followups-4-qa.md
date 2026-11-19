# QA: apotheke #330, plan limits follow-ups 4 (N-2210, N-2211)

- PR: https://github.com/thordin9/apotheke/pull/330 (Coder), base `main`. Test-only follow-up to #325 for N-2210 and N-2211. `LIMITS_ENABLED` and edge behaviour are unchanged.
- Reviewed head: `2e18abb01b73cdd38a29c1e59df9c3de68b1d1c6`. 0 behind main `b3c81498` (v3.49.0), 1 ahead; merge base is `b3c81498`. `mergeable_state` clean. One commit, no merge commits. Parent is main.
- Gate: light (tests only). Diff read, the edge suite as Deploy edge runs it, the `decodeURIComponent` mutant from N-2210, N-2211's won't-fix and CHANGELOG precedent, commits and a secret scan. No production or PHP files are touched.
- CI on `2e18abb0`, all green: CI 44448238398 (all 18 jobs, including the required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql)), Deploy edge 44448238358 (Check and dry run green; Deploy apotheke-api skipped), and CodeQL 44448235120 (Analyze actions and javascript-typescript, and the CodeQL check). 0 open code-scanning alerts on the PR ref.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main: 1 file, +3/−3: `edge/tests/limits.test.mjs` only. CHANGELOG and VERSION are byte-identical to main (3.49.0).
- [x] N-2210: `/%61pi/%FF` and `/%61pi/%zz` are on the decode preflight list (`limits.test.mjs:551`) and the CORS list (`:655`). The test title at `:652` now cites N-2210. Those paths are `/api` only after a byte-wise decode, and each carries a bad or non-UTF-8 escape.
- [x] Edge suite: 225/225 on Node 22.23.3 (`node --test --experimental-strip-types scripts/deploy-scripts.test.mjs edge/tests/r2.test.mjs`, after `npm ci` in `edge/`).
- [x] Mutant: `appPath()` rewritten to `decodeURIComponent`, falling back to the raw path on error. Both new cases fail. For `/%61pi/%FF` and, with that path removed from the lists so the loop can report the second, `/%61pi/%zz`: the used-up preflight is 429, not 204, and `withCors` on a 429 drops CORS (`{}` instead of the allowed headers). The other 223 tests still pass (225 run, 2 fail). Restored afterwards; the suite is 225/225 again.
- [x] N-2211: the PR body acknowledges it as won't-fix. Released 3.48.1 stays byte-identical; `docs/releasing.md` has no errata process for a released section. That is acceptable. The 3.48.1 Fixed bullet still attributes "a failed stored-bytes recount is saved" to #325; #322 already shipped the behaviour and #325 only added the test.
- [x] No CHANGELOG bullet, as the PR is test-only. Matches precedent: #316, #310, #276 and #273 also shipped tests (and QA docs) with no CHANGELOG entry.
- [x] Commits: `2e18abb0` "test(edge): cover encoded /api paths with a bad percent escape" is Conventional, 62 characters, 69 with " (#330)".
- [x] Secret scan: CI gitleaks 8.30.1 scanned 1815 commits, ~19.23 MB, 0 leaks. Local scan of the 3 added lines: 0 secrets.

## Prior findings

- **N-2210 closed.** The two paths kill the `decodeURIComponent` rewrite that previously survived the suite (M15 in #325).
- **N-2211 won't-fix, accepted.** A released changelog section is not rewritten; there is no errata process.

N-2294 (deferred by Coder until after #327, because `autofill.ts` is not on main yet) is out of scope here.

## New findings

None. 0 High, 0 Medium, 0 Low and 0 Nit (next free id: 2320).

## Verdict

**PASS** at `2e18abb0`, 0 behind main `b3c81498` (v3.49.0). Highs and Mediums block; none are open.

Not yet cleared to merge. This doc must land byte-exact as the last commit ("docs(qa): add QA 2 review for #330"), then the hash check and marker, and full CI on the final head must be green, including both required Tests jobs. If main moves first, the PR needs a plain catch-up, a merge-only read and a reissued doc.

Squash as "test(edge): cover encoded /api paths with a bad percent escape" (62 characters, 69 with " (#330)"). The PR title is the same, so it stays within 72.
