# QA: PR #292 FrankenPHP worker mode (QA-1854)

PR: https://github.com/thordin9/apotheke/pull/292 · **Approved head: `f73b7f588576919adeea347687e01e926971d12c`** · Main: `2e62bf69` (v3.36.10) · QA: Apotheke QA 2, 2026-11-12 (EET). Earlier versions of this doc are void, including the FAIL at `7ea981ff` (sha256 `f003c931…`).

**Verdict: PASS at `f73b7f58`.** No High or Medium findings are open. M-1978 is closed: the hosted container ships in classic mode.

## Gate at `7ea981ff` (full gate; worker code unchanged since)
- **PHP 8.4 suites:**
  - sqlite: 620 tests, OK.
  - pgsql (local): 620 tests, OK.
- **Worker reset logic** matches CodeIgniter 4.7.5's worker template. No new per-request static state.
- **Local FrankenPHP v1.13.4 run** (release binary):
  - Isolation with 2 users, an admin, anonymous requests and CSRF checks: 510 checks with 0 failures in each mode.
  - Upload temp files are cleaned up.
  - Memory levels off at about 142 MiB.
  - A 400 or 404 answer doesn't restart the worker.
- **Worker-logic mutants:** the repo suite killed 2 of 7 and the local probe 3 of 7.

## Delta checklist (`7ea981ff` → `f73b7f58`)
- [x] **Head and CI:** 0 behind and 7 ahead of `2e62bf69`, mergeable CLEAN. CI run 43257119828 passed 18/18 jobs; the PR shows 23 checks passed and 2 deploys skipped. FrankenPHP is still pinned at 1.13.4.
- [x] **`ba30ebee`** `fix(edge): ship FrankenPHP worker mode off by default`:
  - Subject is 53 chars. Merge commits use git's default subject, which is exempt.
  - `edge/wrangler.jsonc` sets `"APOTHEKE_PHP_WORKER": "0"`, and the regenerated types say `"0"`.
  - DockerfileTest and `deploy-scripts.test.mjs` both assert `"0"`.
  - ADR 002, `deployment-and-operations.md` (2 places), `configuration.md` and `self-host-cloudflare.md` all say it ships off, and that `"1"` is a later step.
  - A grep of the head finds no doc, test, config or type that still claims it's on by default in prod.
- [x] **Merge read:**
  - The +/- lines of `62c9ca81...ba30ebee` equal those of `main...f73b7f58` for all 20 files.
  - Every non-PR file is byte-identical to main.
  - Since `7ea981ff`, the PHP and container code (`public/`, `app/`, `modules/`, `docker/`, `Dockerfile.frankenphp`, `edge/src`) is unchanged. Main brought in only frontend, docs, e2e tests and `VERSION`.
- [x] **CHANGELOG:** `[Unreleased]` has an Added bullet ("can run") and a Changed bullet ("ships off"), each linking the full PR URL. Everything from `[3.36.10]` down, and the header, is byte-identical to main.
- [x] **Tests (PHP 8.4, from the tarball):** DockerfileTest 15 OK (1 skip), `deploy-scripts.test.mjs` 33/33.
- [x] **Default-flip mutants:** all 4 killed.

  | Mutant | DockerfileTest | deploy-scripts |
  |---|---|---|
  | `wrangler.jsonc` `"0"`→`"1"` | fails | fails |
  | entrypoint `${APOTHEKE_PHP_WORKER:-0}`→`:-1` | fails | passes |
  | `edge/src/index.ts` `?? "0"`→`?? "1"` | fails | passes |
  | Caddyfile import default `classic`→`worker` | fails | passes |
- [x] **Rerun evidence** (`RERUN.md`, `rerun-table.md`, `rerun-bench.jsonl`):
  - Two storage-mode previews ran the merged head `7ea981ff` from images with the same digest. Only `APOTHEKE_PHP_WORKER` differed.
  - Batches alternated c1 w1 … c4 w4: 30 + 30 signed-in requests per batch, 480 requests, all 200.
  - **Signed-in GET /:** worker −5.8% p50 and −1.5% p95, and worker was faster in 4 of 4 rounds. **/api/v1/me:** −11.7% p50.
  - The +59% from the earlier run (non-interleaved, an hour apart) does not reproduce.

## Findings
- **M-1978: closed.** The hosted `apotheke-api` ships `"0"`, and the tests pin it.
- **N-1983: closed.**
  - The interleaved A/B ran on the merged head, and the result is consistent across rounds.
  - Interleaving was batch-level (about 4.5-minute batches), not per request. With 4 of 4 rounds agreeing, that's adequate.
  - This satisfies the go-live measurement for the code at this head. The PHP and container code is identical since `7ea981ff`.
  - The PR that turns worker mode on (`"0"`→`"1"`) still needs its own gate. If any worker or PHP code changes before then, the A/B should be repeated.
- **L-1979 (open, follow-up):** no request cap for worker recycling.
- **L-1980 (open, follow-up):** no CI job runs worker mode.
- **N-1981 (open, follow-up):** the worker case of the Caddyfile validate test fails outside the image.
- **N-1982: closed.** ADR 002 now notes that the BootMigration memo is per worker thread.
- **L-1984 (new; fix before the squash):** the PR title, which becomes the squash subject, still reads `feat: run the hosted container in FrankenPHP worker mode`. Merged as is, history would say prod runs worker mode. Suggested title: `feat: add opt-in FrankenPHP worker mode for the hosted container` (63 chars).
- **N-1985 (new):** three code comments still describe the hosted container as running worker mode: `docker/frankenphp/php-worker.caddy:1`, `docker/frankenphp/entrypoint.sh:7` and `public/frankenphp-worker.php:6`. Reword them in the L-1979 or L-1980 follow-up, for example to "opt-in; the hosted container ships it off".
- **N-1986 (new):** the explanation of the code-0 container exits (sleep, or after a deploy) isn't recorded in the evidence files. The earlier preview exit came 20 s after the last request, so it wasn't the sleep timer. Record the explanation, and check it again on the flip PR's preview.

## Default after merge (at this head)
- **Hosted `apotheke-api`:** classic mode (`APOTHEKE_PHP_WORKER` `"0"`). Merging and the next release change nothing in prod.
- **Self-hosted FrankenPHP image:** classic unless the variable is set to `1`, `true` or `yes`.
- **nginx image:** has no worker mode.
