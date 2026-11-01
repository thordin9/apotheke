# QA: PR 209, SQL executor adapter (P4-2a, part 1 of 3)

- PR: thordin9/apotheke#209, `feat/p4-executor`, by Coder2. #210 is stacked on it and reviewed separately.
- Head: `33dd38cc6a78fd9afd6de8cb9ea7b501f3f0205b`, 7 commits (5 by Coder2 plus 2 plain main merges)
- Base: main `4702c69`, 0 behind, mergeable `clean`. Diff: +464/-35 in 16 files.

## Verdict

**PASS.** No H or M findings, one L and four N. The adapter is a thin, stateless layer. The long id lists are bound as one JSON parameter on both databases, and nothing changes for existing installs (no schema change).

## Checklist

| Check | Result |
|---|---|
| CI on the head | 20/20 success, including `Tests (PHP 8.4, sqlite)` and `Tests (PHP 8.4, pgsql)` |
| Local suites (PHP 8.4, own PG17 cluster) | Root suite, which also runs the module suite: SQLite 451 tests OK (6 skipped), PostgreSQL 451 tests OK (1 skipped) |
| New tests | `SqlExecutorTest` and `DatabaseStateTest`: 8/8 on both drivers |
| Diff against main is Coder2's commits only | Yes. `6033b14..107c944` applied to main gives the head's tree, except `docs/HANDOFF.md`. Both merges conflicted only in HANDOFF, which keeps main's #205/#206 entries |
| `CHANGELOG.md`, `docs/qa`, `.github`, `.gitleaks.toml` | Identical to main |
| Targeted mutants | 13 of 14 killed. The one survivor is L-1620 |
| gitleaks 8.30.1, `main..33dd38c` | No leaks. No UUIDs, 32-hex strings or tokens in the diff |
| Static state, worker and classic mode | No static properties or static variables in `Core\Database` (tested, and a mutant was killed). `sqlExecutor` and `apothekeDb` are not persistent services |
| Existing installs | No migration and no schema change. `ConnectionExecutor` runs on the request's connection through `Writes::transaction()`. The only behaviour change is in 3 queries, `IN (?,?,…)` → `IN (subquery over one JSON parameter)` |
| ADR and nits | N-1560, N-1561 and N-1562 (#203) are fixed. N-1584 and N-1589 are in ADR P4-4 and match `RestoreUploads` and the jobs route |

## Security

- **Binding.** Every value is bound. The SQL text that `Dialect::inList()` adds is a fixed constant, either `(SELECT value FROM json_each(?))` or `(SELECT json_array_elements_text(?::json)[::bigint])`. `ItemList` no longer inlines search ids into its `WHERE` clause. Its ranking `CASE` is still built from `int`-typed ids, as on main.
- **Injection, tested on both drivers.** A text list with all of these round-trips exactly, in order, and the bind after the list keeps its position:
  - a quote, a backslash, `') OR 1=1 --`, `?`, `?::json`;
  - accents, an emoji, a control character, a JSON quote.
- **Type and size, tested on both drivers.**
  - A non-JSON payload such as `1) OR (1=1` matches nothing.
  - String ids (`"1"`) match integer columns. On SQLite numeric affinity applies, on PostgreSQL the `::bigint` cast.
  - Non-list keys are flattened by `array_values()`.
  - An empty list matches nothing. `ItemList` still uses `1 = 0` for no hits, and a mutant was killed.
  - 200,000 ids work in one parameter.
- **PostgreSQL parity.** `IN (SELECT json_array_elements_text(?::json)::bigint)` is the semi-join equivalent of `= ANY($1)`. It is not a plain `IN` list. Text lists stay as text, and a mutant that drops the cast was killed.
- **Limits.** Each list is one bound parameter, so neither `SQLITE_MAX_VARIABLE_NUMBER` nor D1's 100-parameter limit applies. `json_each` runs on D1 and on Durable Object SQLite.
- **Errors.**
  - A failed statement throws `WriteFailed` naming only the step (`the batch`, `read`), and the database's message isn't passed on (mutant killed).
  - A failed read throws; it doesn't return `[]` (mutant killed).
  - A failed batch statement rolls back the whole batch (mutant killed). When the batch joins an outer transaction, `transStatus()` fails the outer one too (DBDebug is off).
  - No exception is swallowed: each is caught once and rethrown as `WriteFailed`. This matches `Writes::check()`.

## Findings

**L-1620: the ranking query's list bind is untested.**
- The mutant that binds only the first id of each chunk in `SearchIndex` ranking (`search_docs WHERE item_id IN …`) survived every suite.
- The changed line can break the "best match first" order without any test failing.
- Add a test with two or more hits that checks the ranked order on both drivers.

**N-1621: guard detection matches a substring.**
- `str_contains($error, 'uow_guard')` checks the whole error text. On PostgreSQL, `pg_last_error()` includes the DETAIL line with row values.
- So a stored value containing `uow_guard` in, say, a unique violation would be reported as `GUARD_FAILED`.
- Match on the relation or constraint name (or the SQLSTATE and table) instead. No caller reaches this yet.

**N-1622: the docs are ahead of the code.**
- The ADR "Request state" section names `Core\Database\UnitsOfWork` and `Services::unitsOfWork()`, and `UowSchema` cites "migration 0031". Both arrive in #210 or later, and `migrations/` ends at 0030 here.
- `database.md` and `architecture.md` say "All SQL goes through one executor per request", but nothing calls `Services::sqlExecutor()` yet.
- Use "from P4-2a part 2" or the future tense.

**N-1623: a new SQLite runtime dependency, JSON1.**
- Main had no `json_each`. JSON1 is built in from SQLite 3.38 and enabled in the usual distro builds (this box has 3.46.1), and the images are fine.
- A non-Docker install with an old SQLite would see search, list and stocktake fail. Document the minimum.
- `Dialect::list()` on invalid UTF-8 text throws `JsonException`, not `WriteFailed`. There is no text caller yet.

**N-1624: the `ItemList` match list is not chunked.**
- At about 7 B per id, D1's 2 MB per-value limit is reached at about 290,000 items. That is well above the target scale; noted for P4-3.

## Mutants (14)

Killed (13):

| Mutant | Caught by |
|---|---|
| Dialect always SQLite | executor, catalog, stocktake and unit tests |
| PostgreSQL without `::bigint` | same as above |
| Batch outside a transaction | `SqlExecutorTest` |
| Guard never detected | `SqlExecutorTest` |
| Database message passed on | `SqlExecutorTest` |
| A failed read returns `[]` | `SqlExecutorTest` |
| The list drops its last id | all groups |
| `ItemList` match list empty | catalog and pages golden tests |
| `ItemList` subtree first id only | catalog |
| `Stocktakes` first id only | stocktake parity |
| A static property in `Dialect` | `DatabaseStateTest` |
| No hits → `1 = 1` | catalog and golden tests |
| Batch returns no rows | `SqlExecutorTest` |

Survived (1): ranking chunk first id only (L-1620).

## Not checked

- The real D1 or Durable Object executors, which come in P4-3.
- #210.
