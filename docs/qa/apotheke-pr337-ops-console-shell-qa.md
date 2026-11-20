# QA: apotheke #337, operator console shell, typed client and seeded preview (F1a)

- PR: https://github.com/thordin9/apotheke/pull/337 (Front-End Specialist), base `main`. Operator console F1a (PM-approved scope, plan section 8).
- Reviewed head: `877c832c75397416d81db98494b43b75df757fa0`. 0 behind main `e81aa7de`, 2 ahead, `mergeable_state` clean.
- Gate: full (new code). Full diff read, the local unit, bundle and end-to-end suites, 11 mutants, a secret scan and an npm audit.
- CI on `877c832c`, all green: CI 44728828516, Operator console 44728828494 and PR #337 44728820894. 22 checks pass, including CodeQL.
- Reviewed 2026-11-20, 13:53 to 14:05 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main: 28 files, +2904/−0. New `ops/console/**`, `.github/workflows/ops-console.yml`, 4 ignore lines in `.gitignore`, `docs/operator-console.md` and CHANGELOG. Nothing touches `frontend/`, `edge/`, deploy workflows or root package files. There's no deploy step.
- [x] Client: `X-CSRF` is set only in `csrfHeaders()`, and `request()` adds it for POST and PUT only. GET sends no header, and no DELETE endpoint is documented. Typed errors cover 401, 403, 404, 409, 410, 422 and 503; anything else is `unexpected`, and a fetch failure is `network`. `credentials: 'same-origin'`. The base comes only from `<meta name="ops-api-base">`, which is build-time and can't be set from the URL. `GET /config` is read once in `boot()`.
- [x] Rendering: every API value goes through `textContent` or `setAttribute`. There's no `innerHTML`, `insertAdjacentHTML`, `eval` or hash/search sink. Links are intercepted only when they're same-origin.
- [x] Types and fixtures: the types are exactly the columns in control migrations 0001, 0003 and 0004, with no secret, key or token fields. Fixtures use `@example.test` owners. gitleaks 8.30.1 with the repo config found 0 leaks (2 commits, plus a directory scan of `ops/console`). npm audit: 0 vulnerabilities.
- [x] Preview isolation: the mock is a dev-only Vite middleware (`apply: 'serve'`, `configureServer`). Dev and preview bind to `127.0.0.1:4181` with `strictPort`. The built `dist/` (3 files) contains no banned strings.
- [x] Tests on `877c832c`, Node 22: unit 17/17 and bundle 2/2. `tsc --noEmit` and `vite build` are clean. Playwright (chromium) 14/14, with axe on the WCAG 2.2 AA tags: 0 violations on 8 routes and the 401, 503 and 410 states. The keyboard path passes. `check-links`: 401 links, 0 broken.
- [x] Workflow: `contents: read`, `persist-credentials: false`, no secrets. `actions/checkout` v7.0.1 and `actions/setup-node` v7.1.0 are pinned to the same SHAs as the other workflows. Runs `npm ci --ignore-scripts`.
- [x] Mutants (all restored afterwards). Killed:
  - CSRF: `csrfHeaders()` returning `{}`, the header sent only on POST, and the header sent only on PUT (3/3).
  - Bundle: a fixture import in `main.ts` and a `mock-api` import in `main.ts` (2/2).
  - Status mapping: 410 removed, and 410 mapped to `not_found` (2/2).
  - Focus: dropping the move of focus to the h1.

  Survived:
  - Removing `apply: 'serve'`. This is an equivalent mutant: the build never runs `configureServer`, and the bundle test still guards the real risk.
  - Dropping the nav's `aria-current="page"` (N-2322).
  - Dropping the filters' `aria-current="true"` (N-2322).
- [x] Docs: the "Console API (draft, not implemented)" section says it is a draft and that nothing is served yet. Its endpoints, CSRF rule and error list match `client.ts`, and it contains no real ids. The CHANGELOG bullet is under `[Unreleased]` / Added and links the full PR URL. Released sections and `VERSION` (3.53.2) are byte-identical to main.
- [x] Commits: `8be2da56` (66 characters) and `877c832c` (46) are Conventional, and `check-commits` reports ok. The PR title plus " (#337)" is 73 characters (N-2321).

## Findings

| Id | Grade | Finding | Fix |
|---|---|---|---|
| N-2321 | Low | PR title + " (#337)" = 73 chars, over the 72 limit; a squash with it fails `check-commits` on main. | Squash with the subject below, or retitle the PR. |
| N-2322 | Low | No test asserts `aria-current`; nav (`page`) and filter (`true`) mutants survive (axe can't see them). | In e2e, assert `aria-current="page"` on the active nav link after navigation and `"true"` on the chosen filter. |
| N-2323 | Nit | Fleet freshness stamp is raw ISO (`2026-11-20T00:00:00Z`). | `<time datetime="ISO">` with `Intl.DateTimeFormat` local text, ISO in `datetime`/`title`; update e2e text. |
| N-2324 | Nit | Revenue "Amount" cell is empty; a reader sees a blank row (accessible via `aria-describedby`). | OK by design; optionally show "Not set" in the cell. |
| N-2325 | Nit | `write()` takes any method; a future DELETE/PATCH would go without `X-CSRF` (server fails closed). | Type `method` as `'POST' \| 'PUT'`, or add the header on every non-GET/HEAD. |
| N-2326 | Nit | `parseRoute` calls `decodeURIComponent` unguarded; `/installations/%E0` throws `URIError` before `boot()`'s try, leaving `main` blank. | Catch it and return `{ name: 'missing' }`. |
| N-2327 | Nit | The `primary` fixture route is the real public host `api.services.getapotheke.com` (mirrors seed 0002, not secret); the "made-up rows" claim is not exact. | Use `primary.example.test`, or say so in the README. |

0 High, 0 Medium, 2 Low and 5 Nit (next free id: 2328).

## Author questions

- **feat(ops) vs chore.** Keep `feat(ops)`. `scripts/release.php` ignores the scope: any `feat` gives a minor bump (v3.54.0), and `chore` gives no release. The repo has used `feat` for operator-console work that wasn't live yet (#299 schema, #314 jobs). The bullet is under Added, which matches `feat`. Use `chore(ops)` only if the PM wants no release, and then move the bullet to Maintenance.
- **Fleet visuals.** The blank revenue cell is acceptable and matches the docs (N-2324). The raw ISO stamp is a Nit (N-2323). Neither blocks.

## Verdict

**PASS** at `877c832c`, 0 behind main `e81aa7de` (v3.53.2). This doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #337"), followed by the hash check and green CI on that head. Squash as "feat(ops): operator console shell, typed client and preview (#337)" (66 characters).
