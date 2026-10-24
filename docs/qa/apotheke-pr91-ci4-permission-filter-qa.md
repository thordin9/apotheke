# QA: apotheke #91, CI4 Permission filter on every request (PLAN PR 7)

- **PR:** [#91](https://github.com/thordin9/apotheke/pull/91), `feat(ci4): apply the Permission filter to every request`. Full gate (authorization). Closes #90 N-10.
- **Head:** `a070e58`. **Base:** `feature/ci4` `b2ba96d`. 6 commits. First review at `7ec2696` (CHANGES REQUESTED, M-1 and L-1); fix commits `e68c00f` (fix), `5ff7e5b` (tests), `a070e58` (docs).
- **Verdict: PASS at `a070e58f71a9cb66f10b10074ff07b63e2e17ed0`.** 0 High, 0 Medium, 0 Low open; 2 open Nits (N-14, N-15).

## Checklist

| Check | Result |
|---|---|
| CI | 22/22 as reported by Coder2; the GitHub API isn't reachable from here. |
| Range | `7ec2696..a070e58` touches only the Auth module, `Routes.php`, the two permission test files, the test router (one route option), AGENTS, CHANGELOG, HANDOFF and PLAN. No legacy code changed. |
| Filter order | `permission` is the last required before-filter (after `session` and `sameorigin`), before the global `sessioncsrf`. Pinned by a test. |
| Routed path (M-1) | A fresh Router matches the request first. A matched route gives its `permission` option; a path no route matches gets legacy `route_perm()` on the path normalised like `Router::handle()`. Over HTTP, 100 path variants per visitor (22 forms × 4 routes, plus spoofed and real PUT/PATCH/DELETE): every variant that reaches a controller is refused to roles without the route's permission (Edit-only, Editor on settings), allowed for roles with it, and 303 for signed-out and guest visitors. The new parity variant test fails against `7ec2696`. |
| Base URL (L-1) | The path is relative to the base URL. With a `PUBLIC_URL` that has a path, Editor and Edit-only roles are refused as without it. |
| Fail-closed | With a route's option removed, admin and sign-in-off get 403 and signed-out visitors get 303. |
| `_method` | The effective method is used. A spoofed PUT/PATCH/DELETE never reaches a POST route (404), and route options don't depend on the method, so no write becomes a read. |
| Parity | `route_perm()`/`user_can()` logic unchanged from the first review. 418 exact HTTP cases (11 visitor kinds) are identical; variant cases follow the stated rule. Sign-in off is unchanged. |
| Worker phase | The filter keeps no static or property state. The Router is new per request (`getShared=false`). `loadRoutes()` is idempotent, and the only change to the shared RouteCollection is `setHTTPVerb()` to this request's method, which the dispatching Router then sets to the same value. |
| Tests, PHP 8.4 | CI4 module suite: SQLite 182 OK, PostgreSQL 17 222 OK. Full PHPUnit (`APOTHEKE_APP=ci4`): SQLite 606 (39 skipped, 1 incomplete), PostgreSQL 17 606 (6 skipped), CI4 PostgreSQL proof OK. Legacy suite not re-run (no legacy files changed; 606 OK at `7ec2696`). |
| Mutations | 32 of 34 caught: the 22 from the first review (the 3 earlier survivors now caught) and 10 of 12 new ones on routing, normalisation, the base URL, fail-closed and `none`. 2 equivalent survivors: removing the null-match check (unreachable, since auto-routing is off and `handle()` throws) and mapping a spoofed method to GET (no PUT/PATCH/DELETE routes exist). |
| CHANGELOG, docs | The `[Unreleased]` › Fixed bullet is updated. `## [2.` to EOF is byte-identical to `main` v2.6.13. AGENTS, HANDOFF and PLAN describe the route option. `check-links`: 270 links, 0 broken. |
| Repo hygiene | `.gitleaks.toml` unchanged. gitleaks 8.30.1 finds nothing in `7ec2696..a070e58`. `check-commits` ok (3). deptrac and the DI rule: 0 errors. Only reserved example hosts in the diff. |

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| M-1 | Medium | Resolved | The filter now uses the path CI4's router matches. |
| L-1 | Low | Resolved | The path is taken relative to the base URL. |
| N-12 | Nit | Resolved | HEAD mapping, decoding and the HTML escape each have a test. |
| N-13 | Nit | Resolved, accepted deviation (a) | The permission follows the effective method. A guest's POST overridden to PUT/PATCH/DELETE on an unknown path gets 404, not legacy's redirect to `/login`. Nothing is reached and nothing changes; accepted. |
| N-14 | Nit | Open, accepted for the follow-up batch (b) | `RoleRepository` falls back to the built-in roles on any query error. Narrow it to database errors, as legacy does. |
| N-15 | Nit | Open, informational | Paths CI4 trims into a route take that route's permission. So a branding GET with a trailing slash stays public, where legacy needs View; branding is public anyway. Paths with characters CI4 disallows (control characters, `;`) give 500 instead of 400, as before this PR. A redirect route would raise inside the filter; none exist. |

`#90` N-10 is fixed. N-8, N-9 and N-11 are unchanged.

## Security

- Authorization runs before CSRF and the controllers on every CI4 request. It uses the route the router actually dispatches, so path-normalization and base-path differences no longer change the rule (M-1, L-1).
- A route without a recorded permission is refused to everyone (fail-closed). Anonymous and guest visitors are never treated as signed in, and an unknown role has no permissions.
- Worker phase: nothing blocks the FrankenPHP worker / Cloudflare Container phase. The router is new per request, and roles and the user are read per request.
- No secrets, hosts or tokens in the diff or this page.

**Verdict: PASS at `a070e58f71a9cb66f10b10074ff07b63e2e17ed0`** (0H / 0M / 0L open; N-14 and N-15 open as Nits).
