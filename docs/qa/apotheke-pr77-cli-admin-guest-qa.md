# QA: apotheke #77, keep the CLI admin when guest browsing is on

- **PR:** [#77](https://github.com/thordin9/apotheke/pull/77), `cursor/cli-admin-guest-browsing-d3ee` into `main`, by Coder
- **Head reviewed:** `ad21bf1fc818ab8feb8b7ba38580df558ceecefb` (still the PR head when I re-checked at the end of the review)
- **Base:** `main` at `205ed23` (`chore(release): v2.6.4`), the merge base and the second parent of the merge `159c01d`
- **Scope:** a full review (auth fix) of the whole PR against `main`: `src/users.php`, two new tests, CHANGELOG
- **Verdict: PASS.** 0 High, 0 Medium, 1 Low, 2 Nits, 5 Info. Nothing blocks. `main` has since moved to v2.6.5 (I-1), so a merge of `main` and a short re-check come before the merge.

## 1. Test plan

1. Read-only `git clone` of the repo into a temporary directory, `refs/pull/77/head`, `main`, `feature/ci4` and #75's head. Diff review, commit subjects.
2. Security core: every SAPI that serves HTTP, where the `anon` flag comes from, every caller of `actor_is_admin()`, `guard_admin_target()` and `current_user()`, and any other admin check that repeats the old rule.
3. Behaviour matrix with the real `bin/settings.php` on `main`, the PR, #75's head, and #75 with #77 merged in. A web-SAPI probe under `php -S` (`cli-server`) on `main`, the PR and a mutant.
4. PHPUnit: the new tests plus the Users, Settings, Backup, Permissions and SessionCookie (auth) suites on PHP 8.3 and 8.4, on SQLite and PostgreSQL 17. The full unit suite and the full HTTP suite (`tests/integration.php`) on PHP 8.4.
5. Mutations of the changed line.
6. CHANGELOG gate, `check-links`, `check-commits`. Docs read against the code. Secret and host-name scan of the diff.
7. CI run 40089319279, checked through the GitHub API.

**Environment.** The box has no Docker.
- **PHP 8.4.27:** system PHP (pdo_sqlite, pdo_pgsql, gd).
- **PHP 8.3.36:** the shared Alpine rootfs from `php:8.3.36-fpm-alpine` (no Imagick, which this PR doesn't need), used through a chroot with only my own bind mount, which was removed afterwards.
- **PostgreSQL:** 17.11 on the box, with a throwaway role and database. Each test runs in its own schema, and both were dropped afterwards.
- **Not repeated:** the Docker build, the smoke test and Playwright. CI covers them (2.1).

## 2. Results

### 2.1 CI

Run 40089319279 (`pull_request`, head `ad21bf1`): `completed` / `success`, 10/10 jobs `success`. The jobs are Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Visual E2E 1/3, 2/3 and 3/3, Docker build + smoke test, Conventional Commits, and Secret scan. The run started at 14:17:39 and ended at 14:28:19 (Athens, UTC+3).

### 2.2 Diff and commits

4 files, +81/−1: `CHANGELOG.md` (+4), `src/users.php` (+2/−1), `tests/Unit/SettingsTest.php` (+34), `tests/Unit/UsersTest.php` (+41).

| Commit | Subject |
|---|---|
| `474e89b` | `fix(auth): keep the CLI admin when guest browsing is on` |
| `12b3c0c` | `docs(changelog): note the CLI guest-browsing admin fix` |
| `159c01d` | Merge of `origin/main` (`205ed23`, v2.6.4) |
| `ad21bf1` | `docs(changelog): keep the CLI admin note out of v2.6.4` |

All subjects are Conventional Commits and 72 characters or shorter. `scripts/check-commits.sh 205ed23 ad21bf1` passes, and merges are skipped by the check. There are no rewrites. There is no `docs(qa):` commit yet; this doc becomes the last commit.

**The change.** In `actor_is_admin()`, the "nobody signed in" branch now also takes the anonymous guest: `if ($u === null || !empty($u['anon'])) return PHP_SAPI === 'cli';`. A signed-in account still falls through to the role check. The flag used is `anon`, not `guest`. That matters: with sign-in off, `current_user()` returns the anonymous admin with `guest => true` but `anon => false`, so that mode keeps its role check and stays admin on the web, as before.

### 2.3 Security core

| Check | Result |
|---|---|
| php-fpm | The image runs `php:8.3.36-fpm-alpine`; `php-fpm -i` reports `Server API => FPM/FastCGI`, so `PHP_SAPI` is `fpm-fcgi`, never `cli`. |
| Built-in server | `PHP_SAPI` is `cli-server` (probe below). `public/index.php` already checks for `cli-server` separately. |
| CLI entry points that serve HTTP | None. `bin/backup.php`, `migrate.php`, `restore.php`, `seed.php` and `settings.php` exit unless `PHP_SAPI === 'cli'` and only read argv/stdin/files. No code under `src/`, `public/` or `bin/` starts a subprocess (`proc_open`, `exec`, `shell_exec`, `system`, `passthru`, `popen`). nginx runs only `/index.php` and returns 404 for `/bin`, `/src` and every other `*.php`. |
| Where `anon` comes from | Only from `current_user()` itself: `anon => true` is set only on the guest-browsing return, built from constants, and `false` everywhere else. No request input (session, cookie, header, form) reaches it. The only other writer is `current_user($set)`, which only tests call. `bootstrap.php` and `login_session()` only reset the cache. |
| Anon guest on the web | Non-admin. The web probe under `cli-server` at the head, with sign-in on and guest browsing on, gives `{"role":"guest","anon":true}` and `actor_is_admin = false`. Sign-in on with guests off gives `null` / `false`. Sign-in off gives the anonymous admin / `true`, unchanged from `main`. |
| Layered guards | Independently of this function, the Guest role can never hold `users`, `settings` or `backup` (`role_perms_clean()`), and the settings and user routes send a guest to sign-in before any admin guard runs. |

**Callers affected.** `actor_is_admin()` is called by `user_create()` (granting admin), `user_set_role()` (granting admin), `auth_turn_off()`, `guard_admin_target()` (used by approve, set role, set password, rename, delete) and `settings_import()` (sign-in keys). All of them now treat the CLI as admin with guest browsing on, and no web caller changes, because a web SAPI still gets `false`.

**Duplicate checks.** No other code repeats the old `null`-only rule for an admin decision. `public/index.php` uses `$me === null || $me['anon']` only to choose 401 or 403, `views.php` only for display, `current_actor()` returns `null` on the CLI on purpose, and `role_has()` is the role check that the signed-in branch already uses. Nothing else needed the same fix.

### 2.4 Behaviour matrix

**CLI (`bin/settings.php`, SQLite, fresh data directory per state, accounts `admin` (admin) and `ed` (editor)).** On the CLI there is no session, so the user is the anonymous admin (sign-in off), `null` (sign-in on, guests off) or the anonymous guest (sign-in on, guests on).

| Command | Sign-in off | On, guests off | On, guests on: `main` / #75 head | On, guests on: #77 / #75 + #77 |
|---|---|---|---|---|
| `import` with `session_days` | applied | applied | **"Imported 0 settings."**, value unchanged | applied |
| `user password admin` | ok | ok | **refused** (admin account) | ok |
| `user role ed admin` | ok | ok | **refused** (grant admin) | ok |
| `password` (recovery) | ok | ok | **refused** (admin account) | ok |
| `password --off` | ok | ok | **refused** (turn sign-in off) | ok |
| `auth on` | ok | ok | ok | ok |

The first two columns are the same on all four trees.

**Web (built-in server, `cli-server`).** Anon guest: not admin on `main` and at the head. Sign-in on with guests off: no user, not admin. Sign-in off: anonymous admin, admin, unchanged. Signed-in admin and signed-in non-admin: decided by role, as before. That is covered by the new tests (a signed-in custom role with `users`/`settings` is refused, 403), by `PermissionsTest`, and by the HTTP suite (#71's admin-target checks), all green.

**#75 and `php bin/settings.php password`.** #75's upgrade note tells installs *without* sign-in to run `php bin/settings.php password`. With sign-in off, guest browsing doesn't apply (`guest_browsing()` needs sign-in on), and the command works on `main`, on #75's head and with #77. So **#75 does not depend on #77** for its documented path. The same command used as a recovery tool on an install with sign-in *and* guest browsing on (lost admin password), or `password --off`, fails on `main` and on #75 and works only with #77. Merging #75 and #77 in either order is clean in `src/` and conflicts only in CHANGELOG `[Unreleased]`.

### 2.5 Tests

| Leg | Users | Settings | Backup | Permissions | SessionCookie | Total |
|---|---|---|---|---|---|---|
| PHP 8.4, SQLite | 9 | 15 | 20 | 10 | 4 | 58 OK |
| PHP 8.4, PostgreSQL 17 | 9 | 15 | 20 | 10 | 4 | 58 OK |
| PHP 8.3, SQLite | 9 | 15 | 20 | 10 | 4 | 58 OK |
| PHP 8.3, PostgreSQL 17 | 9 | 15 | 20 | 10 | 4 | 58 OK |

That is 232/232. Both new tests (`UsersTest::testCommandLineIsAdminWhenGuestBrowsingIsOn`, `SettingsTest::testCommandLineImportAppliesASecuritySettingWhenGuestBrowsingIsOn`) pass on all four legs. Backup includes the two cross-driver restore tests, which need the PostgreSQL connection. Users + Settings + Backup + Permissions is the 54 Coder reported.

Also on PHP 8.4:
- **Full unit suite (SQLite, PostgreSQL reachable):** 400 tests, 398 pass, 4 skipped, 2 errors. All 6 are environment-only (I-3).
- **Full HTTP suite (`tests/integration.php`, `php -S`, SQLite):** 1536 passed, 0 failed.

### 2.6 Mutations

The mutants ran against the five suites on SQLite and PostgreSQL. M3 also ran against the full HTTP suite and the web probe.

| ID | Mutation | Result |
|---|---|---|
| M1 | Drop the anon clause (back to `main`) | **Caught**: both new tests fail, on both drivers |
| M2 | Invert the SAPI check (`!== 'cli'`) | **Caught**: both new tests fail, on both drivers |
| M3 | Anon returns `true` whatever the SAPI | **Survives**: 58/58 on both drivers, and 1536/1536 in the HTTP suite. The web probe shows the anon guest becoming admin under `cli-server`. See L-1. |
| M3b | `null` or anon returns `true` whatever the SAPI | **Survives**, as M3 |

### 2.7 CHANGELOG gate (at the head, against `main` `205ed23`)

| Check | Result |
|---|---|
| Head contains `main`'s latest release commit | Yes for `205ed23` (v2.6.4) at the time the PR was opened. `main` has since cut v2.6.5, see I-1. |
| `[2.6.4]` and older byte-identical to `main` | Yes, `cmp` matches. Both sides hash to `0d3c14ee…b7411d`. |
| The #77 bullet | One `### Fixed` bullet, only under `[Unreleased]`, linked to #77. `ad21bf1` moved it back out of `[2.6.4]`, where the merge `159c01d` had left it. |
| `check-links` | 199 relative links in 16 files, 0 broken |

### 2.8 Docs

#71's wording now holds with guest browsing on: the `[2.6.3]` bullets ("the command line can still do all of it", "the command line still applies the sign-in settings"), the `guard_admin_target()` docblock ("The command line can."), `docs/control-panel.md` ("`php bin/settings.php import` applies the sign-in settings too") and `docs/user-guide.md` ("The command line still applies the sign-in options"). Before #77 each of them was wrong when sign-in and guest browsing were both on (2.4). No doc needs changing for this PR.

## 3. Findings

### L-1 (Low): no test catches the anon guest becoming admin off the command line

M3 and M3b (2.6) make `actor_is_admin()` return `true` for the anonymous guest without checking the SAPI. Every unit suite and the full HTTP suite still pass, because:
- the unit tests can only run under `cli`, where the right answer is `true` anyway;
- over HTTP, the guest is stopped by the role and route guards (2.3) before `actor_is_admin()` is reached.

The head is correct (2.3 probe), and the layered guards mean this is not reachable today. It is a missing regression test for exactly the property this PR touches. Hardening, follow-up is fine: make the SAPI a parameter (for example `actor_is_admin(?string $sapi = null)` defaulting to `PHP_SAPI`, or a pure helper taking the user and the SAPI), and add a unit test that `null` and the anon guest are not admin for `fpm-fcgi` and `cli-server`, while the anonymous admin with sign-in off still is.

### N-1 (Nit, not from this PR): the CLI import doesn't say which keys it skipped

`bin/settings.php import` prints the count and the errors, but not `ignored`. On `main` the bug showed only as "Imported 0 settings." with no reason, while the web import names the ignored and protected keys. Printing `ignored` on the CLI too would have made this bug visible.

### N-2 (Nit): the recovery commands with guest browsing on have no test

The new tests cover `settings_import()`, `user_set_password()` and `user_set_role()`. `auth_turn_off()` (`password --off`, `auth off`) and the `password` shortcut with guest browsing on were checked only by hand (2.4). One more assertion of `auth_turn_off()` in `testCommandLineIsAdminWhenGuestBrowsingIsOn` would cover the remaining caller.

### I-1 (Info): `main` moved during the review (#72 merged, v2.6.5 released)

`main` is now `351e88e` (`chore(release): v2.6.5`, 14:34 Athens), after #72's merge `895b007` (14:24). The head does not contain it. A simulated merge conflicts only in `CHANGELOG.md` (`[Unreleased]` versus the new `[2.6.5]` section), and `src/users.php` and the tests merge cleanly. Before merge: merge `351e88e`, keep the #77 bullet only under `[Unreleased]`, and leave `[2.6.5]` and older byte-identical to `main`. Then commit this doc unchanged as the last commit. That needs a diff read, a re-run of the CHANGELOG gate and green CI.

### I-2 (Info): #75 does not depend on #77

See 2.4. #75's documented sign-in-off path works without #77. #77 is needed only for the same commands on an install with sign-in and guest browsing on.

### I-3 (Info): environment-only results

- `PgsqlSchemaCreateTest` ×4 (2 errors, 2 skips): the throwaway role has no `CREATEROLE`. Not run with more rights on purpose.
- `OriginalsTest` php-cgi test: skipped, no `php-cgi` on the box.
- `ReleaseTest::testRealChangelogUpgradeNoteLeadsTheNextReleaseNotes`: skipped because there is no upgrade note under `[Unreleased]`, which is correct for this PR.

None is in the suites the PR touches, and CI passes them.

### I-4 (Info): CI4 parity

`feature/ci4` (`3146170`) has no port of `actor_is_admin()` or the admin-target guard yet, so it doesn't have this bug. When the guard is ported, it should carry the same rule (the anon guest counts as nobody, and only the command line, `spark`, is admin then), plus L-1's SAPI test.

### I-5 (Info): sign-in-off mode unchanged

Keying on `anon` rather than `guest` keeps the anonymous admin of sign-in-off installs an admin on the web and the CLI (2.2, 2.4), as on `main`.

## 4. Security

- **The CLI branch can't be reached from the web.** It needs `PHP_SAPI === 'cli'`, which php-fpm (`fpm-fcgi`) and the built-in server (`cli-server`) never report. No CLI entry point serves HTTP or is run with request input, and nginx executes only `index.php`.
- **`anon` can't be forged.** It is computed server-side in `current_user()` from constants, and no request data reaches it. A browser user can't get `anon` set and become admin: at the head, the anon guest is not admin on the web, and its role can never hold `users`, `settings` or `backup`.
- **Signed-in accounts are unchanged.** They still go through the role check, so a signed-in non-admin with the Users or Settings permission is still refused (403) on admin targets and sign-in keys (new tests, `PermissionsTest`, HTTP suite).
- **No privilege is widened beyond the documented one:** the command line is admin in all three sign-in states, as #71 documented.
- **L-1** is a missing regression test, not a vulnerability at this head.
- **Secrets and host names:** the diff against `main` has no tokens, keys, account ids or private host names. The only literals are test passwords (`long-enough`, `fresh-pass`, `other-pass`) and the `github.com` PR link. CI's secret scan passed.
- **No High or Medium findings.**

## 5. Gate summary

| Gate | Status |
|---|---|
| CI green at `ad21bf1` | Yes, 10/10 |
| Docs and tests in the PR | Yes, 2 new tests; no doc change needed (2.8) |
| QA with a Security section, no High or Medium | Yes (this doc) |
| CHANGELOG gate | PASS at `ad21bf1` against `205ed23`; to re-run after merging v2.6.5 (I-1) |
| PM secret scan | PM to run |
| Merge pinned to the `docs(qa):` head | after merging `main` (v2.6.5) and committing this doc unchanged as the last commit |
