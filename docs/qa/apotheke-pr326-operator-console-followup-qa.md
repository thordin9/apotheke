# PR #326 operator console follow-up QA

Head `bcaee6351cb318fec32b94b50f797663f75382b7` (live), 0 behind main `77131ed8`. Full gate at `1114c679` (base `184d4553`, v3.48.0, eight commits, 28 files, +879/−67), delta at `ab8cce58`, merge-only read at `bcaee635`.

## Checklist

1. A finite float soft is ceiled only when `ceil` is below `2^63`. `1e20`, the limit constant, INF, NAN, negatives and a fractional hard make the body invalid, so the reader returns 503. N-1895 is closed.
2. Once an active admin exists, a right token, a wrong token and an empty token all return 410 with the same message, the claim row is removed, and no account is added. A second try is the same 410. L-1891 is closed. The unit still checks again under `active-admins`.
3. The dump holds one read transaction. SQLite is deferred; PostgreSQL is `REPEATABLE READ, READ ONLY`. A row committed by another connection during the dump is absent from every table and present afterwards. The transaction is rolled back. D1 does not open one: the Installation object runs one request at a time. N-1893 is closed for the concurrent drivers.
4. A claim stops `GUARD_SECONDS` (600) before it is 2 hours old and returns 500 `backup_failed`, with the claim released and no parts left. One second earlier it finishes. A lost claim returns 409 `backup_in_progress` and writes nothing. If the manifest was already stored, that backup is deleted. N-1894 is closed.
5. Parts with no manifest are deleted once their id time is 86400 seconds old. A folder one second younger is kept. A folder with a manifest is kept, including an unreadable manifest and an export. 7200 seconds is under a day, so a backup inside the takeover window is not collected.
6. `APOTHEKE_HOSTED` of `1`, `true`, `on`, `yes` is hosted in any case, and with surrounding spaces. `0`, `false`, `off`, `no`, empty, `maybe` and `2` are not. Hosted with no `APP_PASSWORD`: `auth status` is on, the migration creates no account, sign-in stays on, registration stays closed, and the claim creates the first admin. Hosted with `APP_PASSWORD`: `admin` is seeded once and the claim returns 410. A later call does not add or replace that account. A short password is still refused. Not hosted, an empty password stays off.
7. README and VERSION match main. At `1114c679` the Unreleased bullet linked pull/000 (L-1896). Suites fail only the known zip-entry test. CMYK did not run. No deadlock flake.
8. Delta at `ab8cce58`: `c040e620` relinks the bullet to pull/326, closing L-1896. `a7f0fbe3` makes `claim-create` refuse while an active admin exists. The check is in the `active-admins` unit before the claim row is replaced. It exits 1 with a fixed stderr message, and stores and prints no token. A pending admin or an active non-admin does not count. With no active admin, the claim flow still works. Mutations removing the check or moving it before the lock fail the tests on SQLite and PostgreSQL. The PostgreSQL race passed 10/10.
9. Merge-only read at `bcaee635`: the old head is an ancestor, and there is no non-merge commit after `ab8cce58`. The own diff over main `77131ed8`, excluding `CHANGELOG.md` and `docs/qa`, is byte-equal to the `ab8cce58` own diff over `590cf9df` (1677 lines). The CHANGELOG preamble and released sections equal main, and [Unreleased] holds only the #326 bullet. README and VERSION equal main.

## Findings

None open. L-1896 (the Unreleased bullet linked pull/000) is closed by `c040e620`.

N-1895, L-1891, N-1893 and N-1894 are closed, with the D1 note in the checklist.

Next id 1897.

## Security

Hosted without `APP_PASSWORD` starts with sign-in on and no account. The claim is the first admin, and it is single-use. With an admin already present, every token gets the same 410 and the row is removed, and `claim-create` refuses to issue a new claim. Seeding writes the password account only when the user table is empty. Out-of-range limits are refused. A job backup does not overlap a takeover, and a lost claim does not keep a second export.

## Verdict

PASS at `bcaee635`. No open finding.

CI on this head: 22 checks pass, including PHP 8.4 sqlite and pgsql. One skipped.
