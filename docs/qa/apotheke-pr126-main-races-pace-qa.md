# QA: main PR #126 – concurrent-write locks and failed-check pacing

PR thordin9/apotheke #126 → main · head `50a1b26` · base `935f144` (v2.6.20, = merge-base) · CI 40376407143 10/10 green at head · QA 2026-10-26 · IDs from 360.
**Verdict: PASS** (0 H / 0 M / 0 L / 6 N). Nothing blocks merge.

## Checklist
- [x] Lint (8.3 + 8.4) clean; gitleaks `935f144..50a1b26` no leaks; `.gitleaks.toml` unchanged (b5bc9f98…993a8f).
- [x] Full suite: 8.3 SQLite 516 OK (13 skip), 8.3 PG17 516 OK (3 skip), 8.4 SQLite 516 OK (13 skip), 8.4 PG17 516 OK (3 skip).
- [x] No migration or schema change; no CI4 / shared-area files touched; CHANGELOG has one hardening line with the PR link.
- [x] L-230 / L-240 / L-340 / N-341: HTTP race matrix, base vs head (below).
- [x] L-310 / L-330: every failed password check answers at the deadline on 8.3/8.4 × SQLite/PG (below); success not padded.
- [x] The real-timing test (`testFailedPasswordChecksAnswerAtTheDeadline`) passed 20 of 20 runs: 5 on each of 8.3 SQLite, 8.3 PG, 8.4 SQLite and 8.4 PG, about 20 s per run.
- [x] N-311: `after_commit()` + `db()` inside a migration via `bin/migrate.php` now works on both drivers.
- [x] N-312 / N-313: the #122 survivors (drain before COMMIT, flag reset only on success) are now killed on 8.3 and 8.4 SQLite.
- [x] N-314 / N-315 notes only; N-320 reasoning accepted (see N-365).

## Race matrix (real HTTP, `php -S`, PHP_CLI_SERVER_WORKERS=4, 30 rounds each; races / 5xx)

| scenario | base 8.3 SQLite | base 8.3 PG | base 8.4 PG | head (8.3+8.4, SQLite+PG) |
|---|---|---|---|---|
| lend + lend | 0 / 10 | 11 / 0 | 13 / 0 | 0 / 0 |
| return + return | 0 / 11 | 6 / 0 | 7 / 0 | 0 / 0 |
| lend + return | 0 / 7 | 3 / 0 | 11 / 0 | 0 / 0 |
| finish + finish | 1 / 5 | 5 / 0 | 12 / 0 | 0 / 0 (30 × 303 + 409) |
| finish + cancel | 1 / 1 | 6 / 0 | 8 / 0 | 0 / 0 (30 × 303 + 409) |
| tick vs finish | 5 / 6 | 10 / 0 | 12 / 0 | 0 / 0 (tick 303 or 409) |

Base 8.4 SQLite: 58 "database is locked" 500s and 0–5 races per scenario. Head: 0 races, 0 5xx, 0 lock errors on every leg.

## Pacing (ms per request, min–max of 4, SIGN_IN_FAIL_MS=1000)

| case | 8.3 SQLite | 8.3 PG | 8.4 SQLite | 8.4 PG |
|---|---|---|---|---|
| sign-in: unknown, wrong pw, NUL (end/start), stored cost 4/10/12/13 | 1000.8–1004.7 | 1001.3–1004.1 | 1000.8–1007.2 | 1001.3–1003.4 |
| current password (account, change, disable) | 1000.9–1001.8 | 1001.3–1003.9 | 1001.2–1002.3 | 1001.3–1005.0 |
| turn-on refusals (admin wrong, NUL ×2, non-admin wrong, **non-admin right**, cost 10/13 wrong) | 1001.2–1002.6 | 1001.9–1004.1 | 1001.4–1006.8 | 1002.1–1004.2 |
| success: sign-in / current pw / turn-on | 51 / 101 / 57 | 68 / 127 / 80 | 221 / 417 / 229 | 229 / 439 / 260 |

- Statuses are unchanged: 401 for sign-in, 403 for the rest, 303 on success. Sign-in stays off after every refusal and turns on with the admin's right password.
- Wrong passwords never rehash.
- Clamp checked over HTTP: 0, 50, `abc`, −3 → 401 ms; 1500 → 1501 ms; 99999 and 10^19 → 10 001 ms. Unit checks (8.3 + 8.4): `1e3` → 1000, `1.9e4` → 10000, NaN/INF/`0x10` → 400, empty → default 1000.
- `REQUEST_TIME_FLOAT` is set by the SAPI. Sending a `Request-Time-Float` header, query value or POST field changes nothing, and php-fpm 8.3 overwrites a FastCGI `REQUEST_TIME_FLOAT` parameter. Clients cannot influence the deadline.

## Mutants (PR tests; 8.3 SQLite / 8.3 PG; + QA race probe for L/S)
15 mutants:
- lend/return without the hold
- close without the status guard, without the row count, moved last
- tick without the check, without the row count
- flat 400 ms, turn-on pace only when the password is wrong, no current-password pace, full sleep after the check, success waits, no clamp
- non-admin password check skipped
- `migrate.php` without `db($pdo)`

All 15 are killed on SQLite. On PG 14 of 15 are killed; **close moved last survives on PG** (N-363), and the QA race probe kills it on both drivers. Score: 29 of 30 legs.

## Findings
- **N-360 (N) availability:** each failed check now holds a worker for about 1 s (was about 0.45–0.6 s), against a php-fpm pool of 10. The per-address and per-account caps bound one client; many addresses can still fill the pool. Hardening: document sizing (pool vs `SIGN_IN_FAIL_MS`), or a global cap on concurrent paced answers.
- **N-361 (N):** a stored hash whose check outlasts the deadline (bcrypt cost ≥ 15, about 1.8 s) answers late. Only imported or hand-made hashes do this. Hardening: on rehash, cap the cost at the default, or keep `SIGN_IN_FAIL_MS` above the slowest stored cost.
- **N-362 (N):** a malformed `SIGN_IN_FAIL_MS` (e.g. `abc`) silently becomes the 400 ms minimum, not the 1000 ms default. Hardening: fall back to the default and log a warning.
- **N-363 (N, test gap):** the "close moved last" mutant passes the PR suite on PG. Hardening: on PG, race a tick against a finish that holds the close and assert the summary matches the lines.
- **N-364 (N, note):** a right password on an account waiting for approval answers at once with its own message (as before, by design). Pacing only applies to failed checks.
- **N-365 (N, N-320):** keeping `rel_drop_pr_generated` is sound. Lines are deduplicated by PR link, so one hand-written bullet stands for the PR. Residual: a PR with several distinct fixes relies on its author to name all of them in the bullet.

## Security
- Concurrent lend/return/finish/cancel/tick are serialised by a first-write lock or a compare-and-set, with a row-count check; closed stocktakes answer 409.
- Failed password checks are time-uniform across name, role, NUL, stored cost and driver. This closes L-310 and L-330.
- The deadline is server-anchored and clamped, so it cannot become an unbounded sleep.
- Migrate shares one connection. No new statics; the pace reads `$_SERVER` only.
- No secrets in the diff.

## Verdict
**PASS** (0 H / 0 M / 0 L / 6 N). N-360 to N-363 are optional follow-ups.
