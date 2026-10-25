# QA: PR #99, CI4 catalog list, search, stats, saved filters, favourites (PLAN step 9)

**Reviewed head: `329fcf4`.** The base is `b511156`.
- `57928f0`: the 5 code and test commits.
- `4be952b`: docs (step 9 acceptance).
- `49f1a43`, `65e2298`, `c50209a`: the M-50 fix, its tests, and N-51 docs.
- `329fcf4`: merge of `feature/ci4` (#98 Notes).

Gate run on 2026-10-25 from read-only clones. CI4 ran on PHP 8.4 and legacy on PHP 8.3. Databases: SQLite, and PostgreSQL 17 on a throwaway role and database.

**Verdict: PASS.**
- M-50 is closed.
- N-51 is tracked in HANDOFF.
- N-52 and N-53 stay open as Low/Note items. Neither blocks the merge.
- The PM accepted the golden parity test in place of the step-9 Playwright specs. That is recorded, not a finding.

## Checklist

| Area | Result |
|---|---|
| gitleaks `b511156..329fcf4` (`.gitleaks.toml` sha `b5bc9f98…`) | pass, no leaks |
| check-commits, deptrac (0 violations), DI rule, `lint.sh`, check-links (211) | pass |
| CHANGELOG released tail | identical to `main` `bbd53c4` |
| `docs/qa` files | unchanged; the #98 doc sha is `3c206b3e…d4e0` |
| Docs commits `4be952b`, `c50209a` | docs only: PLAN step 9 note, HANDOFF Catalog entry and Tracked section (trailing slash, N-51) |
| Merge `329fcf4` (`--remerge-diff`) | 4 files differ from both parents: AGENTS.md, deptrac.yaml and HANDOFF (conflicts), plus CHANGELOG (auto-merged). Every line either side added is present, and no line either side removed came back. Coder2's lines are unchanged. Only the Notes layer and rule were added to deptrac |
| CI4 module suite at `329fcf4` | 394/394 with the PG legs (317 on SQLite only) |
| Full suite at `57928f0` | 614 OK on CI4 and legacy, SQLite and PG |
| Catalog, search and Notes tests at `329fcf4` | 69/69 on SQLite and on PG |
| GET differential against legacy, same data | 810 pairs, no body difference. Covers filters, sorts, paging, select, chips, toasts, the `stats_open` cookie, fetch JSON, `/stats` in a non-UTC timezone, and hostile params (huge or negative pages, arrays, invalid UTF-8, NUL, SQL-ish text, `% _ \`) |
| Search differential (44 queries, page and fetch) | 88/88 identical on SQLite without FTS5, SQLite with FTS5, and PG with pg_trgm |
| POST differential, SQLite and PG | 64 cases each: filters, limit, rename and delete, fav `back` open redirects, CSRF 419, viewer 403. Same answers and tables, except N-52 |
| XSS | no raw payload from hostile names or queries in any HTML or JSON answer |
| `/index.php`, `/index.php/stats` | 404, as on legacy |
| Writes and permissions | forced failures give a 500 with tables unchanged. View covers GET, HEAD and `/recent/clear`; Edit covers the other POSTs |
| N-33, N-34, N-35, N-36 | closed. On real PG, a unique violation and a multi-line `DETAIL` carrying a sentinel show `DETAIL: (removed)`, and the sentinel appears 0 times in the log and server stderr |
| Worker mode | no new static state. The engine is cached per instance (non-persistent service); the fingerprint is stored in `search_meta` |

**Mutants: 22/22 killed.**
- The first 20 (at `57928f0`):
  - redaction, cleanup log, seal
  - search escaping, LIKE escaping
  - rebuild transaction, page clamp
  - permissions, toasts, filter limit
  - unchecked write, `SCRIPT_NAME`
- 2 on the M-50 fix: dropping it, and moving `engine()` back inside the transaction. Each fails `CatalogTest::testSqliteWithoutFts5SearchesWithLike` and the SQLite parity golden test.

## Findings

**M-50 (closed): on SQLite without FTS5, every search answered 500.**
- *Was:* the first `engine()` probe ran inside the rebuild transaction. When the FTS table was absent, the failed probe failed the rebuild.
- *Fix:* `ensure()` now probes the engine first, before the fingerprint check and the transaction (`49f1a43`).
- *Re-check, with the FTS tables dropped and `search_meta` cleared:*
  - The repro searches now answer 200, with bodies identical to legacy.
  - The fingerprint is stored and the rebuild completes.
  - A later rename is indexed.
- *Unchanged paths:* FTS5 and PG gave the same 88/88 identical answers.
- *Tests:* `65e2298` adds the no-FTS test and golden cases; reverting the fix fails them.

**N-51 (tracked): dot segments.** `/./stats`, `/%2e/stats` and `/a/../stats` answer 200 where legacy gives 404. Filters still apply. HANDOFF Tracked now lists this with the trailing slash (`c50209a`).

**N-52 (open, Low): NUL in a saved-filter name on SQLite.** CI4 stores `a` and legacy stores `a\0b`; PG matches between the two. Likely driver-wide, so it is worth keeping in mind for later steps.

**N-53 (open, hardening): log redaction boundary.**
- The `DETAIL` strip stops at the first `\nin <x> on line N.`. A value containing that text leaks whatever follows it.
- Only an upper-case `DETAIL:` is removed; a value quoted in the `ERROR:` line itself is not.
- Consider anchoring on the last such line.

**Notes (no action):**
- CI4 is stricter than legacy on PG overflow ids and on `//index.php`.
- `Cache-Control` wording differs on 404s; both send `no-store`.
- Author-disclosed and not graded again: 5 full-table reads per search, and deferred 11a, 11b, Undo, lent and barcode.
