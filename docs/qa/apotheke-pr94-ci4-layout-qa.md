# QA: apotheke #94, CI4 Layout module and the app's error pages

- **PR:** [#94](https://github.com/thordin9/apotheke/pull/94), `feat(ci4): Layout module and the app's error pages` (`ci4/layout` into `feature/ci4`). This is a full gate, because the PR changes the error answers of the CSRF, same-origin, 413 and Permission filters and the exception handler. It also includes #93 N-16 and N-17.
- **Head:** `2bdf21f`. **Base:** `feature/ci4` `26ce1e9`. 6 commits (`118a035`, `4407f3b`, `60b9ff0`, `1f738e2`, `ff80338`, `2bdf21f`).
- **Verdict: PASS at `2bdf21ff7c8f8dac6dad6b65e0b2b75bc39e931f`.** 0 High, 0 Medium, 0 Low; 2 new Nits.

## Checklist

| Check | Result |
|---|---|
| CI | 22/22, as reported by Coder2. The GitHub API isn't reachable from here. |
| Range | CI4 config, Core filters and handler, Auth (`RoutePermission`, Permission filter), new `Layout` module, a byte copy of `public/assets` under `ci4/public/assets`, tests, deptrac, AGENTS, CHANGELOG, HANDOFF. No legacy code changed. Legacy suite run anyway, because root tests changed. |
| Page shell parity | `Ci4LayoutParityTest` uses `assertSame` on every answer: status, content type, Location, JSON, body, Set-Cookie and the 4 security headers. It covers 8 visitor kinds, the 404, 403, 419 and fetch 419 answers, POST `/theme` (3 back values, fetch, no CSRF), the menu, the simple header, theme cookies and escaped branding. The only normalisation is `?v=` busters, the session token and the menu's back value. More than 40 cases are checked, and spot assertions show each case is what its name says. The test isn't vacuous: mutants M12, M18 and M20 are caught by it. The icon set, `safe_back()` and the asset bytes (sha256 per file) are also compared. |
| Not in the parity test | Checked by hand over HTTP against legacy. The 413 HTML page is byte-identical (1,652 B). The 413, same-origin and 419 JSON answers send `application/json; charset=utf-8` with `Cache-Control: no-store`. The same-origin 403 for POST `/theme` matches legacy. |
| POST `/theme` | Has `permission` view, the global CSRF filter (419 without a token for every visitor) and the same-origin filter (403 cross-site). The cookie value is limited to auto/light/dark. The action writes no stored state. The redirect goes through `Theme::safeBack()`, the same regex as legacy: `//x`, `/\x`, CR/LF, VT and `https://` all become `/`. A guest with view may use it, as on legacy (deviation 6). See N-18. |
| Error answers | The Permission filter's 403, CSRF 419, 413, same-origin 403 and the handler's 404, `UserError` and 500 all go through `Core\Http\ErrorPage`, and the message is escaped. A 500 page shows only the generic text. JSON answers use `Server error` or the `UserError` text. No class, path, SQL or trace reaches a response. A 404 for a fetch is the HTML page, as on legacy. The router-refused 400 stays plain text. If the page can't be drawn (database gone), the plain fallback is served. |
| N-16 | `exact()` no longer decodes. Signed out with sign-in on, `/theme%252Ecss`, `/favicon%252Eico` and `/brand/logo%252Epng` now get 303 to `/login` on both apps. `/theme%2Ecss` is served on both. The test cases are meaningful: putting the decode back (M01) fails them. |
| N-17 | `AutofillTest`, `SettingsCliTest` and `SettingsServiceTest` (53) pass with `APP_KEY=short`, with `APP_KEY_FILE=/nonexistent/key`, with `APOTHEKE_SODIUM=0`, and with all three set. The whole module suite passes with all three set (201). Without the bootstrap loop (M24), 12 errors and 1 failure come back. |
| Tests, PHP 8.4 | CI4 module suite: SQLite 201 OK, PostgreSQL 17 243 OK. Full PHPUnit (`APOTHEKE_APP=ci4`): SQLite 610 (39 skipped, 1 incomplete), PostgreSQL 17 610 OK (4 skipped). Legacy full suite on PHP 8.3 / SQLite: 610 OK (77 skipped, 1 incomplete). |
| Mutations | 20 of 24 caught. They cover the second decode, 404/400/JSON branches, the handler's no-store and content type, 500 text, each filter's page, escaping on the page and the fallback, class-only logging, session-free page, `safeBack`, theme validation, the route's permission, `is-guest`, and the bootstrap key reset. Survivors are M07, M09 and M11 (no-store dropped from the CSRF, 413 and same-origin JSON) and M10 (413 HTML page). See N-19. |
| Worker phase | No static or per-request global state was added. `Config\Exceptions::$page` is a closure that resolves `Services::errorPage()` on each error, so the persistent `exceptions` service never holds a stale page. `layout` and `errorPage` are ordinary shared services, rebuilt per request in CI4 worker mode. The theme cookie goes through the response, not `setcookie()`. `Services::layout()` reads `$_COOKIE` and `REQUEST_URI`, which FrankenPHP resets per request. Taking them from the request object would be tidier, but it doesn't block anything. |
| CHANGELOG, docs | One Changed and one Fixed bullet under `[Unreleased]`. From `## [2.` to EOF the file is byte-identical to `main` (`bbd53c4`). AGENTS and HANDOFF are updated. `check-links`: 211 links, 0 broken. Existing `docs/qa` files match the local copies (53 identical). |
| Repo hygiene | `.gitleaks.toml` unchanged. gitleaks 8.30.1 finds nothing in 6 commits. `check-commits` ok (6). deptrac (Layout depends on Core and Settings) and the DI rule: 0 errors. No hosts or secrets in the diff (`*.example` only). |

## PR deviations

| # | Assessment |
|---|---|
| 1 No undo bar yet | Accepted. `Page::end()` takes it with the Undo module. |
| 2 Cache busters differ | Accepted. Same formula, different file times; the URLs match without `?v=`. |
| 3 `no-store` on HTML error pages | Accepted. Stricter than legacy. |
| 4 Plain 400 for refused paths | Accepted, as at #93. |
| 5 Fallback logs only the class | Accepted. The 500's own exception is still logged with its message by the handler, and a connect failure logs its own line. Leaving the text out keeps connection details out of the log. Little diagnostic value is lost. |
| 6 Guest may POST `/theme` | Accepted. Legacy `route_perm('/theme')` is view. It needs CSRF and same origin, only sets the caller's own cookie, and redirects same-site only. |

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| #93 N-16 | Nit | Closed | Single decode, `%252E` tests added. |
| #93 N-17 | Nit | Closed | Bootstrap clears the key variables. |
| N-18 | Nit | Open | A `back` value with a control character (NUL, `\x01`, DEL) that legacy redirects gets a 500, because CI4 refuses the header. It needs the user's own CSRF token and logs one generic line. Suggest rejecting C0 and DEL in `Theme::safeBack()`, so the answer is 303 to `/`. |
| N-19 | Nit, tests | Open | Nothing asserts `Cache-Control: no-store` on the CSRF, 413 or same-origin JSON, or the 413 HTML page (survivors M07, M09, M10, M11). The behaviour is correct over HTTP; add assertions to the filter tests. |
| #84 N-2, N-3, N-5 | | | Not affected (main). |

## Security

- Every CI4 error answer that legacy draws as a page now uses the escaped app page, and nothing internal is shown. JSON error answers are not cached.
- POST `/theme` keeps the CSRF, same-origin and permission checks. It writes no stored state and redirects only to same-site paths. N-18 is a robustness nit.
- Double-encoded public paths now need the same permission as on legacy (#93 N-16).
- When the error page can't be drawn, the log names only the error class.
- Worker phase: nothing new blocks the FrankenPHP worker / Cloudflare Container phase.

**Verdict: PASS at `2bdf21ff7c8f8dac6dad6b65e0b2b75bc39e931f`** (0H / 0M / 0L; N-18 and N-19 open).
