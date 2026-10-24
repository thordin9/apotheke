# QA: apotheke PR #71, security hardening SEC-M1/L1/M2/N2/N3 (main)

- **PR:** [#71](https://github.com/thordin9/apotheke/pull/71), "fix(auth): harden sign-in, import, paging, and the database line", into `main`, by Coder. Hardening for SEC-M1, SEC-L1, SEC-M2, SEC-N2 and SEC-N3 from the apotheke security audit (`/workspace/qa-docs/security-audit-apotheke-2026-10-24.md`).
- **Head reviewed (final):** `f59b58529904a8b3606d0cfee0c25ba2902915e4`. Base `main` at `6251870` (the v2.6.2 release commit). 9 commits, mergeable.
- **Head history during the review:** the branch moved three times while I reviewed it, each time for a reason I verified:
  1. `9e1e043` — the original 5-commit branch on `a6e89c7`. Fully reviewed (CI, suites, repros, mutations); PASS on its own.
  2. `6cb7549` — after #70 merged to `main` (`242c534`), Coder merged `main` in (`b1c9a55`) and aligned the proxy wording (`6cb7549`). Reviewed the delta; the runtime/tests were sound, but I found a **blocking CHANGELOG/release defect** (F-1 below).
  3. `f59b585` — after v2.6.2 was cut from `main` (`6251870`), Coder merged current `main` in (`7c99f0c`) and moved #71's notes back out of the released section (`f59b585`, `docs(changelog): keep the #71 notes out of v2.6.2`). F-1 is **resolved**; this is the final head.
- **Changes vs `main` (`6251870`):** runtime `src/{config,users,settings,items}.php` and `public/index.php`; tests `tests/Unit/{Activity,Backup,Config,Permissions,Repository,Settings,Users}Test.php` and `tests/integration.php`; docs `CHANGELOG.md`, `README.md`, `config.example.php`, `docs/{configuration,control-panel,deployment-and-operations,user-guide}.md`. (The #70 hardening — Host check, sessions/cookie, nginx, ImageMagick policy — is already in `main` as of v2.6.2 and arrived in the branch through the merges; it is not #71's own change.)
- **CI:** run 40082425210 at `f59b585` (`pull_request`), **10/10 `success`** (12:33–12:43 Athens time, UTC+3). The earlier heads were also green (`9e1e043`: run 40077439680 10/10; `6cb7549`: run 40081160925 10/10).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only. No comment, review, push or merge was made, and nothing was posted. Sources: codeload tarballs of `f59b585`, `6cb7549`, `9e1e043`, `a6e89c7`, `242c534`, `6251870` and #70's `0926503`; `raw.githubusercontent.com`; `api.github.com`; the GitHub connector. Local runs only, in scratch copies, against a throwaway PostgreSQL 17 and SQLite, in private databases (`qa2_71_*`), on ports 9401–9409. (QA's #74 review runs in parallel on 9501–9509 / `qa2_74_`; no overlap.)
- **Verdict: PASS at `f59b585`.** The one blocking finding (F-1, the CHANGELOG/release duplication at `6cb7549`) is fixed at this head and re-verified. Remaining items are two Nits, both anticipated by the audit. Every claimed fix is in and does what the audit asked; all 11 mutations are caught.

## 1. Test plan

### 1.1 Environment

- **No Docker on this box.** Suites ran on the box's PHP **8.3.36** and **8.4.27** CLIs (gd, imagick, pdo_pgsql, pgsql, pdo_sqlite, zip), with Composer dev dependencies from the locked `composer.lock`.
- PostgreSQL **17** on `127.0.0.1:5432`, test-only superuser `apotheke` with the CI public test password (written as `<CI test password>` here). Each pgsql leg used its own database (`qa2_71_f83`, `qa2_71_f84`); dynamic probes used SQLite and scratch `DATA_DIR`s.
- Dynamic probes used the legacy app under PHP's built-in server on ports 9401–9403, `APP_PASSWORD` set (sign-in on), probe accounts with a throwaway local password (not recorded). The new head answers the Host allow-list (SEC-M3, from #70): an IP host is accepted, `evil.example` and a malformed host get 421.
- gitleaks **8.30.1** with the repo `.gitleaks.toml`; `scripts/check-links.mjs` and `scripts/release.php` for the link and release checks.

### 1.2 Acceptance checks

CI and `scripts/check-commits.sh`; audit repros for M1/L1/M2/N2/N3 re-run against the head and mutations; M1 (import + restore); L1 (the Users-escalation probe incl. role creation and Coder's `5cb654b` claim); M2 (per-(client,account) throttle and XFF); N2/N3; docs/CHANGELOG and the release simulation; a Security section and gitleaks; suites on 8.3 and 8.4, each on SQLite and PG17.

## 2. CI and commits (item 1)

- **Run 40082425210** (`f59b585`, `pull_request`): **10/10 `success`** (12:33–12:43 Athens, UTC+3): Lint, Headless mobile checks, Docker build + smoke test, Visual E2E shards 1–3, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Conventional Commits, Secret scan.
- **Commit subjects:** every non-merge subject is Conventional Commits, ≤72 chars, and `scripts/check-commits.sh --message` passes each (`fix(auth):`, `docs(auth):`, `docs(changelog):`); the two merge commits are skipped by the script's `^Merge` rule. Compare API: `6251870..f59b585` adds the #71 work and the two merges, 0 behind at the final head.

**PASS**

## 3. Suites (item 9)

Fresh databases and servers; stale `qa2_71_*` DBs dropped and ports cleared before the run.

| Leg | Result |
|---|---|
| PHP 8.3.36, SQLite | **OK**, 399 tests, 2888 assertions, 2 skipped (environmental: `php-cgi` dev-server test, HEIC-dependent autofill case) |
| PHP 8.3.36, PostgreSQL 17 (`qa2_71_f83`) | **OK**, 399 tests, 2889 assertions, 2 skipped |
| PHP 8.4.27, SQLite | **OK**, 399 tests, 2888 assertions, 2 skipped |
| PHP 8.4.27, PostgreSQL 17 (`qa2_71_f84`) | **OK**, 399 tests, 2889 assertions, 2 skipped |

- 399 tests = #71's new unit/integration tests plus #70's (`HostAllowListTest`, `PublicUrlTest`, `SessionCookieTest`, `integration_hardening.php`), now present via `main`.
- The new #71 unit tests passed on every leg: `SettingsTest::{testImportSkipsSignInSettingsWithoutUsersPermission, …WithOnlyUsersPermission, testDatabaseLabelShowsOnlyHostPortAndName}`, `BackupTest::{testRestoreSkipsSignInSettingsUnlessTheCallerIsAdmin, testRestoreRefusesUsersAndRolesTables}`, `UsersTest::{testOnlyAnAdminCanGrantTheAdminRole, …ChangeAnAdminAccount, …TurnSignInOff}`, `PermissionsTest::{testSignInLimitIsPerClientAndAccount, testClientAddressUsesForwardedForOnlyFromConfiguredProxies}`, `ConfigTest::testTrustedProxiesFromEnvAndFile`, `ActivityTest::testAHugePageReturnsNoRows`, `RepositoryTest::testAHugePageDoesNotFailTheQuery`.
- The HTTP integration suite passed, including the new "Sign-in settings follow the caller" and "Database line hides the password" sections.

**PASS**

## 4. Audit repros re-run against the head (item 2), and mutations

Each finding was reproduced on pre-fix code to confirm it is real, re-run on the head to confirm it is blocked, then each fix was reverted in a scratch copy and the matching test was shown to fail. Results were identical at `9e1e043`, `6cb7549` and `f59b585` (the runtime/test code is byte-identical across them — see §8).

| Finding | Pre-fix | On head | Mutation (revert fix → test) |
|---|---|---|---|
| **SEC-M1** import | a `settings`-only role imports all 6 sign-in keys | the 6 sign-in keys are skipped (listed as ignored); other keys still apply | drop the `security`-section admin guard → `SettingsTest::testImportSkipsSignInSettings*` fail (2) |
| **SEC-M1** restore | a `backup`-only restore applies a crafted `settings` block | restore skips the sign-in keys unless the caller is admin; a zip naming `users`/`roles` is refused | drop the guard → `BackupTest::testRestoreSkipsSignInSettings…` fails; bypass the table allow-list → `…testRestoreRefusesUsersAndRolesTables` fails |
| **SEC-L1** | a `users`-only role grants itself `admin`, `/backup` 200 | grant-admin, admin-account edits, sign-in-off all 403 for non-admins | remove each guard → `UsersTest::testOnlyAnAdmin…` each fail (3) |
| **SEC-M2** | throttle on `REMOTE_ADDR` only; one client locks everyone | keyed on `(client, account)`; XFF only from configured proxies | key on client only → `PermissionsTest::testSignInLimitIsPerClientAndAccount` errors; always trust XFF → `…testClientAddressUsesForwardedForOnlyFromConfiguredProxies` fails |
| **SEC-N2** | `?page=INT_MAX` on `/` and `/activity` → 500 | both → 200 (clamped 1–100000) | drop `min(PAGE_MAX,…)` → `ActivityTest::testAHugePageReturnsNoRows`, `RepositoryTest::testAHugePageDoesNotFailTheQuery` error |
| **SEC-N3** | `password=[^;]*` mask misses `password = <value>` | the database line shows only host/port/dbname | revert `settings_database_label()` → `SettingsTest::testDatabaseLabelShowsOnlyHostPortAndName` fails |
| trusted_proxies | n/a (new) | IPv4/IPv6/CIDR validated and normalised | drop `config_proxy_list()` → `ConfigTest::testTrustedProxiesFromEnvAndFile` fails |

- **All 11 mutations caught.** Each file was restored after each mutation.

**PASS**

## 5. SEC-M1 — settings import and backup restore (item 3)

Confirmed on the head (SQLite and PG). A `settings`-only role (`cp`), a `users`-only role (`helpdesk`), a `settings`+`users` role, and a `backup`-only role (`bk`) were used.

- **Import (JSON body and file upload):** a `settings`-only caller importing `auth_guest_disabled`, `auth_register_disabled`, `auth_register_role`, `auth_register_approval`, `session_days`, `trust_proxy` has all six **skipped** and listed among the ignored keys; a non-security key (`app_name`) still applies. Case changes (`AUTH_REGISTER_DISABLED`, `Auth_Guest_Disabled`), leading/trailing spaces, and `auth_enabled`/`password_hash`/`autofill_token`/`logo` all do nothing ("Imported 0 settings"); string/int bool forms are also skipped for a non-admin. A `settings`+`users` caller is still blocked (admin role required, not the `users` permission). A `users`-only caller is 403 at the route (`/settings/import` needs `settings`). An admin import applies all six and reverts cleanly.
- **Backup restore:** `backup.zip` has no `users`/`roles` tables, no `password_hash`, no secrets. A `backup`-only restore applies non-security settings but **skips the sign-in keys** (same guard, non-admin actor); an admin restore applies them. A zip adding `users`, `roles`, `role_permissions`, `auth_attempts`, `settings`, `sessions`, `undo_tokens`, `recent_views`, `search_meta`, `sqlite_sequence`, or case/space/quote variants (`Users`, `"users"`, `` `users` ``, `main.users`, `users `) or an injection-shaped name is refused with "Unknown table in backup" before any DB write. A raw `app_settings` row setting `auth_enabled=false` does **not** turn sign-in off (the settings table is `settings`, restored only through the filtered import). Users and roles were unchanged after every attempt.

**PASS**

## 6. SEC-L1 — the escalation probe (item 4)

Confirmed on the head (SQLite and PG). A `helpdesk` role (`view`, `users`) was the attacker; a second admin and a pending admin were targets.

- **Direct admin actions, all 403 for a `users`-only caller:** demote/rename/set-password/delete/approve/delete-pending an admin ("Only an admin can change an admin account"); grant `admin` to self/another, create an admin account ("Only an admin can grant the admin role"); turn sign-in off ("Only an admin can turn sign-in off"). `role=ADMIN` (uppercase) is 422 at validation. Admin accounts and `auth_enabled` unchanged.
- **Role-creation chain (Coder's `5cb654b` claim):** a `users` holder can create a `power` role with all 14 permissions and assign it to itself/a puppet (by design). From there, granting `admin`, turning sign-in off, and editing an admin all remain **403**; importing sign-in settings while holding `settings` is **skipped** ("Imported 0 settings") — **confirming the `5cb654b` claim**: import/restore applies sign-in settings only for an admin. The autofill token and DB password are not exposed on `/settings/{autofill,system,export.json,data,security}`; testing a typed autofill URL sends **no** `Authorization` header, and saving a new URL clears the stored token — so "read secrets" fails.
- **Role edits:** a role named `Admin`/`admin!` collides with the built-in `admin` key (409); the built-in `admin` role cannot be edited/renamed over; `guest` cannot be widened to `users`/`settings`/`backup`; the last-admin and last-users-manager lockout guards hold.
- **Indirect, by design once the toggles are on:** a `users` holder can widen the Guest role (except the three gated perms) and open guest browsing / registration with a chosen non-admin role. But the registration role can never be `admin`, and a registrant holding a 14-perm custom role still cannot grant itself `admin` (403). The chain's ceiling is whatever non-admin permissions the `users` holder deliberately grants — the documented meaning of `users`, now spelled out in the docs. Graded **Nit** (N-1).

**PASS** (SEC-L1 addressed: `users` no longer reaches admin, sign-in-off, admin-account edits, or secret reads.)

## 7. SEC-M2 — the throttle and X-Forwarded-For (item 5)

Confirmed on the head (SQLite). Servers were run with `TRUSTED_PROXIES` empty, `=127.0.0.1`, and `=10.0.0.0/8,192.0.2.0/24`, toggling `trust_proxy`.

- **trust_proxy off, empty list:** spoofed rotating `X-Forwarded-For` is ignored; 10 wrong attempts for one account from the connecting address lock **that account from that client** (correct password also 429), while a **different account** from the same address still signs in (303). Key is `(client, account)`.
- **trust_proxy on, empty list:** `X-Forwarded-For` still ignored (one distinct client key for rotating XFF).
- **trust_proxy on, peer not in list:** header ignored, connecting address used.
- **trust_proxy on, peer in list:** the client is the **rightmost `X-Forwarded-For` hop that is not itself a configured proxy**. Per-client isolation holds (10 wrong from one forwarded client lock only that client; another forwarded client signs in).
- **Shared-proxy lockout (the original M2):** an attacker burning many accounts from one real client does **not** lock a legitimate user of the same account on a different client — the global lockout is gone.
- **Per-account brute force / targeted lockout:** 14 wrong attempts for one account from 14 distinct clients never trip the limit and the account still signs in; distributed per-account brute force is throttled only by the 0.4 s sleep (no global per-account cap). A targeted single-account lockout from one shared-proxy client is possible for that one account for 15 min. Both are inherent to an IP×account throttle and far narrower than the pre-fix "lock everybody out"; the audit listed a per-account cap as *optional*. Graded **Nit** (N-2).

**PASS** (SEC-M2 addressed.)

## 8. SEC-N2, SEC-N3 (item 6); the CHANGELOG/release checks (item 7); Security + gitleaks (item 8)

- **SEC-N2:** `?page` on `/` and `/activity` is clamped (`clamp_page`, 1–100000). `page=9223372036854775807`, `=99999999999999999999`, `=100001`, `=-1`, `=0`, `=abc`, `=1e9` all return 200 on the head; the same `INT_MAX` value is a 500 on pre-fix code. Over-range pages return no rows.
- **SEC-N3:** the Control Panel database line (`settings_database_label()` via `config_dsn_public_fields()`) shows only `host=… port=… dbname=…`. A DSN written `…;password = <value>;…` (spaces around `=`) no longer leaks (verified live on the PG server, and by the new integration test that uses `password = spaced-secret`).
- **Docs:** `README.md`, `config.example.php`, `docs/{configuration,control-panel,deployment-and-operations,user-guide}.md` document `trusted_proxies`/`TRUSTED_PROXIES`, the admin-only actions, the per-(client,account) limit, and the admin-only import/restore of sign-in settings. The `6cb7549` alignment commit tightened the XFF wording consistently across all five files to: "`X-Forwarded-For` is read only when `trust_proxy` is on and the connecting peer is listed in `trusted_proxies`/`TRUSTED_PROXIES`; the client is the rightmost hop that isn't itself a listed proxy; with `trust_proxy` on and an empty list, the connecting address is used." **This matches the code's actual behaviour**, which I verified point-by-point in §7. #70's L3/Host-names and cookie-`Secure` text is preserved in the merged deployment doc.
- **check-links:** `scripts/release.php check-links` → exit 0; `scripts/check-links.mjs` → "198 relative links checked in 16 files, 0 broken".
- **#70 interaction / merge integrity:** every #70 file (`src/hosts.php`, `src/bootstrap.php`, `src/views.php`, `docker/nginx.conf`, `docker/imagemagick-policy.xml`, `Dockerfile`, `scripts/imagick-policy-check.php`, `scripts/docker-auth-checks.sh`, `HostAllowListTest`, `PublicUrlTest`, `SessionCookieTest`, `integration_hardening.php`) is **byte-identical** between the merged head and `main` (`242c534`) — nothing from #70 was lost in the merge. #70's Host check works alongside #71's throttle/XFF on the merged head (IP host 200, bad host 421, with the per-(client,account) throttle intact).
- **CHANGELOG / release simulation:** at the final head the `[2.6.2]` section is **byte-identical to current `main`'s**, and `[Unreleased]` holds **exactly #71's five bullets** (M1, L1, M2, N2, N3), each linked, hardening-worded with SEC-IDs and no repro/exploit text. Simulating the merge onto current `main` (`6251870`) and running `scripts/release.php apply` yields a **v2.6.3** section with exactly those five bullets, a clean `v2.6.2...v2.6.3` compare link, and `[Unreleased]` emptied — no #70 bullet or upgrade note duplicated. `release.php plan` → `bump=patch, next=2.6.3`. This is the fix for F-1.
- **Security section + gitleaks:** gitleaks 8.30.1 with the repo config — directory scan and full history **no leaks**. The only secret-shaped literal in the diff is the test placeholder `spaced-secret` (used by the N3 tests). None of the known fakes (`SecretMissingEq99`, `s3cret`, `s9d44`, `zzpass9`, `dsnu`/`dsnp`, `p-secret`) appears as a real credential. New runtime surface (`config_proxy_*`, `client_address*`, `actor_is_admin`, `guard_admin_target`, `settings_database_label`, `clamp_page`) reviewed: IP parsing via `inet_pton`/`filter_var` with bounded CIDR bits; no shell; table/column names stay constants; the restore allow-list is unchanged; no secret is logged or rendered.

**PASS**

## 9. Code-path stability across heads (item 7, parent request)

- `9e1e043` → `6cb7549`: the merge brought in #70's files; **`src/users.php` auto-merged with no conflict**; `src/config.php` and `public/index.php` merged cleanly and keep #71's full content (verified function-by-function: `config_proxy_*`, `client_address*`, `rate_limit($kind,$account)`, `clamp_page`). Only CHANGELOG/docs conflicts, resolved in `b1c9a55`; wording aligned in `6cb7549`.
- `6cb7549` → `f59b585`: **`src/`, `public/`, `tests/`, `scripts/`, `docker/` are byte-identical.** Only `CHANGELOG.md`, `README.md` (a regenerated coverage-table number) and `VERSION` (2.6.1→2.6.2, from merging the release commit) changed. So the §4–§8 runtime results carry over unchanged; I also re-ran all four suites and the M1/L1/M2 repros at `f59b585` and got identical results.

## 10. Findings

| Id | Sev | Finding | Status |
|---|---|---|---|
| **F-1** | **High (blocked)** | At `6cb7549`, merging #71 onto `main` (after v2.6.2 was released) left `[Unreleased]` empty and dropped #71's five bullets **into the already-released `[2.6.2]` section**. On merge the auto-release would have cut v2.6.3 with no handwritten notes and corrupted the published v2.6.2 changelog. | **Resolved at `f59b585`** (`7c99f0c` merge of current `main` + `f59b585` `docs(changelog): keep the #71 notes out of v2.6.2`). Verified: `[2.6.2]` byte-identical to `main`; `[Unreleased]` = exactly #71's 5 bullets; `release.php apply` → v2.6.3 with exactly those 5 bullets; check-links passes. |
| N-1 | Nit | A `users`-permission holder can, by design, create a custom role and open guest browsing/registration with a chosen non-admin role, so it can delegate `settings`/`backup` and other non-admin permissions to accounts. It can no longer reach `admin`, turn sign-in off, change an admin, or read secrets. The docs now state this. | Non-blocking; documented. |
| N-2 | Nit | No global per-account sign-in cap: a distributed attacker (many client IPs, or spoofed XFF via a trusted proxy) can brute-force one account, slowed only by the 0.4 s sleep; a narrow single-account lockout from one shared-proxy client is also possible. | Non-blocking; the audit listed per-account backoff as optional. |
| I-1 | Info | `README.md` differs from `main` only in regenerated coverage-table numbers. | None. |

No open High or Medium findings at `f59b585`.

## 11. Secrets in this doc

No real secret appears here. The CI test password is written as `<CI test password>`; `spaced-secret`, `p-secret` and `zzpass9` are known test placeholders.

## 12. Verdict

**PASS at `f59b58529904a8b3606d0cfee0c25ba2902915e4`.**

- CI: run 40082425210, 10/10 `success`.
- Suites: PHP 8.3 and 8.4 green on SQLite and PostgreSQL 17 (399 tests, 2 environmental skips), plus the HTTP integration suite with the new sign-in/XFF/DSN cases.
- Fixes: SEC-M1, SEC-L1 (Coder's `5cb654b` claim verified), SEC-M2, SEC-N2, SEC-N3 — each reproduced pre-fix, confirmed blocked on the head, and protected by a test that fails when reverted (11/11 mutations caught).
- Merge integrity: all of #70's files are byte-identical to `main`; #71's runtime code is unchanged since `6cb7549`; the Host check and the throttle/XFF coexist; the aligned proxy wording matches the code.
- CHANGELOG/release: the blocking F-1 is fixed — `[2.6.2]` is byte-identical to `main`, `[Unreleased]` carries exactly #71's five bullets, and a simulated merge + `release.php apply` cuts a clean v2.6.3 with exactly those bullets; check-links passes.
- Security: gitleaks clean (tree and history); no new secrets; no weaponized wording in the PR or this doc.
- Open items: nits N-1 and N-2 (both non-blocking, both anticipated by the audit).
