# QA: main PR #122 (sign-in timing and after-commit hardening)

- PR: thordin9/apotheke #122 into main. Head `ad133f6`, base `80cf3b6` (v2.6.19, also the merge-base).
- CI run 40334699837: 10/10 green at the head.
- QA date: 2026-10-26. Finding IDs start at 310.
- Verdict: **PASS**. 0 H / 0 M / 1 L / 5 N. Nothing blocks the merge.

## Checklist

- [x] Lint is clean.
- [x] gitleaks on `80cf3b6..ad133f6` finds no leaks. `.gitleaks.toml` is unchanged (sha b5bc9f98…993a8f).
- [x] Legacy suite on PHP 8.3:
  - SQLite: 510 OK (13 skipped).
  - PG17: 510 OK (3 skipped).
- [x] Auth tests on PHP 8.4 (HardeningTest, HardeningHttpTest, UsersTest): 46 OK on SQLite and 46 OK on PG.
- [x] CHANGELOG has one hardening line that cites the PR.
- [x] L-300: timing measured on PHP 8.3 and 8.4, SQLite and PG, at the head and at the base.
- [x] N-301 and N-304: the timing tests are stable and kill the dummy and re-enable mutants.
- [x] N-302: a failed import drops queued work, and its mutants are killed.
- [~] N-303: the migrate flag works, but two mutants survive (N-312, N-313).
- [x] N-304: re-enable status codes match the base.

## Timing (ms, fastest of 12 sign-in or 8 re-enable requests)

Sign-in failures:

| Run | NUL / unknown / known name | Account at cost 10 | Account at cost 12 |
|---|---|---|---|
| 8.3 head, SQLite | 451 | 451 | 606 |
| 8.3 head, PG | 464 | 464 | 618 |
| 8.4 head, SQLite | 600–604 | 450 | 600–604 |
| 8.4 head, PG | 618–626 | 465 | 618–626 |
| 8.4 base | unknown 450, known 605–620 | | |

- All of these return 401.
- On the base, an unknown name answers faster than a known one on 8.4, which confirms L-300. The head closes that gap.

Re-enable failures (NUL name / non-admin / admin with a wrong password):

| Run | Time |
|---|---|
| 8.3 head, SQLite | 51–57 |
| 8.3 head, PG | about 63 |
| 8.4 head, SQLite | 203–205 |
| 8.4 head, PG | about 220 |
| Base | NUL and non-admin 1.6–15, admin 50–228 |

Status codes are the same as on the base:

| Case | Status | Sign-in afterwards |
|---|---|---|
| Any wrong password | 403 | |
| Non-admin with the right password | 403 | stays off |
| Admin with the right password | 303 | on |
| Unknown name | 303 | creates the account (as before) |

New names containing NUL are refused 6/6 each for register, add and rename. The server logs no warnings.

Cost of the dummy hash compared with a real check, per call:

| | PHP 8.3 | PHP 8.4 |
|---|---|---|
| `password_hash` (default cost) | about 48 | about 203 |
| `password_verify` (default cost) | about 48 | about 200 |
| Median difference over 60 interleaved runs | −0.25 | −0.54 |

- There is no difference in code path. `password_hash` can only throw if the system random source fails.
- Password length has no effect.

## Mutants

On 8.3 SQLite, `inTransaction()` is false after `BEGIN IMMEDIATE`. On PG and on 8.4 it is true. Some mutants are therefore equivalent on some legs.

| Mutant | 8.3 SQLite | 8.4 SQLite | 8.3 PG |
|---|---|---|---|
| T1: dummy removed | K | K | – |
| T2: dummy at cost 4 | K | K | – |
| T3: fixed cost-10 dummy | S (equivalent) | K | – |
| R1: re-enable short-circuit | K | K | – |
| R2: no check for NUL | K | K | – |
| I1: import error path drains | K | – | K |
| I2: import row error drains | K | – | K |
| M1: drain before COMMIT | **S** | K | K |
| M2: flag not reset | K | K | K |
| M3: flag never set | K | S (equivalent) | S (equivalent) |
| M4: `after_commit` ignores the flag | K | S (equivalent) | S (equivalent) |
| M5: flag reset only on success | **S** | **S** | **S** |
| M6: migrate error path drains | K | K | K |

- PR tests: 12 of 13 mutants killed. Counting legs: 23 of 27 non-equivalent legs killed, plus 5 equivalent legs.
- A QA probe kills M5 on all three legs, which brings the total to 13 of 13 (see N-313).

Flake runs of the two timing tests:

| Run | Result |
|---|---|
| 8.3 SQLite | 20/20 |
| 8.3 PG | 20/20 |
| 8.4 SQLite | 10/10 |
| 8.4 PG | 24/25 (see N-315) |
| 8.3 SQLite with 6 busy loops running | 10/10 |

## Findings

- **L-310 (L):** On a failed sign-in, an account whose stored hash cost differs from the runtime default still takes a different time than an unknown name, until that account next signs in successfully. Examples are cost-10 hashes on 8.4 and cost-12 hashes on 8.3, such as hashes written by another runtime.
  - A wrong password never triggers a rehash.
  - This is still strictly better than the base.
  - A fixed cost-matched dummy hash, or one cached per process, costs about the same and does not fix this (L-300 d).
  - Hardening: pad every failed check to a fixed deadline measured from the start of the request, instead of a fixed sleep, on sign-in, re-enable and current-password checks. Optionally, rehash stale costs at upgrade time.
- **N-311 (N, existing before this PR):** When a migration calls `after_commit()` during `bin/migrate.php`, the CLI opens a second connection and starts migrating again. On SQLite it fails after about 5 s. On PG it waits on the migrations lock until it is killed.
  - No shipped migration does this. The new flag suggests the pattern is supported, though.
  - Hardening: inject the migrate connection into `db()` from the CLI, or don't use `db()` while a migration is active.
- **N-312 (N, test gap):** The M1 mutant (drain before COMMIT) survives on 8.3 SQLite, which is the leg this PR's claim names. The test checks where the drain happens with `inTransaction()`, which is always false on that leg.
  - Hardening: check commit visibility from a second connection, or record the order of COMMIT and the drain.
- **N-313 (N, test gap):** M5 survives because the test only checks the flag after a later successful migration. A QA probe kills it on all legs; it passes at the head:

  ```
  failed migrate()
  assertFalse(migrate_active())
  after_commit(f)
  assert f ran
  ```

  Hardening: add these assertions straight after the failed migration.
- **N-314 (N, note):** The migrate flag and queue are static to the process. They don't leak, because FPM and CLI workers don't share state and the flag resets in `finally`. A Cloudflare port has to keep that state per request or per isolate.
- **N-315 (N, observation):** In one 8.4 PG run, the timing test got status 0: requests timed out for about 5 minutes while other QA jobs were running. It could not be reproduced: 15/15 passed in isolation, at 14–16 s per run. No assertion on timing has failed.

## Security

- The PR removes the timing difference between unknown and known names for current-cost hashes on 8.3 and 8.4, and on both drivers.
- The NUL-name and re-enable paths now run one password check each, and their status codes are unchanged.
- One timing difference remains for accounts with mixed hash costs (L-310).
- Secret scanning is clean, and no secrets were added.

## Verdict

**PASS** (0 H / 0 M / 1 L / 5 N). L-310 and N-311 to N-313 are suggested follow-up hardening.
