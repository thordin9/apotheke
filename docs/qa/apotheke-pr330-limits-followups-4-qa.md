# QA: apotheke #330, plan limits follow-ups 4 (N-2210, N-2211)

- PR: https://github.com/thordin9/apotheke/pull/330 (Coder), base `main`. Test-only follow-up to #325 for N-2210 and N-2211. `LIMITS_ENABLED` and edge behaviour are unchanged.
- Reviewed head: `d1dee9134284dc02f0ba6e9c12eed87b4d02db9f`, a plain merge with parents exactly `53c2b8ca` (the prior head with the doc commit on `2e18abb0`) and main `9abfcc00` (v3.49.1). 0 behind, 3 ahead; merge base is `9abfcc00`. `mergeable_state` clean, not a draft.
- Gate: content was gated at `2e18abb0` (PASS: edge suite 225/225, the `decodeURIComponent` mutant, N-2211 won't-fix, commits and a secret scan). This round is a light merge-only read only: no suites or mutants.
- CI on `d1dee913`, all green: CI 44455048146 (all 18 jobs, including the required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql)), Deploy edge 44455048139 (Check and dry run green; Deploy apotheke-api skipped), and CodeQL 44455041633 (Analyze actions and javascript-typescript, and the CodeQL check). 0 open code-scanning alerts on the PR ref.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only read: parents are exactly `53c2b8ca` and `9abfcc00`. `behind_by` is 0. `git merge-tree --write-tree` of those parents has no conflicts and equals the head tree `acbac413`. `53c2b8ca...d1dee913` brings in only main's files (#328 and the v3.49.1 release). `limits.test.mjs` auto-merged: main changed a helper at line 68 (`DB: {}` → `DB: controlDb()`), which does not overlap the N-2210 lines. The `main...head` numstat is `edge/tests/limits.test.mjs` +3/−3 plus `docs/qa/apotheke-pr330-limits-followups-4-qa.md` +37. The `limits.test.mjs` diff against main equals the original `2e18abb0` PR diff (the same 3 lines; only the index hashes differ). CHANGELOG and VERSION are byte-identical to main (3.49.1).
- [x] Scope vs main: 2 files, +40/−3. N-2210 paths `/%61pi/%FF` and `/%61pi/%zz` remain on the preflight list (`limits.test.mjs:551`) and the CORS list (`:655`); the test title at `:652` cites N-2210. Line numbers are unchanged after the auto-merge.
- [x] Prior content gate (unchanged): the `decodeURIComponent` rewrite of `appPath()` (raw path on error) failed both new cases (used-up preflight 429, CORS dropped). N-2211 is won't-fix in the PR body and accepted: released 3.48.1 stays byte-identical; `docs/releasing.md` has no errata process. No CHANGELOG bullet, matching test-only precedent (#316, #310, #276, #273).
- [x] Commits: `2e18abb0` "test(edge): cover encoded /api paths with a bad percent escape" is Conventional, 62 characters, 69 with " (#330)". `53c2b8ca` is "docs(qa): add QA 2 review for #330". The merge `d1dee913` is "chore: merge origin/main into cursor/limits-followups-4" (55 characters), with an empty body and no declared conflicts.
- [x] Secret scan: CI Secret scan is green on this head. The prior local scan of the 3 added test lines found 0 secrets.

## Prior findings

- **N-2210 closed.** The two paths kill the `decodeURIComponent` rewrite that previously survived the suite (M15 in #325).
- **N-2211 won't-fix, accepted.** A released changelog section is not rewritten; there is no errata process.

N-2294 (deferred by Coder until after #327, because `autofill.ts` is not on main yet) is out of scope here.

## New findings

None. 0 High, 0 Medium, 0 Low and 0 Nit (next free id: 2320).

## Verdict

**PASS** at `d1dee913`, 0 behind main `9abfcc00` (v3.49.1). The merge-only read passes. Highs and Mediums block; none are open.

Not yet cleared to merge. This doc must land byte-exact as the last commit ("docs(qa): add QA 2 review for #330"), then the hash check and marker, and full CI on the final head must be green, including both required Tests jobs. If main moves first, the PR needs a plain catch-up, a merge-only read and a reissued doc.

Squash as "test(edge): cover encoded /api paths with a bad percent escape" (62 characters, 69 with " (#330)"). The PR title is the same, so it stays within 72.
