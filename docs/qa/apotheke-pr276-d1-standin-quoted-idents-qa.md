# QA: apotheke #276, mask quoted identifiers in the D1 stand-in

- PR: https://github.com/thordin9/apotheke/pull/276 (Coder), closes N-1898 from the #273 gate. Was stacked on #273's `b439302e`; #273 has since merged as `e119ce33`.
- Reviewed head: `a93d348fcf4af38baf7d297f78888cb5296ed462` (plain merge of main `37afbdd7` into `4ba43498`)
- Merge-only read: `207347f45af79fefe07143ab093924e2bcd6597e` (plain merge of main `f4667cf2` (#272) into `6afb5658`, after the reads at `3a132b42` and `dac60082`; #272 touches no file in this PR). 0 behind main; the PR's diff against main is still exactly `4ba43498`'s +/- lines plus this doc. CI, including D1 (local workerd) and Visual E2E, must be green on the final head before merging.
- Gate: light (test-only diff read, CI, regex probe; no suites)
- Reviewer: Apotheke QA 2

## Checklist

- [x] The new commit `4ba43498` is test-only: `tests/Modules/Migrations/D1SqliteStandIn.php` (+7/−1 on top of #273) and `D1MigrationRunnerTest.php` (+23). No production code. The PR also shows #273's `b439302e`, already gated. 0 behind main.
- [x] `refuseInternal()` now makes one left-to-right pass. `'…'` literals (with `''`), `--` and `/* */` comments are blanked, while `"…"`, `` `…` `` and `[…]` names are kept, so a `'`, `--` or `/*` inside a quoted name can no longer hide a later `_cf_` name. An independent probe of the same regexes refuses both N-1898 examples, quoted and bracketed `_cf_` names, `"a""b"` names and a name after an escaped `''` literal. It accepts `_cf_` in literals and comments, after a `"a''"` name, and in `items_cf_extra`.
- [x] Tests: 10 new refused cases (each quote form with `'`, `--` and `/*`, plus quoted `_cf_` names after them, across `query` and `exec`) and `testStandInAcceptsCfLiteralsAfterQuotedNames`. These are the author's figures, not re-run because the box has no PHP: 9 of the 10 fail on the old code, 12/12 mutants are killed, and local D1 refuses every new refused case.
- [x] The commit subject is a valid Conventional Commit. No CHANGELOG line (test-only; expected). The known leftovers (`split()` not handling `[…]` and `/* */`, the unquoted `AS _cf_alias` false positive) are stated in the PR and out of scope.
- [ ] CI at this head was running when reviewed. It must be green, including Visual E2E, before merging.

## Findings

None. 0 High, 0 Medium, 0 Low, 0 Nit. N-1898 is closed.

## Verdict

**PASS** at `a93d348f`, confirmed by the merge-only read at `207347f4`. Squash-merge with the PR title as the subject.
