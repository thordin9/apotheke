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
3. **Exact match in the rule.** The PHPStan `NoServiceRule` allows only files directly in
   `ci4/modules/<Module>/Filters/`, inside `before()`/`after()`. Any other class is still flagged
   (`tests/Unit/Ci4DiRuleTest.php`).

## Consequences

* Pull requests for the port target `feature/ci4`. The cutover, version 3.0.0, is the pull request into `main`.
* After 3.0.0, the 2.5.x line gets security fixes only, on `release/2.5`, for 90 days.
* Rollback to the 2.5.x image needs no data step, because the schema does not move ahead of 2.5.x.
* Pages that still say "no framework" describe the legacy app until the documentation rewrite.
