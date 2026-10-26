# QA: PR #120 (CI4: N-261 null character in user names, N-280, N-281)

Head `968e21f17b439a06945401cc872e5990d424b7d4`, base feature/ci4 `f3cdc91` (#119, tip and merge-base). CI4 on PHP 8.4, SQLite and PostgreSQL 17.
Scope: the delta since the #119 PASS. The merge commit `df30d29` has an empty remerge-diff.

## Checklist

| Area | Result |
|---|---|
| Lint, deptrac (0 errors), DI check | PASS |
| gitleaks `f3cdc91..head` (4 commits); `.gitleaks.toml` sha b5bc9f98…993a8f unchanged | PASS, no leaks |
| CI4 unit SQLite / PostgreSQL 17 | 462 / 588 OK (2 / 0 skipped) |
| CI4 HTTP suite SQLite / PostgreSQL 17 | 776 / 776 OK (39 / 6 skipped, environment) |
| Autofill and Media with `allow_url_fopen=0`, SQLite / PostgreSQL 17 | 45 / 47 OK, 0 skipped (same count as with it on) |
| CI 40314238676 | 22/22 at the head |
| N-261 probes, legacy vs CI4, both drivers | as claimed |
| Mutation, 20 mutants (1 equivalent) | PR suite 19/19 |

## Verification

- **N-261.** `Accounts::nameValid()` refuses a null character anywhere, before trimming, with 422 and the usual invalid-name message.
  - It covers register, add user and rename. The command line goes through the same `UserAdmin` calls, and a process argument cannot hold a null character.
  - `/register` passes the raw name when it holds a null character. Sign-in on (N-221) is unchanged.
  - The HTTP probe sent 10 names with a null character in register, add user and rename: leading, middle, trailing, only, doubled, with spaces or a tab, and around an existing name. Every one gives 422 and the invalid-name message. No account is created or renamed, and the result is the same on SQLite and PostgreSQL.
  - Legacy creates or renames to the trimmed name for the 7 names with a null character at either end. That deliberate difference is asserted in `Ci4AuthParityTest::testANullCharacterInARegisteredNameIsRefusedOnCi4` and tracked as N-251 on main.
  - **Valid names are unchanged.** These give the same outcome as legacy on all three forms: names trimmed of spaces, a tab or a newline, `Zoë`, `名前`, `ab.c_d@e-f`, and a 40-character name.
  - **No enumeration.** A null-character name gets the same answer whether or not the trimmed name exists, because the refusal comes before any lookup. Timing is flat (about 5 ms either way) and never slower than another invalid name. The register limit still counts the try. Nothing goes beyond N-260.
  - **Database compatibility.** No stored name can hold a null character on either app, so data moves both ways unchanged.
  - **Minimal change.** 3 lines in `Accounts.php` and 1 in `SignInController.php`, plus tests. No regressions in the CI4 Auth suites or the parity tests.
- **N-280.** The test builds a photo with one colour per quadrant, then checks orientations 1 to 8 by dimensions, four corner colours and byte equality with the copy built from the path. I checked the corner table against the EXIF orientation definitions, and it is correct for all 8.
- **N-281.** `clearStub`, `suggestCount` and `uploads` share one curl helper that fails the test on anything but 200. A mutant gives "GET /_stub/countx: Failed asserting that 404 is identical to 200".
- **ci.yml.** One step changed: it now runs `ci4/tests/Autofill` and `ci4/tests/Media` under `-d allow_url_fopen=0` with `--fail-on-empty-test-suite`. `--fail-on-skipped` and `--testdox` were dropped (N-290). No other step changed.
- **Cloudflare phase.** The new code adds no static or per-request state, no superglobals and no storage access. The curl helper is test-only.

## Mutation

| Group | PR suite (on + `allow_url_fopen=0` step) |
|---|---|
| Orientation handling: 2, 3, 4, 5 (two ways), 6, 7, 8 wrong; orientation ignored; bytes EXIF false; `data:` revert (11) | 11/11; the `data:` revert is killed only by the Off step |
| Stub helper: count, uploads or clear path gives 404; status assert dropped plus clear 404 (4) | 4/4 |
| Stub helper: status assert dropped alone (1) | equivalent (every call answers 200) |
| N-261: no check; check after trim; other message; register passes the trimmed name (4) | 4/4; the last is killed only by `Ci4AuthParityTest` (N-291) |

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-290 | N | CI: the `allow_url_fopen=0` step no longer uses `--fail-on-skipped`. A test that skips only under Off would pass unseen. Today 0 are skipped. Hardening: restore `--fail-on-skipped`, or skip only by name. |
| N-291 | N | Test gap: the raw name in `/register` is covered only by the root `Ci4AuthParityTest`, which needs PHP 8.4 and the CI4 vendor. The CI4 suite misses a mutant that passes the trimmed name. Hardening: add a CI4 register-form test with a leading and a trailing null character. |

No High, Medium or Low findings.

## Security

- **Auth validation.** A user name with a null character is refused as sent on every account-writing path, and nothing is trimmed into another account's name. The refusal comes before any lookup or password hashing, with the same message as any invalid name, so it reveals nothing about existing accounts.
- **Privacy.** Only a resized copy with metadata stripped leaves the install, with `allow_url_fopen` On or Off.
- **Gates.** gitleaks is clean and the config is unchanged. There are no secrets or hosts in the diff, the CHANGELOG or the docs.

## Verdict

**PASS (0H/0M/0L/2N).** N-261, N-280 and N-281 are resolved. N-290 and N-291 can go into a follow-up PR. Commit this doc byte-for-byte as the last commit.
