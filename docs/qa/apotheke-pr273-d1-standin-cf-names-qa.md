# QA: apotheke #273, make the D1 stand-in refuse _cf_ names like D1

- PR: https://github.com/thordin9/apotheke/pull/273 (Coder), closes N-1850 from the #258 gate
- Reviewed head: `b439302e4b26304096014b1697e8cd813586802a`
- Merge-only read: `74b0a5df4727c811a72a4a30ffc4da9361d22be4` (plain merge of main `37afbdd7` (#262) into `8e8418b9`). 0 behind main; the PR's 2 files and their changes are unchanged. CI, including the three Visual E2E shards (blocked by the Launchpad outage), must be green on the final head before merging.
- Gate: light (test-only diff read, CI, regex probe; no suites)
- Reviewer: Apotheke QA 2

## Checklist

- [x] One commit, test-only: `tests/Modules/Migrations/D1SqliteStandIn.php` (+6/−2) and `tests/Modules/Migrations/D1MigrationRunnerTest.php` (+43). No production code. 0 behind main, `mergeable_state` clean.
- [x] CI at this head: 21/21 green, including Tests (PHP 8.4, sqlite and pgsql) and D1 (local workerd).
- [x] `refuseInternal()` now blanks `'…'` literals (with `''` escapes), `--` line comments and `/* */` block comments (an unterminated one too) before the case-insensitive `(?<![\w$])_cf_` match. An independent probe of the same two regexes refuses the bare, `"…"`, upper-case, `` `…` ``, `[…]` and after-a-literal forms, and accepts `_cf_` inside a literal, a `LIKE` pattern, either comment form and `items_cf_extra`. That matches the PR's local-D1 table.
- [x] Tests: `testStandInRefusesD1InternalNames` runs 10 cases across `query`, `run` (batch) and `exec`, and `testStandInAcceptsCfTextThatIsNotAName` covers the accepted forms, so the three N-1850 survivors are covered. The one remaining surviving mutant (making the D1Pdo prefix check case-insensitive) is equivalent, as stated.
- [x] The commit subject is a valid Conventional Commit. No CHANGELOG line (test-only; expected). The known gap (the stand-in refuses `AS _cf_alias`, which D1 accepts) is stated in the PR and unused.

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-1898 | Nit | Quoted identifiers are not masked before literals and comments are blanked, so a `'` or `--` inside a `"…"`, `` `…` `` or `[…]` name hides a later `_cf_` name. For example, `SELECT "a'" FROM _cf_M WHERE x='b'` and `SELECT "a--b" FROM _cf_M` are accepted by the stand-in. It only affects a test fake, and no current statement looks like this. Mask quoted identifiers in the same alternation (keeping their contents for the name match), or note the limit beside the alias gap. |

0 High, 0 Medium, 0 Low, 1 Nit.

## Verdict

**PASS** at `b439302e`, confirmed by the merge-only read at `74b0a5df`. Squash-merge with the PR title as the subject.
