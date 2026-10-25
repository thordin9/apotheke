# QA: apotheke #93, CI4 follow-ups from the #89 and #91 reviews

- **PR:** [#93](https://github.com/thordin9/apotheke/pull/93), `fix(ci4): follow-ups from #89 and #91 reviews`. Full gate, scoped to the changed code (Permission filter, roles fallback, error handler, secret-key tests).
- **Head:** `5d5ba55`. **Base:** `feature/ci4` `907cd43`, which contains #91 (`fc9b2cb`) and the v2.6.14 sync #92. 5 commits (`524a290`, `4cdfb37`, `e0454ae`, `364eaf9`, `5d5ba55`).
- **Verdict: PASS at `5d5ba55ca23a850005ea5b7c5c65d276c3ceb739`.** 0 High, 0 Medium, 0 Low; 2 new Nits.

## Checklist

| Check | Result |
|---|---|
| CI | 22/22 as reported by Coder2; the GitHub API isn't reachable from here. |
| Range | CI4 modules and tests, `DevServer/StaticFile`, two root test files, AGENTS, CHANGELOG, HANDOFF. No legacy code changed, so the legacy suite wasn't re-run. |
| `none` routes (#91 N-15) | Over HTTP, 17 variants × 7 branding/health routes, GET, HEAD and POST, for 4 visitor setups. Trailing-slash forms (`/x/`, `/x//`, `/x%20/`) now need View (303 signed out, 403 without View, served to a viewer). The forms CI4's request URI already normalises (`//x`, `/x%20`, `/x+`, dot segments, an `/index.php/` prefix, `?/x`) serve the same public file without the check. See (x). |
| Write routes | The 4 POST routes × 19 variants still behave as at #91 for Edit-only, Editor, signed-out and guest visitors. |
| Redirect route | With a test redirect under `/settings/` added, Editor gets 403, signed out gets 303, and admin and sign-in-off get the redirect. Trailing and doubled slashes behave the same. |
| 400 (#91 N-15) | Control characters, `;` and NUL give 400 `Bad request` with the security headers, after the permission check, and aren't logged. The dev server serves no file for a NUL path. |
| Roles fallback (#91 N-14) | Only `DatabaseException` and `PDOException` fall back to the built-in roles; other errors propagate. With DBDebug off, a failed query returns false and also falls back, which matches legacy's fallback on any PDO error. |
| (y) | `UserRepository::find()` returning null on any error means the visitor isn't signed in (the guest or nobody), so it fails closed. |
| #89 N-1, N-3 | Key lengths 16/24/31/33 are refused in `APP_KEY` and in a key file. A key file without sodium gives 422, logged once. `password_hash` is never sealed. The backup roots use `ApothekeConfig::originalsPath()`, with the same result as before. |
| #89 N-2 | Root `composer.lock` against `main`: every shared package is identical; the only additions are the dev packages `deptrac/deptrac` and `phpstan/phpstan`. Confirmed. |
| N-11 | `SettingsServiceTest` passes (23) with `APP_KEY` (valid placeholder or malformed), `APP_KEY_FILE` (missing) or `APOTHEKE_SODIUM=0` set. See N-17. |
| Tests, PHP 8.4 | CI4 module suite: SQLite 196 OK, PostgreSQL 17 238 OK. Full PHPUnit (`APOTHEKE_APP=ci4`): SQLite 607 (39 skipped, 1 incomplete), PostgreSQL 17 607 (6 skipped), CI4 PostgreSQL proof OK. |
| Mutations | 19 of 21 caught (roles catch types, `none` exactness, 400 branch and logging, redirect catch, key-length bounds, key file without sodium, NUL check, originals path). Survivors: B3 (see N-16) and making the redirect route `none`, which is equivalent because the `none` branch re-derives the permission. |
| Worker phase | No new static or property state. `RoutePermission::exact()` is pure. |
| CHANGELOG, docs | 3 Fixed and 1 Maintenance bullet under `[Unreleased]`. `## [2.` to EOF is byte-identical to `main` and to v2.6.14 (`bbd53c4`). AGENTS and HANDOFF are updated. `check-links`: 211 links, 0 broken. |
| Repo hygiene | `.gitleaks.toml` unchanged. gitleaks 8.30.1 finds nothing in the range. `check-commits` ok (5). deptrac and the DI rule: 0 errors. No hosts or secrets in the diff (test values are placeholders). |

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| #89 N-1 | Nit | Closed | Key-length, key file without sodium, and password-hash tests are added. |
| #89 N-2 | Nit | Closed | No change needed; the lock difference is verified as above. |
| #89 N-3 | Nit | Closed | The originals path is shared. |
| N-11 | Nit | Closed | `SettingsServiceTest` isolates the key variables. |
| N-14 | Nit | Closed | The fallback is limited to database errors. |
| N-15 | Nit | Closed | Exact paths for `none` routes, 400 for disallowed characters, redirect routes checked. |
| (x) | Nit | Accepted | Forms CI4 normalises before filters reach only the health and branding controllers, which serve the same public responses as the exact path. No other resource is reachable this way. The set is wider than `//x` and `%20` (see the `none` row). |
| (y) | Nit | Accepted | It fails closed, as above. |
| N-16 | Nit | Open | `RoutePermission::exact()` decodes a second time (`getPath()` is already decoded), so a double-encoded branding path (`%252E`) skips the check where legacy needs View. It's branding only. Dropping the second decode (mutant B3) matches legacy and passes the suite; add a `%252E` case. |
| N-17 | Nit, test isolation | Open | With an unusable `APP_KEY` or `APP_KEY_FILE` in the runner's environment, `AutofillTest` (12) and `SettingsCliTest` (1) fail. These tests weren't changed here, and CI sets no key. |

## Security

- The Permission filter now checks every routed form except those that resolve to the exact public health and branding responses. Redirect routes are checked, and disallowed paths are answered 400 after the check without error logging (#91 N-15).
- The roles fallback covers database errors only (#91 N-14). A failing user lookup leaves the visitor signed out (fail-closed).
- Key handling refuses wrong-length keys and refuses to seal without sodium (#89 N-1). The key-file location check uses the same originals path as the config (#89 N-3).
- Worker phase: nothing new blocks the FrankenPHP worker / Cloudflare Container phase.

**Verdict: PASS at `5d5ba55ca23a850005ea5b7c5c65d276c3ceb739`** (0H / 0M / 0L; N-16 and N-17 open).
