# QA: PR #106: main hardening (legacy app, PHP 8.3)

Head `c1ca261a2b0ef78fb9ceed3e33f522f692c09942`, base `bbd53c496f06579268f40f2e6896c114d663351c` (verified as the merge base and as `main`). Commits: e158e71, 05133fd, ac35a9b, c1ca261.

Each claim was probed over HTTP with concurrent requests on SQLite and PG17, and against old `main` to show the probe catches the bug.

**Verdict: APPROVE WITH FOLLOW-UPS.** Every claimed fix holds. There are no High or Medium findings. The Low findings are L-120 (a cap reset) and L-121 to L-123, which are pre-existing or test gaps.

## Checklist
- [x] gitleaks: no leaks on `bbd53c4..c1ca261` and on `--all`. The `.gitleaks.toml` sha256 is `b5bc9f98…3a8f`.
- [x] check-commits (4 ok), lint, and check-links (0 broken of 167).
- [x] CI's CLI steps: migrate twice, then seed the demo twice.
- [x] Full legacy PHPUnit suite: 488 OK on SQLite and 488 OK on PG17, 3 skipped each.
- [x] Playwright on legacy (Chromium projects and pgsql-smoke): 298 passed.
  - The 105 failures are screenshot diffs plus autofill/logo failures that `main` also shows here, and one `qa-v2` load flake (15/15 on re-run).
  - The photo, originals, item, add and sign-in specs: 35/35.
- [ ] Docker jobs (SEC-M3 image checks) were not run: there is no Docker here. No Docker, compose, entrypoint or nginx file changed. The compose file still binds `127.0.0.1:9091`, and a short `APP_PASSWORD` still stops migrate.

## Claims vs results (new vs old main; SQLite and PG)
| ID | Probe | Old main | This PR |
|---|---|---|---|
| L-61 | 40 sign-ins at once | 10 through. SQLite 500s; PG over the limit in 5/10 boundary rounds | 10 through, 30 × 429. Never over the limit in 10 boundary rounds |
| L-61 | 20 registrations at once | 6 accounts (PG) | 5 accounts |
| L-62 | wrong current password on `/account` and `/settings/password` (change and disable), serial and 30 at once | never 429 | 10 × 403, then 429. Success clears it. Shares the sign-in bucket |
| L-66 | 70 different names from one address | 70 or 69 through | 30 through, 40 × 429 |
| L-66 | `X-Forwarded-For`, trust off or forged left hops with trust on | – | capped at 30. Another client is unaffected |
| N-68 | right password followed by NUL | signs in | 401 |
| N-68 | new password with NUL (`/account`, admin set, create, register) | 500 | 422 |
| N-68 | `APP_PASSWORD` with NUL | accepted | refused |
| L-110 | two admins demote/delete each other, 3 cases × 15 rounds | 0 admins in 1–7 rounds per case | 0 admins never. No deadlock, no 500 on PG |
| L-81 | `/save` (photo, new place, new category) with a failing trigger on items/activity/item_photos/item_tags, insert and update | 4 files and 2 places left | 500 with 0 files and 0 places |
| L-81 | photo add with a failing trigger (3 cases) | 4 files left | 500 with 0 files |
| L-81 | replacing a photo, failing | – | the old photo is kept |
| L-81 | a 422 from an SVG | – | keeps the place (as before), 0 files |
| M-90 | 2 or 6 edits at once (10–12 rounds) | lost the original every round | the original is byte-identical, 0 strays. Loser gets 500 |
| M-90 | an edit and a delete at once | lost the original | 404, 0 strays |

72 bytes plus extra characters still signs in, as documented.

**Photo requirement (both databases):**
- The original stays byte-identical. Only the 1600×1067 copy, with EXIF stripped, is served, and `/media/full` returns exactly that copy.
- 6 URL tricks for reaching an original all fail.

**CSRF and authz** on `/login`, `/account`, `/settings/password`, `/save`, photo add/edit, and user role/delete:
- No token or a bad token: 419.
- Viewer or editor: 403.
- Anonymous: 303 to `/login`.

## Mutants: 18 of 24 valid killed
- **Killed:**
  - R1–R4, including the old count-then-insert order and dropping `closeCursor`;
  - C1 and C2 (the current-password limit and its clear);
  - A1–A3 (the address cap, its clear, and its size);
  - N1–N3 (NUL handling);
  - P1–P3 (one transaction, and cleanup armed on `/save` and photo add);
  - M1 and M2 (compare-and-swap, and the row-count check);
  - U1 (removing the admin lock): killed on PG only. On SQLite the race fails safe with 500s.
- **Survived:**
  - C3 and C4 (`/settings/password` disable/change without the limit): no test covers them (L-123).
  - U2 (a stale target row): equivalent, because the count is read after the lock.
  - P4 (keeping the photo on a UserError returned from the transaction): not reachable over HTTP.
  - M3 (copying when the original is gone): the test can't fail (L-123).
  - M4 (the loser's 404 check): only changes the status code.
- **Invalid:** U3. The mutation stopped the delete from running at all.

## Coder's known risks judged
- **A file removed before the outer commit.** `item_save` drops a replaced photo inside the now-nested transaction, so the only window is a failed COMMIT. Accept; see N-126.
- **Address cap behind a shared address.** Real; see L-121.
- **All attempts at the limit refused.** Seen in 1/10 rounds on PG, and only for the caller's own bucket. Accept.
- **A PG deadlock gives 500.** None in 45 PG rounds, and `db_tx` rolls back, so it fails safe. Accept.
- **A generic 500 for the edit loser.** Same as CI4. Accept, with 409 as an optional improvement.
- **Concurrent legacy photo adds not covered.** Confirmed; see L-122.

## Findings
- **L-120. A successful sign-in clears the whole per-address bucket.** One account that can sign in resets L-66's cap, so the cap doesn't hold for a client that has any working account (87 failed tries in one window). Fix: on success, clear only the per-account bucket, or remove only this request's address row.
- **L-121. The address cap is shared by everyone behind one address.** That includes a reverse proxy without `TRUSTED_PROXIES`, NAT and CGNAT. 30 failures from anyone then block every sign-in from that address for up to 15 minutes; sessions already signed in are unaffected. Before, only the named accounts were affected. Fix: document `TRUSTED_PROXIES` next to the limit, and consider letting a recognised device skip the address cap.
- **L-122 (pre-existing, disclosed). Photo adds sent at once can pass `max_photos`.** PG passed it in 8/10 rounds (old: 7/10). SQLite returns some 500 "database locked" answers. No files are left over now. Fix: lock the item row first, as CI4 does (P6).
- **L-123. Test gaps.**
  - Nothing covers the limit on `/settings/password` (C3, C4).
  - There is no concurrent delete-vs-delete or demote-vs-delete test.
  - `testCopyingAPhotoWhoseOriginalIsGoneFails` can't fail: `catch (RuntimeException)` also catches PHPUnit's failure.
- **N-124.** Turning sign-in back on while it is off checks the admin password without the limit. This predates the PR and is outside L-62's paths.
- **N-125.** The per-address caps count each IPv6 address on its own, so many addresses in one /64 each get a fresh cap (40/40 through). Pre-existing.
- **N-126.** A replaced photo's files are removed before the outer COMMIT (disclosed). Suggestion: defer the delete until after the commit.

## Security
This PR is hardening for L-61, L-62, L-66, N-68, L-110, L-81 and M-90, and each was verified against old `main`. The IDs above are the public wording. L-120 and L-121 weaken L-66 only; they do not weaken the per-account limit. SEC-M3 has not regressed, by inspection; the Docker jobs could not be run here.
