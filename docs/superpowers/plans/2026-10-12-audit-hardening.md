# Audit hardening and agent docs — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix HEAD 404s and pluralization, DRY small helpers, add AGENTS.md and coverage, without large refactors.

**Architecture:** Treat HEAD as GET once in the router; add `item_noun` / `stats_brief` / `no_store` helpers; document for agents; assert HEAD in integration and docker-checks.

**Tech Stack:** Plain PHP 8.2+, existing `tests/integration.php` + `tests/lib.php` Client, bash docker-checks, Markdown docs.

## Global Constraints

- No new runtime dependencies; PHPUnit/Playwright stay as today.
- CSP-safe: no inline scripts/styles/handlers.
- Both SQLite and PostgreSQL must keep working (no SQL changes expected).
- Do not change visual baselines unless an asserted string changes (8-item seed stays `8 items · 19 pcs`).
- Conventional Commits; subject ≤ 72 characters.

## File map

| File | Role |
|---|---|
| `public/index.php` | HEAD→GET; use `no_store()`; label subtitles via `item_noun` |
| `src/bootstrap.php` | `no_store()` |
| `src/views.php` | `item_noun`, `stats_brief`; wire call sites |
| `tests/integration.php` | HEAD assertions |
| `scripts/docker-checks.sh` | HEAD / check |
| `AGENTS.md` | Agent instructions |
| `docs/index.md`, `docs/architecture.md`, `docs/contributing.md`, `README.md` | Cross-links |
| `docs/superpowers/specs/2026-10-12-audit-hardening-design.md` | Spec (already written) |

---

### Task 1: Failing HEAD + pluralization tests

**Files:**
- Modify: `tests/integration.php` (early after first GET /)
- Modify: `scripts/docker-checks.sh`
- Create: `tests/Unit/CopyHelpersTest.php` (optional lightweight unit for helpers — use once helpers exist in Task 2; write failing integration first)

**Interfaces:**
- Produces: assertions that `HEAD /` status === `GET /` status; `HEAD /no/such/page` === 404; docker-checks expects HEAD / == 200

- [ ] **Step 1: Add HEAD checks to integration suite**

Near the start of `tests/integration.php` (after the first successful `GET /` and security-header checks), add:

```php
T::section('HEAD');
$getHome = $c->get('/');
$headHome = $c->req('HEAD', '/');
T::eq($getHome['status'], $headHome['status'], 'HEAD / status matches GET /');
T::eq(200, $headHome['status'], 'HEAD / is 200');
T::eq(404, $c->req('HEAD', '/no/such/page')['status'], 'HEAD unknown path is 404');
T::eq(200, $c->req('HEAD', '/healthz')['status'], 'HEAD /healthz is 200');
```

- [ ] **Step 2: Add docker-checks HEAD assertion**

After `check "front controller" …`, add:

```bash
check "HEAD / same as GET"           200 "$(code -I "$base/")"
```

(`curl -I` issues HEAD.)

- [ ] **Step 3: Run integration to confirm HEAD fails today**

Run: `php tests/integration.php` (or the HEAD section via full suite)

Expected: FAIL on `HEAD / status matches GET /` or `HEAD / is 200` (actual 404).

- [ ] **Step 4: Commit**

```bash
git add tests/integration.php scripts/docker-checks.sh
git commit -m "$(cat <<'EOF'
test: assert HEAD matches GET on read routes

EOF
)"
```

---

### Task 2: Router HEAD + no_store + copy helpers

**Files:**
- Modify: `public/index.php`
- Modify: `src/bootstrap.php`
- Modify: `src/views.php`
- Create: `tests/Unit/CopyHelpersTest.php`

**Interfaces:**
- Consumes: none new
- Produces:
  - `no_store(): void` in `src/bootstrap.php`
  - `item_noun(int $n): string`
  - `stats_brief(int $items, int $qty): string`

- [ ] **Step 1: Write unit test for helpers**

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CopyHelpersTest extends TestCase
{
    public function testItemNoun(): void
    {
        $this->assertSame('0 items', item_noun(0));
        $this->assertSame('1 item', item_noun(1));
        $this->assertSame('8 items', item_noun(8));
    }

    public function testStatsBrief(): void
    {
        $this->assertSame('1 item · 1 pc', stats_brief(1, 1));
        $this->assertSame('8 items · 19 pcs', stats_brief(8, 19));
        $this->assertSame('0 items · 0 pcs', stats_brief(0, 0));
    }
}
```

Require views via existing PHPUnit bootstrap (same as other Unit tests that call app functions).

- [ ] **Step 2: Implement helpers in views.php**

```php
function item_noun(int $n): string
{
    return $n . ' item' . ($n === 1 ? '' : 's');
}

function stats_brief(int $items, int $qty): string
{
    return item_noun($items) . ' · ' . $qty . ' pc' . ($qty === 1 ? '' : 's');
}
```

Update `count_text` to use `item_noun`; update bulk_toast, lent summary, value priced line, header `.stats`, and stats_section mini-text to use these helpers.

- [ ] **Step 3: HEAD alias + no_store in bootstrap/router**

In `src/bootstrap.php`:

```php
function no_store(): void
{
    header('Cache-Control: no-store');
}
```

In `public/index.php` after `$method = …`:

```php
if ($method === 'HEAD') {
    $method = 'GET';
}
```

Replace `header('Cache-Control: no-store');` in the router with `no_store();`. Replace label subtitle concatenations with `item_noun((int)$l['n'])`.

- [ ] **Step 4: Run tests**

```bash
vendor/bin/phpunit --filter CopyHelpersTest
php tests/integration.php   # or tests/run.sh
```

Expected: PASS including HEAD section.

- [ ] **Step 5: Commit**

```bash
git commit -m "$(cat <<'EOF'
fix: treat HEAD as GET and pluralize stats copy

EOF
)"
```

---

### Task 3: AGENTS.md and doc links

**Files:**
- Create: `AGENTS.md`
- Modify: `docs/index.md`, `docs/architecture.md`, `docs/contributing.md`, `README.md` (short pointers)

- [ ] **Step 1: Write AGENTS.md** covering stack constraints, layout, Docker/`php -S`, config, test commands, commits, link to `docs/contributing.md`.

- [ ] **Step 2: Cross-link** from docs index, architecture (HEAD note), contributing, README contents/project layout.

- [ ] **Step 3: Commit**

```bash
git commit -m "$(cat <<'EOF'
docs: add AGENTS.md and link from project docs

EOF
)"
```

---

### Task 4: Verify against Docker

**Files:** none (rebuild + scripts)

- [ ] **Step 1:** `docker compose up -d --build`
- [ ] **Step 2:** `scripts/docker-checks.sh http://127.0.0.1:8080` — expect HEAD check ok
- [ ] **Step 3:** Spot-check `curl -sI http://127.0.0.1:8080/` → 200; singular stats on a 1-item DB if present
