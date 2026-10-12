# Audit hardening and agent docs — design

Date: 2026-10-12  
Scope: approach C (bugs + helpers + AGENTS.md + light router/docs tidy)

## Problem

Live browse of `http://127.0.0.1:8080/` (Docker Compose) plus a code audit found:

1. **HEAD → 404** — almost every read route matches `$method === 'GET'` only, so `HEAD /`, `HEAD /new`, `HEAD /export.csv`, etc. return 404. Only `/healthz` works (no method check). Link checkers and some proxies use HEAD. No tests cover this.
2. **Pluralization** — header and overview summary always say `N items · M pcs` (e.g. `1 items · 1 pcs`). List `count_text()` already singularizes correctly. The same `N item(s)` ternary is duplicated in several views and in label subtitles in the router.
3. **No `AGENTS.md`** — contributing docs are thorough for humans; agents lack a single entry point for stack constraints, layout, and test commands.
4. **Repeated boilerplate** — many GET handlers repeat `header('Cache-Control: no-store');`.

Security posture (CSP, CSRF, path blocking, prepared statements, `safe_back`) is sound and out of scope for change.

## Goals

- HEAD on read routes returns the same status as GET (body may be empty under nginx; PHP may still generate it under `php -S`).
- Singular/plural copy is consistent and centralized.
- Agents have a root `AGENTS.md` with durable project instructions.
- Tests and docker checks catch HEAD regressions.
- Docs index/architecture/testing/contributing mention the above without a README rewrite.

Non-goals: splitting `views.php` / `items.php`, auth redesign, password hashing, JS rewrite, visual baseline churn unless a asserted string actually changes (seeded list stays `8 items · 19 pcs`).

## Design

### Routing

In `public/index.php`, immediately after `$method = …`:

```php
if ($method === 'HEAD') {
    $method = 'GET';
}
```

All existing `$method === 'GET'` checks then accept HEAD. POST routes unchanged. Add `no_store(): void` next to other response helpers in `src/bootstrap.php` and replace repeated `Cache-Control: no-store` calls in the router.

### Copy helpers

In `src/views.php`:

- `item_noun(int $n): string` → `"0 items"`, `"1 item"`, `"2 items"`
- `stats_brief(int $items, int $qty): string` → `"1 item · 1 pc"` / `"8 items · 19 pcs"` (`pc` / `pcs`)

Wire into header stats, overview summary mini-text, `count_text`, bulk toast, lent summary, value-tile priced count, and label subtitles in `public/index.php` (via `item_noun`).

### AGENTS.md

Root file covering: what the app is; hard constraints (plain PHP 8.2+, PDO SQLite/pgsql, GD, no runtime Composer, no JS build, no CDNs, CSP-safe); directory map; local run (Docker Compose and `php -S`); config/`DATA_DIR`; test pyramid; Conventional Commits; pointer to `docs/contributing.md` for feature workflow.

### Tests

- Integration suite: `HEAD /` status equals `GET /` (200); `HEAD` of an unknown path stays 404; optionally `HEAD /healthz` and one other GET page.
- `scripts/docker-checks.sh`: assert `HEAD /` is 200 (same as front controller GET).
- Visual list assertion for `8 items · 19 pcs` remains valid.

### Docs

- `docs/index.md`: link `AGENTS.md`.
- `docs/architecture.md` or testing: one sentence that read routes treat HEAD like GET.
- `docs/contributing.md` / README: one-line pointer to `AGENTS.md`.

## Risks

- Under PHP’s built-in server, HEAD may still return a body; status code is what we assert. Nginx discards bodies for HEAD in Docker.
- Changing `1 items` → `1 item` does not affect the 8-item visual seed.

## Verification

`tests/run.sh`, targeted PHPUnit if a unit test is added for helpers, `scripts/docker-checks.sh` against a rebuilt container when Docker path is touched.
