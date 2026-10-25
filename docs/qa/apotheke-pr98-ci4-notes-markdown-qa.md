# QA: apotheke #98, CI4 Notes Markdown renderer and N-32

- **PR:** [#98](https://github.com/thordin9/apotheke/pull/98) (`ci4/notes` into `feature/ci4`). This is a full gate: the PR adds output escaping in a renderer and changes the `Writes` commit path.
- **Head:** `612d984`, a merge of `feature/ci4` `b511156` (#97). **Base:** `feature/ci4` `b511156`. 3 commits and the merge.
- **Verdict: PASS at `612d984312d3202f24d393339504990156ffce09`.** 0 High, 0 Medium; 1 Low (legacy parity, follow-up); 2 Nits.

## Checklist

| Check | Result |
|---|---|
| CI | 22/22, as reported by Coder2. The GitHub API isn't reachable from here. |
| Merge | `--remerge-diff` shows a conflict only in HANDOFF. Every other file equals the side that changed it: 9 files from `ci4/notes`, 6 from #97. See N-38. |
| Renderer port | `MarkdownRenderer` follows `src/markdown.php` line for line. Closures became methods, the `(string)` casts are added, and `h()` is the same. |
| Own differential fuzz | 40,306 inputs on 2 new seeds. They covered nested brackets, entity tricks (`&#106;avascript:`, `&#x6A;`, `&colon;`, `&#xD800;`, `&#0;`), mixed-case, tab and newline schemes, `vbscript:` and `data:`, and attribute breakouts. They also included newlines in URLs, overlong and surrogate UTF-8, `\xff`, NUL and DEL, and bidi characters. Cap cases were 19,998–20,001 characters (ASCII, `é`, emoji, invalid bytes at the edge), plus 20 kB pathological lines for PCRE. Results: **0 differences** from legacy `md_render()`/`md_plain()` on PHP 8.3 and PHP 8.4, and 0 exceptions or PCRE failures. |
| Output safety | Every output was parsed with the HTML5 parser (`Dom\HTMLDocument`). Only the allowed elements appear, and only `href`/`rel`/`target` on `a`. Every `href` is http(s) or mailto. 0 unsafe in 40,306. The renderer mutants that drop escaping give 178 to 9,903 unsafe outputs, so the check works. See L-37 for a legacy quirk inside attribute values. |
| Worker phase | Nothing blocks the FrankenPHP worker / Cloudflare Container phase. No static properties, globals or superglobals; the closures keep state per call. The same holds for `Writes`. |
| N-32 | Closed. The docblock says nesting is flat (no SAVEPOINT). A refused commit always throws `WriteFailed`, whether the driver throws (PostgreSQL) or returns false. `WritesTransactionTest` passes on real PostgreSQL 17 (deferred foreign key refused at COMMIT) and SQLite, along with a commit that returns false and flat nesting. A refused transaction ends at depth 0 and keeps nothing, and the next one commits. M-25 hasn't come back: `WriteFailureTest`, `SettingsWriteFailureTest` and the taxonomy parity triggers (500, tables unchanged) pass on both drivers. |
| Equivalent mutant | `ENT_SUBSTITUTE` dropped: equivalent. `mb_substr` repairs invalid UTF-8 before any `h()`, and `html_entity_decode` never yields invalid UTF-8 (surrogate and NUL entities stay encoded). The fuzz with invalid bytes and those entities shows 0 differences. |
| Tests, PHP 8.4 | CI4 module suite: SQLite 292 (1 skipped), PostgreSQL 17 356 OK. Full PHPUnit (`APOTHEKE_APP=ci4`): PostgreSQL 17 658 OK (5 skipped); SQLite 658, 1 failure (N-39, not from this PR). Legacy (PHP 8.3, SQLite): 658 OK (78 skipped, 1 incomplete). |
| Mutations | 19 of 21 caught. They cover cap length and bytes vs. characters, control characters, escaping of code, link text, `href` and fences, link schemes, entity decode, quote recursion, plain text, heading level, `rel`, and in `Writes` the commit catch, the commit result, rollback and status. Both survivors are equivalent: `ENT_SUBSTITUTE`, and `link()`'s own scheme check, which only ever gets URLs the regex already limited to http(s)/mailto. |
| CHANGELOG, docs | One Changed and one Fixed bullet under `[Unreleased]`. From `## [2.` to EOF the file is byte-identical to `main` `bbd53c4`. AGENTS and deptrac (Notes depends on Core only) agree. `check-links`: 0 broken. `lint.sh` OK. `docs/qa`: 57 identical. |
| Repo hygiene | `.gitleaks.toml` unchanged. gitleaks 8.30.1 finds nothing. `check-commits` ok (3). deptrac and the DI rule: 0 errors. No hosts or secrets. |

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| N-32 | Nit | Closed | See the checklist. |
| L-37 | Low, legacy too | Open (follow-up) | Emphasis runs after a bare URL becomes a link, so `_`, `*` or `~~` in a bare URL put `<em>`, `<strong>` or `<del>` into the `href` and `target` values. For example, `https://a.org/_x_` gives `href=".../<em>x</em>"` and `target="<em>blank"`. It is not exploitable: no quote or element gets in, and every `href` keeps its scheme. But URLs break and some links lose `_blank`. Legacy does the same, and CI4 must match it. Suggest fixing both together: hold bare links in placeholders, as for inline code, before emphasis. Add a corpus case. |
| N-38 | Nit, docs | Open | The HANDOFF merge dropped "then step 9 (catalog)" from Coder2's lane, although both parents had it. Now no lane owns step 9. Restore it, or name the new owner. There is also a stray blank line before the Notes bullet in the CHANGELOG. |
| N-39 | Nit, tests | Open (from #97) | On a SQLite-only machine, `Ci4ModuleSuiteTest` fails. #97's `SettingsWriteFailureTest::testAFailedCommitThrows` skips there ("PostgreSQL only"), and the test wants a plain `OK (`. CI isn't affected, because every leg has PostgreSQL. Suggest accepting `OK, but some tests were skipped`, or making the commit test run on SQLite with a deferred foreign key. Route to #97's author. |
| #84 N-2, N-3, N-5 | | | Not affected (main). |

## Security

- Note text is escaped before any markup. Only http(s)/mailto links are produced, with `rel="nofollow noopener noreferrer"`. HTML5 parsing of 40,306 hostile outputs found no element, attribute or scheme outside the allowed set.
- Hardening: L-37 (markup inside the link attribute values; legacy too; not exploitable).
- `Writes`: a commit the database refuses now always rolls back and answers with an error (N-32).
- Worker phase: nothing new blocks the FrankenPHP worker / Cloudflare Container phase.

**Verdict: PASS at `612d984312d3202f24d393339504990156ffce09`** (0H / 0M / 1L / 2N). L-37 is a parity follow-up for main and CI4 together. N-38 and N-39 can follow.
