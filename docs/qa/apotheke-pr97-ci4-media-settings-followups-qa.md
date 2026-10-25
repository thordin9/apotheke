# QA: apotheke #97, CI4 follow-ups for #95 (N-30, N-31) and settings writes (M-25)

- **PR:** [#97](https://github.com/thordin9/apotheke/pull/97), into `feature/ci4`. Targeted full gate on the changed code.
- **Head:** `45d951c`. **Base:** `feature/ci4` `ff100cf`. 4 commits (`66e4043`, `39aa8fd`, `18f75da`, `45d951c`).
- **Verdict: PASS at `45d951cc1ffe834fdca6567a9bdb81b8b83f1475`.** 0 High, 0 Medium, 0 Low; 4 new Nits.

## Checklist

| Check | Result |
|---|---|
| CI | 22/22, as reported by Coder. The GitHub API isn't reachable from here. |
| N-30 | A failing undo step logs `[apotheke] request cleanup step failed: <key> (<Class>)`, never the message. The key is `media:` plus the served name, which the app makes from random bytes, so no user input reaches the log. The original's 64-hex name and the originals directory never reach it either. `PlainHandler` logs only the class if the cleanup itself throws. |
| N-31 | New tests: a stored upload survives the end-of-request run; a step after a failing one still runs; the log line is key and class only. The two earlier survivors (dropping `drop()`, and `break` after a failing step) are now caught. |
| M-25 over HTTP | Saving the token on a real CI4 server, SQLite and PostgreSQL 17, with failures injected by trigger on DELETE, on INSERT, on a value-specific INSERT, a deferred constraint at COMMIT (PostgreSQL) and a CHECK constraint (PostgreSQL). Every case: JSON 500 `Server error`, rows unchanged, no open transaction left, and the next save works. |
| M-25 CLI | `apotheke:settings set` with a failing INSERT: exit 1, `Server error`, rows unchanged. Same as before #97, except the class is now `WriteFailed`, a `RuntimeException` subclass, so callers that catch `RuntimeException` are unaffected. |
| Log content | The app's own lines name only the class, the message `Could not save the settings.` and the step. No value, no SQL. See N-33 for the database driver's own error line. |
| Nesting | `replace()` inside an outer `Writes::transaction()`: if the inner write fails, the outer one throws and nothing is committed (SQLite and PostgreSQL). If the inner write succeeds and the outer one fails, the inner write is rolled back. See N-34. |
| Other write paths | Checked by grep, excluding Taxonomy, Activity and Notes. `UserRepository` checks `=== false` on both single-statement updates. `PdoMigrationRunner` and `LegacyFunctions` use PDO in exception mode. `DatabaseFactory` checks the PostgreSQL time zone and leaves the SQLite PRAGMA tuning unchecked, which I accept. |
| Seal path | `sealPlaintextRows` swallows a failed save, as legacy `secret_persist()` does. The plaintext row is the one already there, and it is sealed on a later load or save; nothing new is stored in plaintext. See N-35. |
| Worker phase | No new static state. The log closure is injected per instance. |
| Tests, PHP 8.4 | CI4 module suite: SQLite 334 OK, PostgreSQL 17 334 OK (including the PostgreSQL COMMIT test). Full PHPUnit (`APOTHEKE_APP=ci4`): SQLite 612 OK (6 skipped), PostgreSQL 17 612 OK (5 skipped). `src/` and root tests are untouched, so the legacy suite wasn't run. |
| Mutations | 10 of 15 caught, and 2 of the survivors are equivalent: removing the per-statement checks, because `transaction()` still fails on transaction status. Caught: `drop()`, `break`, message in the log, no key, no class, no log, no transaction, message in `WriteFailed`, no rethrow, seal with no save. Survivors: see N-36. |
| Repo hygiene | `.gitleaks.toml` unchanged; gitleaks 8.30.1 finds nothing in the 4 commits. `check-commits` ok (4), deptrac and the DI rule 0 errors, `check-links` 211 links with 0 broken. The CHANGELOG from `## [2.` to EOF is identical to `main` `bbd53c4`. `docs/qa` files match the local copies (56, plus the README). HANDOFF is accurate. |

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| N-30, N-31 | Nit | Closed | As above. |
| M-25 (Settings) | Medium | Closed for Coder's modules | Settings writes are checked and roll back. |
| N-33 | Nit, hardening | Open, pre-existing | On PostgreSQL, the database driver's error line for a constraint failure can include the row's detail, and so a settings value, in the application log. The value is sealed when an at-rest key is set. The shipped schema can't trigger it, and legacy's error log shows the same text. Suggest redacting detail lines from driver errors before they are logged. |
| N-34 | Nit | Open | Transactions nest flat (#96 N-32). A failure that `sealPlaintextRows` swallows inside an outer transaction makes the outer write fail, with nothing committed. Suggest skipping the seal while a transaction is open. |
| N-35 | Nit | Open | The swallowed seal failure has no log line, so a key that never seals is invisible. Matches legacy. Suggest a class-only line. |
| N-36 | Nit, tests | Open | Survivors: the cleanup-failure log line in `PlainHandler` (dropped, or with the message added), and the seal swallow (a rethrow isn't caught). Suggest a test for each. |

## Security

- Cleanup and handler log lines hold only a generated key and a class: no messages, paths or original names.
- Settings saves (including secret values) are atomic on both drivers. A failure answers the generic error and leaves the stored rows as they were.
- Hardening N-33 (driver error detail in logs) and N-35 (silent seal failure) are open, both at Nit level.

**Verdict: PASS at `45d951cc1ffe834fdca6567a9bdb81b8b83f1475`** (0H / 0M / 0L; N-33 to N-36 open).
