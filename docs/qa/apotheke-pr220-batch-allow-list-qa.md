# QA review: PR #220, batch allow-list and upload sweep

- PR: #220 `fix/batch-filter-allowlist`: "fix(core): allow-list batch statements; sweep skips fresh uploads"
- Head reviewed: `0688af5272fae50ca1f47bb631d2b6905653c3c8` (7 commits, two plain `main` merges)
- Scope: `LocalBatchExecutor` leading-keyword allow-list, whitespace trimming, SQLite-only trigger exception, `set_config` refusal, PRAGMA tightening; `RestoreUploads` sweep pre-check; tests, CHANGELOG, docs/database.md, HANDOFF
- Reviewer lane: QA gate (Europe/Athens, 4 Nov 2026)

## Checklist

| Check | Result |
|---|---|
| Root suite, SQLite (458 tests, module suite included) | OK, 6 skipped |
| Root suite, PostgreSQL 17 (458 tests) | OK, 1 skipped |
| `scripts/lint.sh` | clean |
| deptrac / DI architecture checks | 0 violations / clean |
| `spark apotheke:routes --check` | clean |
| `scripts/check-commits.sh` | ok |
| gitleaks over the PR range | no leaks |
| Merge commits (remerge-diff) | touch only CHANGELOG and HANDOFF |
| Released CHANGELOG sections, VERSION, README, migrations, `.github`, docs/qa | unchanged vs main |
| Adversarial probes on both drivers | see findings |
| Mutation run (22 mutants) | 18 killed, 4 survived (N-1683..N-1685) |
| Upload sweep parallel test, PostgreSQL, 30 repeats | 30/30 OK |
| CI on head | 20/20 success |
| Mergeable state | conflicting with current main (CHANGELOG only, see N-1686) |

## Verified fixes

- L-1647 (form feed / vertical tab hiding a statement): fixed. Leading and trailing trimming now uses `\s`, and hidden transaction control is refused on both drivers.
- L-1648 (PostgreSQL-only statements such as `SET`): largely fixed. Statements must now start with one of 11 allow-listed keywords, so `SET`, `TRUNCATE`, `EXPLAIN`, `NOTIFY`, `LISTEN` and parenthesised leads are refused. `set_config(` is refused in its quoted, schema-qualified, commented and upper-case forms. One residual path remains (L-1680).
- N-1649 (trigger-body exception on PostgreSQL): fixed. The exception now applies to SQLite only.
- N-1651 (upload sweep locking a fresh upload): fixed. The sweep skips files that are no longer eligible before taking the lock and re-checks under the lock. The new test is deterministic through the after-lock seam.
- PRAGMA: `optimize` no longer takes a value, and the anchor uses the `D` flag.

## Findings

### High
None.

### Medium
None.

### Low

- **L-1680: allow-listed write statements can still change session settings on PostgreSQL.** Leading keywords that are allowed, such as `UPDATE` on a system settings view, or `CREATE FUNCTION` followed by `SELECT`, can still reach the settings that L-1648 meant to close. The effect is the same class as L-1648: the local executor can be made to break batch atomicity. It affects only the local PostgreSQL test model. D1 rejects these statements, so production edge behaviour is unaffected. Suggested fix: on PostgreSQL, refuse writes to settings catalogs and user-function creation in batches, or refuse literals whose meaning depends on the string-escaping setting.

### Note

- **N-1681:** `CREATE`, `DROP` and `ALTER` let PostgreSQL-only DDL through (functions, rules, extensions, roles, databases), which D1 does not support, so the local allow-list is broader than D1. Some of these fail inside a transaction anyway. A per-object allow-list would match D1 more closely.
- **N-1682:** an old upload that looks idle can still be locked by the sweep while its owner is replacing it. The window is narrow, the behaviour is intended, and the re-check under the lock keeps it safe.
- **N-1683:** the mutants that drop `REPLACE` or `ALTER` from the allow-list survive: no test covers those keywords. Suggest one positive case for each.
- **N-1684:** the mutant that removes the PRAGMA `D` flag survives: no test covers a PRAGMA followed by a trailing newline.
- **N-1685:** the mutant that removes the re-check under the lock in the sweep survives. The pre-check alone satisfies the current tests. Suggest a seam test where a file becomes in-use between the pre-check and the lock.
- **N-1686:** main has moved (#222, edge storage CI). The branch now conflicts with main in `CHANGELOG.md` only. The code paths of #220 and #222 do not overlap. A plain main merge that resolves the CHANGELOG is needed before merging; a re-run of CI on that head is sufficient.

## Security

- No secrets, tokens or identifiers in the diff. gitleaks is clean.
- The batch filter is a local test-model guard, not a production security boundary. D1 enforces its own statement rules at the edge.
- L-1680 is a local-model fidelity gap with no production exposure.
- No new routes, permissions, migrations or dependencies.
- The upload sweep change narrows locking. It adds no new file access.

## Verdict

**PASS.** No High or Medium findings. L-1680 and the notes can be handled in a follow-up. Before merging, resolve the CHANGELOG conflict with a plain main merge (N-1686).
