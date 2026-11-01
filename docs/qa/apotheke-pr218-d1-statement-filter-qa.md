# QA: PR 218, match D1 in the local batch statement filter

- PR: thordin9/apotheke#218, `fix/batch-filter-parity`, by Coder2. The follow-up to #216 (L-1643, N-1644 to N-1646).
- Head: `22cdf657527a29fb786a53bded4c98d588d74e88` (4 commits, no merge)
- Base: main `5e37a2c` (v3.15.2), 0 behind, mergeable `clean`. Diff: +305/-80 in 7 files; no migration.

## Verdict

**PASS.** No H or M findings; two L and three N.
- N-1644, N-1645 and N-1646 are fixed.
- L-1643 is fixed for every quoting form it named.
- Two other ways around the filter remain (L-1647, L-1648). As before, they affect only the local test model: D1 rejects these statements itself, and the PDO path does not use the filter.

## Checklist

| Check | Result |
|---|---|
| CI on the head | 20/20 success, including both `Tests` jobs and both `Upgrade and rollback` jobs |
| Local suites (PHP 8.4, own PG17 clusters) | sqlite 458 OK (6 skipped), twice. pgsql 458 OK (1 skipped) on the second run; the first run hit the flake in N-1651 |
| lint, deptrac, DI rule, `routes --check` | Clean |
| gitleaks 8.30.1, `main..22cdf65` | No leaks. No UUIDs or tokens |
| Merge sanity | Based directly on main, with no merge commit. CHANGELOG, VERSION, README, migrations, `docs/qa` and `.github` are equal to main |
| Full suites with every statement run through the filter and the bind check | The only refusals are `PRAGMA journal_mode` (not in D1's list) and savepoints. No bind-count disagreement with CI4. Triggers, `sqlite_sequence` writes and the documented PRAGMAs now pass |
| Probes (about 60 cases, both dialects) | See below |
| Worker mode | No static state: pure static helpers and constants |
| Targeted tokenizer mutants | 24 of 30 killed. The 6 survivors are 1 equivalent and 5 test gaps (N-1650) |

## Prior findings

- **L-1643, fixed as claimed.**
  - Both dialects: `''`, `""` and comments.
  - PostgreSQL: `E''` escapes (including a doubled backslash before the quote), dollar quotes with tags (a mismatched or unclosed tag is refused), and nested comments.
  - SQLite: non-nesting comments, `[..]` names (which end at the first `]`) and backtick names.
  - Refused as unreadable: an unclosed quote or comment, and a backtick on PostgreSQL.
  - `[sqlite_version]()` and the backtick form are refused.
  - `U&''` strings and `$1` parameters read correctly.
  - The refusal tests run on both databases, through both `run()` and `query()`.
- **N-1644, fixed.**
  - The 17 PRAGMAs match D1's documented list, and a PRAGMA takes only a plain value: `optimize(-1)`, extra tokens and a second statement are refused.
  - Writes to `sqlite_sequence` are allowed, while `sqlite_*()` calls stay refused.
  - A trigger body takes only DML or SELECT. A `COMMIT`, `PRAGMA` or `ATTACH` in the body is refused, as is any statement after `END`. `CASE … END` inside a body is fine.
  - The docblock, `database.md` and ADR-002 now describe the filter as a model of D1, which is accurate.
- **N-1645, fixed.** A `?` or `'` inside a `""` name is refused in both modes, and that mutant is killed.
- **N-1646, fixed.** Tests now cover an unclosed block comment, backticks (doubled and unclosed) and a `?` inside `"..."`.

## Security

- **Scope.** The filter belongs to `LocalBatchExecutor`, the local test model. Production does not use it, and D1 enforces its own statement rules.
- **Fail closed.** Unreadable input is refused. The bind check only adds refusals, before any SQL runs.
- **No new exposure.** Error messages name no values. No dependency or configuration change.

## Findings

**L-1647: a form feed before a refused keyword passes the filter.**
- The keyword checks run on PHP `trim()` output, and `trim()` does not strip a form feed. Both databases treat a form feed as whitespace.
- So a transaction statement (or an undocumented PRAGMA) that starts with one passes the filter and runs in the middle of a batch.
- On the local connection this breaks the batch's atomicity; on SQLite it can also leave the connection inside a transaction.
- Fix: match the start with `\s`, or trim form feeds too, and add a test.

**L-1648: PostgreSQL-only statements are not refused.**
- D1 cannot run `SET`, `DO`, `CALL`, `LOCK` or `COPY`, but the filter allows them on PostgreSQL.
- An earlier `SET` step that changes how plain literals are read lets a later step hide a second statement from the filter. That breaks atomicity locally, as in L-1643.
- Fix: refuse statements by an allow-list of leading keywords rather than a deny-list.

**N-1649: rules that are not D1's.**
- The trigger-body exception also applies on PostgreSQL, where trigger bodies of this form do not exist and `END` means `COMMIT`. It is harmless today, because such a statement fails to parse there. Make it SQLite-only.
- `PRAGMA optimize` also accepts a mask value, which D1's documentation does not list.

**N-1650: mutant survivors.**
- Test gaps:
  - the check that an `E` prefix is not the end of an identifier;
  - a dollar tag that starts with a digit;
  - a trigger whose last part is not `END`;
  - a comment between `PRAGMA` and its name;
  - a `[..]` name followed by a later `]`.
- Equivalent: dropping the doubled-quote handling, since adjacent literals read the same.

**N-1651: an existing flaky test, not caused by this PR.**
- `RestoreUploadsTest::testParallelCreatesLeaveOneUploadPerAccount` failed on PostgreSQL in 2 of about 30 runs on the head: once in a full suite under load, once on its own.
- It passed in all 12 runs on main. This PR does not touch backup code, so this looks like an existing timing sensitivity in that test.

## Mutants (30, both databases)

- **Killed (24).**
  - Tokenizer: E escapes, nesting on each side, `[..]`, backticks, the PostgreSQL backtick refusal, dollar quotes and their previous-character check.
  - Unreadable input: unclosed dollar quotes, comments, quotes and brackets.
  - Matching: a quoted name kept raw, the trailing `;`, the trigger body check, no triggers at all.
  - PRAGMA and functions: any PRAGMA allowed, any PRAGMA value allowed, `sqlite_sequence` refused, the quoted-function form, `optimize` missing from the list.
  - Binds and comments: the CI4 parity check, line comments, a literal emptied to nothing.
- **Survived (6).** See N-1650.
