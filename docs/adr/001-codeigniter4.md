# ADR-001: Adopt CodeIgniter 4

## Status

Accepted. Stathis approved this on 2026-10-20 at 10:09 EEST ("yes to all"), including the decisions below.
The working plan is [Feature 12](../plans/feature12-ci4/PLAN.md).

## Context

Apotheke 2.5 is a plain-PHP application: no framework and no Composer packages at runtime. The 3.0 port
needs a maintained HTTP framework, sessions, filters and a module layout, without changing the database
or the behaviour users already rely on.

## Decision

1. **CodeIgniter 4** replaces the no-framework rule. Dependencies are pinned. No other framework is added.
2. **Side by side.** The port is built in `ci4/` next to the legacy tree. The cutover moves `ci4/` to the
   repository root. Until then, legacy `main` stays as it is.
3. **No schema change.** The 25 migration files stay byte-identical. They run through a PDO runner in its
   own module, with a `LegacyFunctions` shim that stays after the cutover.
4. **Docker-only after cutover.** From 3.0.0 the supported install is the Docker image. There is no
   non-Docker install and no vendored zip.
5. **One re-login.** Sessions are not migrated. Users sign in again once at upgrade.
6. **PHP 8.4.** The CI4 image and the CI4 leg on `feature/ci4` run PHP 8.4 (legacy@8.3 and legacy@8.4 PHPUnit also run on that branch). The legacy 2.5.x image stays on PHP 8.3.
7. **Unchanged constraints.** CI4 keeps the same constraints: no CDN, no JavaScript build step, works fully offline
   on a LAN. The only runtime Composer packages are CodeIgniter 4 and its pinned dependencies.

Module boundaries for the port (dependency graph enforced by deptrac, constructor injection, `service()`
only in controllers and filters) are in [AGENTS.md](../../AGENTS.md#ci4-module-rules).

Filters may use the shared services because CI4 builds them with `new $class()` and no constructor
arguments. Three conditions apply, so a long-lived worker (FrankenPHP, the Cloudflare phase) never carries
one request's state into the next:

1. **Inside `before()`/`after()` only.** A filter resolves a service inside its `before()` or `after()`
   method, never in a constructor, a helper, a closure, a property or a static. Filters declare no
   properties (`ci4/tests/Core/FilterStateTest.php`).
2. **No cached request or settings state.** A service a filter uses keeps nothing across requests: for
   example `Services::sessionLifetime()` reads `session_days` through the request's `SettingsService` on
   every call (`ci4/tests/Core/SessionCookieWorkerTest.php`).
   `$_SESSION` counts too: the session filter starts every request with an empty array, so a request
   without a live cookie never sees the previous request's session (#76 L-3).
3. **Exact match in the rule.** The PHPStan `NoServiceRule` allows only files directly in
   `ci4/modules/<Module>/Filters/`, inside `before()`/`after()`. Any other class is still flagged
   (`tests/Unit/Ci4DiRuleTest.php`).

Request hardening ported from `main` (SEC-M3, SEC-L2, SEC-N6, SEC-M1, SEC-M2):

* **Required before-filters, in order:** `headasget`, `harness`, `originalsguard`, `hostcheck`, `publicbase`,
  `posttoolarge`, `session`, `sameorigin`. Required filters run before routing, so the Host check also covers
  `/healthz` and unknown paths. The Host check answers 421 and never echoes the name. `Config\App` sets
  `allowedHostnames` from `PUBLIC_URL` and `ALLOWED_HOSTS`, and its `baseURL` is only a fallback that reads no
  database.
* **Base URL per request (#76 L-2).** `publicbase` builds CI4's base URL the way legacy `public_url()` does:
  the `public_url` setting (its default is `PUBLIC_URL`), else this request's scheme with `trust_proxy` and a
  Host that passed the allow-list, else `localhost`. It sets the request's `App` config and URI, which CI4
  rebuilds for every request, so `base_url()`, `site_url()` and `redirect()->to()` follow the Control Panel.
  Apotheke's own redirects send a relative `Location`, as legacy does.
* **Sessions on demand.** The session filter only sets cookie parameters. It resumes a session only when the
  cookie names an existing, well-formed session file. `SessionToken` (the port of `csrf_token()`) starts one
  when a page needs a token. An anonymous request leaves no session file and no cookie.
  CI4's `session` service is `NativeSession` (#76 L-1): it reads the native session's `$_SESSION` and never
  starts, regenerates or destroys a handler-backed session of its own, so `storePreviousURL()` on an HTML
  answer cannot add a second session file or change the id. It is read-only: `set()`, `remove()` and the
  flash and temp data methods change nothing, and `close()` write-closes a session a request left open. The
  session filter also closes such a session before it resets `$_SESSION` for the next request.
* **Sign-in limits.** As legacy `init_session()` on `main`: `SessionGate` ends a signed-in session after
  `session_idle_hours` with no request (the stored number, clamped to 1–8760), and `session_days` after the
  sign-in time `signed_in_at`, which later requests never move. A session without that time gets it on its next
  request. Any other session ends after `session_days` with no request. The activity stamp `last_activity`
  moves at most once a minute. The session file keys are the legacy ones.
* **Sign out everywhere.** Each account has a session epoch (migration 0028, the same file as `main`). A
  session is valid only while it holds the current one. Signing out everywhere or a new password stores a new
  one; an account without a stored epoch uses the password-hash fingerprint that 0028 fills in, so the upgrade
  signs nobody out. `SessionGate::signIn()` writes a sign-in into the session; signing the same account in
  again with `keepSignIn` keeps `signed_in_at`. The routes and buttons arrive with the Auth pages (PR 7).
* **Actor gate.** `SettingsService` takes an `ActorGate`. On the web it is the signed-in user (`CurrentUser`);
  without one it is `Nobody`. The spark commands pass `CommandLine`, which is unrestricted, as on legacy's CLI.
  `import()` ignores the sign-in (security) section for an actor that is not an admin.
  `CurrentUser` never counts nobody or the anonymous guest as admin, whatever role it carries (main #77). Main
  decides that by `PHP_SAPI`; CI4 has no SAPI check, because the command line is admin only as `CommandLine`.
* **Client address.** CI4's `proxyIPs` stays empty. `ClientAddress` reads `X-Forwarded-For` only when
  `trust_proxy` is on and the peer is in `TRUSTED_PROXIES`, and takes the rightmost hop that is not a proxy.
* **One connection per request.** `Config\App` reads nothing from the database. `trust_proxy` and `public_url`
  are read through the request's `SettingsService` on CI4's own connection. `/healthz` reads no settings for
  built-in host names (addresses, `localhost`, this machine); for any other name the Host check reads
  `public_url` (#76 N-3).

## Consequences

* Pull requests for the port target `feature/ci4`. The cutover, version 3.0.0, is the pull request into `main`.
* After 3.0.0, the 2.5.x line gets security fixes only, on `release/2.5`, for 90 days.
* Rollback to the 2.5.x image needs no data step, because the schema does not move ahead of 2.5.x.
* Pages that still say "no framework" describe the legacy app until the documentation rewrite.
