# QA: PR #121 (main, auth hardening: N-251, N-250, N-190, N-191)

Head `ef9096f844dfde58de60787af29147913239d824`, base main `ff23b38` (v2.6.18, tip and merge-base). Legacy app, PHP 8.3, SQLite and PostgreSQL 17. PHP 8.4 was used only for the cost check in L-300.

## Checklist

| Area | Result |
|---|---|
| Lint (`scripts/lint.sh`) | PASS |
| gitleaks `ff23b38..head` (3 commits); `.gitleaks.toml` sha b5bc9f98…993a8f unchanged | PASS, no leaks |
| PHPUnit SQLite / PostgreSQL 17 | 507/507 (13 / 3 skipped, environment) |
| `HardeningHttpTest`, 3x per driver | PASS |
| New timing test: 20x SQLite, 10x PostgreSQL, 10x under CPU load | 40/40 PASS, no flake |
| CI 40320273743 | 10/10 at the head |
| Null-character name matrix (same as the #120 matrix), both drivers | matches CI4 #120 |
| Address cap probes | holds |
| Mutation, 20 mutants | PR suite 18/20; with QA probes 19/20 |
| CHANGELOG | one hardening line, PR id only |

## Verification

- **N-251.** `user_name_valid()` refuses a null character before `trim()`, with 422 and the invalid-name message, on register, add user and rename. The CLI takes names only from arguments, which cannot hold a null character, and it uses the same functions.
  - The HTTP matrix sent 10 names with a null character: leading, middle, trailing, only, doubled, with spaces or a tab, and around an existing name. Every one gives 422 and the invalid-name message, and nothing is created or renamed.
  - At the base, 7 of the 10 created or renamed to the trimmed name. The head now matches CI4 #120 exactly.
  - Valid names are unchanged from the base: names trimmed of spaces, a tab or a newline, `Zoë`, `名前`, `ab.c_d@e-f`, and a 40-character name. The results are the same on SQLite and PostgreSQL.
- **N-250.** `/login` passes a name with a null character to `login()` as submitted. The account lookup refuses it before the driver, and the dummy hash runs.
  - Timing over 12 rounds: a null character at the start or the end, an unknown name, and a known name with a wrong password all answer in the same time on SQLite and PostgreSQL (fastest about 451 ms, which includes the fixed 400 ms delay).
  - At the base, a null-character name answered about 50 ms faster.
- **The timing test.** It compares the fastest of 6 tries per name against a 20 ms margin, and the gap it guards is about 50 ms. No failures in 40 runs, including runs under load.
- **N-190.** Wrong current passwords on `/account` and wrong admin passwords on re-enable both count toward the per-address cap of 30. Once the cap is reached, these are refused with 429:
  - the right password on `/login`
  - the same name upper-case, padded with spaces, or with a null character added
  - a forged `X-Forwarded-For` entry put in front of the real address
  - the right current password on `/account`

  Other checks:
  - **Other addresses:** not locked; one signs in normally (303).
  - **Without a trusted proxy:** a changing `X-Forwarded-For` is ignored, and the 31st try gets 429.
  - **The password:** unchanged.
- **N-191.** Import, restore and migrate run queued cleanups only after their own COMMIT, and drop them on rollback. The new test and the QA mutants confirm it, apart from N-302 and N-303.
- **Re-enable with a null-character name.** It answers 403 without a hash, in the same time as an existing non-admin name, whatever the name is. It reveals nothing about accounts (N-304).

## Mutation

| Group | PR suite | QA probes |
|---|---|---|
| N-251: no check, check after trim, wrong message, register passes the trimmed name (4) | 4/4 | 4/4 |
| N-250: old skip-hash path, login with the trimmed name, dummy hash removed (3) | 2/3 | 3/3 |
| N-190: no address cap, or the try taken back before the check, on current-password or re-enable (4) | 4/4 | 4/4 |
| N-191: preview or error drains, drain before COMMIT, no drain, on import, restore and migrate (9) | 8/9 | 8/9 |

- **Dummy hash removed.** Survives the PR suite, which compares the null-character name only with an unknown name (N-301).
- **Import error path drains.** Survives (N-302).
- **Migrate drain before COMMIT.** Killed only on PostgreSQL (N-303).

## Findings

| ID | Grade | Finding |
|---|---|---|
| L-300 | L | From before this PR: the dummy hash for unknown names uses bcrypt cost 10. On PHP 8.4 and later, `PASSWORD_DEFAULT` hashes use cost 12, so a known name takes measurably longer than an unknown one there. The shipped image (PHP 8.3) is not affected; CI4 already uses cost 12. Hardening: derive the dummy hash from `PASSWORD_DEFAULT` at run time. |
| N-301 | N | Test gap: the timing test compares the null-character name only with an unknown name. A known name with a wrong password is not compared, so removing the dummy hash goes unnoticed. That comparison would also catch L-300 on PHP 8.4. |
| N-302 | N | Test gap: no test makes an import fail with work queued, so draining on that error path goes unnoticed. |
| N-303 | N | Latent: on PHP 8.3 with SQLite, PDO does not see migrate's `BEGIN IMMEDIATE`. An `after_commit()` call inside a migration would then run at once, before the COMMIT and even if it rolls back. No migration uses it today, and the test cannot tell on that setup. Hardening: mark migrate's transaction explicitly, or refuse `after_commit()` during migrate. |
| N-304 | N | Info: with sign-in off, re-enable with a null-character name answers 403 without a hash, in the same time as a non-admin name. It reveals nothing new. Re-enable is reachable only while the app is open to everyone. |

No High or Medium findings.

## Security

- **User names.** Every account-writing path refuses a name with a null character as sent. Nothing is trimmed into another account's name. Legacy and CI4 now agree.
- **Sign-in timing.** A null-character name costs the same password check as an unknown name. L-300 is a separate gap in the existing dummy hash, only on PHP 8.4 and later.
- **Rate limits.** The per-address cap covers sign-in, the current-password check and re-enable. Name variants and forged forwarding headers don't get around it, and other addresses are not affected.
- **Cleanups.** Queued file removals never run after a rollback on the shipped paths.
- **Gates.** gitleaks is clean and the config is unchanged. There are no secrets or exploit detail in the diff or the CHANGELOG.

## Verdict

**PASS (0H/0M/1L/4N).** L-300 is from before this PR and can go into a follow-up with N-301 to N-303. Commit this doc byte-for-byte as the last commit.
