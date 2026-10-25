# QA: apotheke #100, CI4 sign-in, register, sign-out and account pages (step 7 A)

- **PR:** [#100](https://github.com/thordin9/apotheke/pull/100) (`ci4/auth-pages` into `feature/ci4`). Security gate.
- **Reviewed head:** `285342c`. Passes: `c984930` (full), `a91866f` (M-60, merge of #101), `285342c` (M-65, N-67). Last delta: `fb42434` (fix), `1be8df8` (tests), `285342c` (docs); no merge.
- **Verdict: PASS at `285342c7c3e33e746b9a440e34e52f79fcadafb1`.** 0 High, 0 Medium; 3 Low (PM backlog); 1 Nit for `main`.

## Checklist

| Check | Result |
|---|---|
| CI | 22/22, as reported by Coder2. The GitHub API isn't reachable from here. |
| Pace and dummy hash | Over HTTP, 27 inputs × 6 attempts all answered 401, median 1006–1013 ms, all within 1006–1031 ms. Accounts: unknown, cost 10, cost 12. Inputs: wrong password, right password plus a NUL byte, NUL only, NUL first or last, over 72 bytes, invalid UTF-8, an array, and a NUL in a multipart form. No 500. A JSON body gets 413 at once, as on legacy, before any lookup. `DUMMY_HASH` is a valid bcrypt cost-12 hash at PHP 8.4's default cost. It is not derived from any secret, and no trivial input matches it. Checking against it never throws. |
| M-60 still closed | Wrong-password timing is the same for unknown, cost-10 and cost-12 names (above). A good password isn't padded and is rehashed to cost 12. |
| N-67 | Closed. Registration and `/account` refuse a password containing a NUL byte with 422 and a message; the hash is unchanged. Legacy answers 500. |
| First admin | `APP_PASSWORD` doesn't go through the password rules. A NUL byte can't come from the environment, only from the config file the operator writes. It would then fail closed (500, no account created), so no action is needed. |
| N-63 | Tracked in HANDOFF, owner Coder2 (CI4 lane). |
| Tests, PHP 8.4 | CI4 module suite: SQLite 342 (1 skipped), PostgreSQL 17 436 OK. Auth parity: SQLite and PostgreSQL 17 OK (171 assertions). Earlier passes: the full suites, legacy (PHP 8.3) and cross-app hashes. |
| Mutations (last delta) | 6 of 8 caught: NUL check dropped, the deadline setting ignored, the NUL rule dropped from the password rules, dummy hash at cost 10, no dummy check, and the catch widened to every error (would hide a failed rehash write). Two are equivalent alone: hashing instead of checking the dummy (the catch makes it a padded 401), and narrowing the catch (nothing throws any more). Together they are caught by the new NUL test. Earlier passes: 33/35 and 11/14, survivors equivalent. |
| Gates | `.gitleaks.toml` `b5bc9f98…` unchanged. gitleaks finds nothing (`a91866f..285342c`). `check-commits` ok (3). deptrac and the DI rule: 0 errors. `check-links`: 0 broken. `lint.sh` OK. The CHANGELOG released tail equals `main` `bbd53c4`. `docs/qa`: 60 identical. |

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| M-60 | Medium | Closed | A failed sign-in's timing depended on the stored hash's cost. Fixed by the fixed deadline (`SignInPace`). |
| M-65 | Medium | Closed | An input class answered before the deadline and showed whether a name exists. Fixed by the dummy-hash check and NUL handling. |
| N-64 | Nit, tests | Closed | Parity case for `trust_proxy` off. |
| N-67 | Nit | Closed | NUL in a new password: now 422. |
| N-63 | Nit, parity | Tracked (Coder2) | 404 for other methods on the sign-in pages, where legacy shows the page. |
| L-61 | Low, legacy too | PM backlog | Rate-limit count and insert are not atomic on PostgreSQL. |
| L-62 | Low, legacy too | PM backlog | The current-password check on `/account` has no limit. |
| L-66 | Low, hardening | PM backlog | Failed sign-ins hold a worker for the deadline. Add a per-address cap across names or a request limit on `/login`. |
| N-68 | Nit, `main` | Backlog (hardening) | On legacy (PHP 8.3 and 8.4), the right password with a NUL byte and anything after it signs in, because bcrypt stops at NUL. With an account at a non-default cost the answer is 500. It needs the right password, so it gives no extra access. bcrypt also ignores bytes past 72, in both apps (the rules allow 200). Refuse NUL bytes on `main` as CI4 does, and state the 72-byte limit in the password rules. |

## Security

- **Name enumeration:** every failed sign-in answers 401 at the same deadline, whatever the name, the stored hash or the password bytes (M-60 and M-65 closed). Registration shows a taken name, as legacy does.
- **Session:** IDs are renewed on sign-in, registration and sign-out. Old sessions are removed on the server.
- **CSRF and redirects:** CSRF on every write, sign-in included. Redirects go only to fixed targets. Cookie flags match legacy. No access to another account's data.
- **Backlog (PM):** L-61, L-62 and L-66, for `main` and CI4 together. N-68 for `main`.
- **Worker phase:** nothing blocks the FrankenPHP worker / Cloudflare Container phase. No static state; the pace and accounts objects are built per request.

**Verdict: PASS at `285342c7c3e33e746b9a440e34e52f79fcadafb1`** (0H / 0M / 3L backlog / 1N `main`). N-63 is tracked.
