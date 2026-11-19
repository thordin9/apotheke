# PR #318 hosted installation env QA

Head `bb36de799c25865f04c99567d97d8f048606c701` (live `refs/pull/318/head` at the verdict), a plain merge of main `386bcb56e61eb981c9c5c824cb6e9aa6e0f13cca` (v3.51.0) into `b726d19a`, 0 behind main. Full gate at `79e34e27`, deltas at `62d37b8a`, `319d7e52` and `b726d19a`, then a merge-only read (evidence file).

## Checklist

1. A non-primary container gets `APOTHEKE_HOSTED=1` and `APOTHEKE_INSTALLATION` set to its own name, and no `APP_PASSWORD`. `primary` keeps `APP_PASSWORD` and does not get either new variable. Both come from the container object's name. A request header or body does not change them. Lifecycle provision's bootstrap is still unwired and does not start a container, so it does not pass an env. The three hosted-env edge tests pass.
2. `accounts:hosted-reset-admins` refuses unless hosted, using the same `APOTHEKE_HOSTED` bool parse as #326. It deletes only `role = 'admin'` (active and pending) inside one `users` unit under `active-admins`. A viewer stays. A second run changes nothing. Exit 0 prints one fixed sentence and empty stderr. Not hosted: exit 1, a fixed stderr line, empty stdout, accounts unchanged. A thrown error prints a fixed line and not the exception. No entrypoint calls it.
3. The reset deletes the admins' session rows, the first-admin claim row and `role = 'admin'` users in one `users` unit under `active-admins` (N-1898 closed). After the reset a new claim can be created and redeemed on SQLite and PostgreSQL. A deleted admin is not found, so that session cannot stay an admin. Hosted sign-in stays on, so there is no anonymous admin. Registration stays closed while the account count is 0.
4. The account API still refuses to delete or demote the last active admin. This command is the documented manual operator step that removes every admin so a claim can be used.
5. PostgreSQL race, eight times, both modes: reset waits on `active-admins`, then deletes the admin inserted under that lock. `claim()` and `claim-create` take the same key.
6. Mutations, file restored after each: drop the hosted throw, and the off test fails. Drop the role filter, and the viewer test fails on SQLite and PostgreSQL, both modes. Drop the lock, or run the delete outside the unit, and the PostgreSQL race fails in both modes. SQLite does not observe that advisory lock. At `b726d19a`, a claim delete before or after `units->run` fails the same-unit test on both drivers, both modes (L-1900 closed). The test hooks default to null and no caller in the app passes one.
7. CHANGELOG preamble and every released section match main `386bcb56`. Unreleased holds only the two #318 bullets (L-1897 closed). README and VERSION match main. The code diff against main is byte-equal to `b726d19a`'s against its base (891 lines). Only `docs/HANDOFF.md` and `docs/history/handoff-log.md` differ, adding main's v3.51.0 and the #327/#329 notes.
8. L-2194 (defined in the e164423a QA doc, which was kept local): installations started before this PR had `admin` seeded with the Worker's `APP_PASSWORD`, and a running container keeps its old env until it next starts. `c0a0d7e1` adds the remediation, and `docs/operator-console.md` documents it for "a hosted installation that already has an admin". `62d37b8a` adds that those installations need a container restart and then `accounts:hosted-reset-admins` before a tenant gets access. L-2194 is closed through L-1899. Running it on live installations is an operator task outside this PR.
9. Suites fail only the known local zip-entry test (`BackupHelpersTest`). CMYK did not run. CI is recorded below and was not waited on.

## Findings

L-1897. Unreleased repeated the shipped #326 bullet. Closed in `46925500`.

N-1898. The reset left the claim row and session records. Closed in `a780ed48`.

L-1899 (rest of L-2194). Docs did not name the affected installations or the restart. Closed in `62d37b8a`.

L-1900. A claim delete outside the unit was not caught by the tests. Closed in `b726d19a`.

N-1901. The session match is `LIKE '%uid|i:<id>;%'`, so a key that merely ends in `uid` with an admin's id is also removed. Accepted by the PM.

Next id 1902.

## Security

No path in this diff gives `APP_PASSWORD` to a non-primary installation or sets `APOTHEKE_HOSTED` on `primary`. The reset is hosted-only, limited to the admin role, and runs the delete inside the `active-admins` unit. It is not a start-up hook. It bypasses the account API's last-admin guard only as that documented operator step. Afterwards sign-in stays on. There is no anonymous-admin window. The reset also removes any earlier claim and the admins' session rows. The test hooks are null in production wiring.

## Verdict

PASS at `bb36de79`. No open high, medium or low finding. L-1897, N-1898, L-1899, L-1900 and L-2194 are closed. N-1901 is accepted.

CI on this head, as it stood at the read: 10 checks pass and 12 are pending. Not waited on.
