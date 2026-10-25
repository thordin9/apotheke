# QA: PR #101: CI4 Playwright target + N-51, N-53, N-39

Reviewed head `eaf1b84` (cut from `7142a97`). Full gate. **Verdict: PASS**. There are no High or Medium findings. One Low test gap (L-72).

## Gates
- gitleaks `7142a97..eaf1b84`: no leaks. The `.gitleaks.toml` sha256 matches `b5bc9f98…3a8f`.
- check-commits: 7 ok. deptrac: 0 violations. DI rule: OK. `lint.sh`: exit 0. check-links: 211 links, 0 broken.
- The CHANGELOG tail (`## [2.` onward) is identical to main `bbd53c4`. No files under `docs/qa/` were changed.
- Suites (PHP 8.4 for CI4, 8.3 for legacy):
  - CI4 module suite on SQLite + PG: 395 tests, OK.
  - Full CI4 suite: 669 OK on SQLite and 669 OK on PG.
  - Full legacy suite: 669 OK on SQLite and 669 OK on PG.

## N-51: LegacyPathRouter
- **Differential probe.** About 6,900 requests per run, comparing legacy, base CI4 and head CI4.
  - Methods: GET, HEAD and OPTIONS as anon, viewer and admin. POST as viewer with a token, as admin without and with a token, and with method-override headers.
  - Path variants: trailing `/`, `./`, `../`, `%2e`/`%2E`/`%252e`, `//` and `///`, `\` and `%5C`, `%00`, `%0a`, overlong UTF-8, mixed-case escapes, `;params`, `?` and `#` edges, `+`, `%2B`, `%20`.
- **Result: CI4 is never more permissive than legacy.**
  - At base, 319 GET and 137 HEAD variants returned 200 where legacy returns 404. Head now returns 404 for all of them.
  - Head also newly matches legacy on `/?/x`.
- **CSRF.** Admin POSTs without a token, across all variants and override headers, return only 419, 404 or 400. There is no bypass.
  - The viewer succeeds only on view-permission routes. Single-decoded forms (`/recent%2Fclear`, `/%72ecent/clear`) behave as they do on legacy.
- **Double decode.** `/%2573tats` returns 404 on both sides.
- **CLI fallback.** The fallback runs only when `REQUEST_URI` is null. Both php -S and FPM (via `fastcgi_params`) always set it, so the fallback can't be reached over a web SAPI.
- **Worker mode.** The router is not a persistent service and holds no static state.
- **Pre-existing differences (base already had them, not new):**
  - CI4 returns 400 where legacy returns 404 for `\`, `%5C`, `%0a`, `%0d`, `%09`, `;`, `%23`, overlong sequences and `%ff`.
  - Legacy returns 500 on `%00`.
  - 404 bodies differ in places.

## N-53: real PG 17
- **Live check.** A trigger raises an error whose DETAIL holds a sentinel value, two fake `in X on line N.` lines and a lowercase `detail:`. Renaming the row on a CI4 server returns 500.
  - The log shows `DETAIL: (removed)` and keeps the real `in … on line N.` line.
  - The sentinel appears 0 times in the log and 0 times in stderr.
- **Unit probe.** Mixed-case `DeTaIl:` and a 2 MB DETAIL are both redacted.
- The value on the ERROR line itself is still logged. This is known and out of scope.

## N-39: summary matcher
- These summaries correctly **fail**:
  - fail+skip, error+skip, risky+skip, warnings+skip, PHPUnit-warnings+skip, notices, incomplete+skip;
  - `OK, but` followed by failures; trailing junk; CRLF; "No tests executed".
- See N-73 for the weak spots.

## CI / Composer
- The Composer 2.10.3 phar's sha256 equals the published value and the pin. `hash_file` runs before `chmod` or any execution.
- No curl|sh. The step uses no secrets. The legacy Playwright step is unchanged.
- **Local Playwright on CI4.** Ran the 11 ported specs on desktop-chromium and pgsql-smoke with headless Chromium and PHP 8.4 + intl: 18 passed, 17 skipped, exit 0.
- **Skip list (`CI4_UNPORTED`, 13 entries).** Every entry reaches a page that isn't ported yet, so each skip is justified. See N-74.

## Mutants: 12/16 killed
- **Killed:** R4 trim check, R5 `%` escape, R6 exact gate, R8 double decode, R9 plain router, R10 and R11 trimmed helpers, D1 case-sensitive, D2 lazy, S1, S2, S3.
- **Survived but equivalent:** R1 (no `//` check), R2 (no dot-segment check) and R7 (`urldecode` instead of `rawurldecode`). The parent router or the trim check already rejects those paths.
- **Real survivor:** R3, which removes the `+` check. See L-72.

## Findings
- **N-70. Filters still use two path notions.**
  - The permission filter's fallback for unmatched paths, and its exact-path check, use the CI4-normalised `getPath()`. The router, `isHealth` and the public-asset helper use the legacy path.
  - Effects:
    - An absolute-form request target (`GET http://host/healthz`, also theme.css, favicon and brand) gets 303 to /login, even when signed in. Legacy returns 200.
    - Some unmatched variants get a different refusal: viewer `/./settings` gets 403 (legacy 404); anon `/healthz/` gets 404 (legacy 303); anon `//media/…` gets 401 (legacy 303).
  - Never more permissive, because the router still answers 404.
  - Hardening: use `LegacyPathRouter::requestPath()` in the permission filter as well.
- **N-71. Credential tests need PG role privileges.** The 16 errors (8 DSN, 3 connect-leak, 2 schema, 2 schema-create, 1 HTTP probe) are not caused by password auth; scram-sha-256 works.
  - First error: `CREATE ROLE` permission denied.
  - With CREATEROLE: `must be able to SET ROLE` (PG 16+).
  - With CREATEROLE + `createrole_self_grant='set, inherit'`: all pass.
  - This is likely the author's 7 local failures. Suggest documenting the required test role privileges.
- **L-72. No test covers `+`.** With the `+` check removed (mutant R3), `/stats+`, `/stats%2B`, `/+stats` and `/stats%20%2B` all return 200 (legacy: 404). The CHANGELOG claims `+` is covered. Add those paths to the router test.
- **N-73. The N-39 matcher is lenient.** It accepts an all-skipped run (`Tests: 3, Assertions: 0, Skipped: 3.`) and `OK (0 tests…)`. It isn't anchored to the final summary, though the exit-code assert covers failures. Suggest anchoring to the last summary and rejecting zero or all-skipped runs.
- **N-74. Skip granularity.**
  - Whole specs are skipped: value, favourites, originals, and both manage add/rename/default/delete-with-move tests.
  - Ported behaviour such as the card star, the fav chip and manage add/rename/default loses browser coverage until 11a. The goldens still cover it.
  - The errors 404 test passes trivially on the unported `/labels` and `/item/999`.
  - Skip keys are titles only, so stale entries go unnoticed.
  - Suggest splitting tests so the ported prefixes still run.
- **N-75. Redaction matches English only.** A localised DETAIL label (e.g. French `DÉTAIL :`) is not redacted. This is theoretical: no translations are installed, and `lc_messages` is superuser-only.
