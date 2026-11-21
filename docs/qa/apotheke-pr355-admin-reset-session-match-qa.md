# PR #355 admin reset session match QA

Head `a5c0ebd3e7af0d794de36471619c4d5d744cb6be` (merge of main `0674342ce9287b87adf778d5d76db1b93263e2ff`, `#342`, into `f0f3ccd4`). Main is an ancestor, 0 behind. Four files, +38/−8. The full gate ran at `ec7b2821`. The catch-ups since then were checked as merge-only reads.

## Checklist

1. `HostedAdminReset` deletes a session only when some `role = 'admin'` row matches `uid|i:<id>;` at the start of the payload or after `;` or `}`. The claim delete and `DELETE FROM users WHERE role = 'admin'` are unchanged. A viewer session stays. A pending admin's session is still removed, as before, because the filter is the role. The command still refuses when the install is not hosted, which is how `primary` stays out of it.
2. The three patterns are the same ones `HostedDisabledUsers` uses for one id. Here the id is `users.id` inside `EXISTS`, not a bound parameter, so the statements are not the same call. The text is copied (N-1922).
3. `HostedAdminResetTest`: 26 tests, 307 assertions, SQLite and PostgreSQL, connection and batch. Admin sessions at the start, after `;`, and after `}` are removed. `otheruid|i:<id>;`, a later `otheruid`, and `uid|i:<id>0;` stay. The older test still keeps a viewer session and `uid|i:<id>1;`, and still clears the claim row. Restoring the old `%uid|i:` match, dropping the semicolon, or dropping the `}` pattern each fails the new test, 4 failures. The id is an integer, so the pattern has no `%` or `_`.
4. The changelog header and every released section equal main. Unreleased adds the `pull/355` Fixed bullet above main's `#342` Maintenance entry. README and VERSION match main (3.65.0). The hand-off moves the latest-release line from v3.58.0 to v3.65.0 and replaces the merged `#344` open-PR line with this PR.
5. Merge-only reads at `5f6252c5`, `f1d14c1f`, `f0f3ccd4` and `a5c0ebd3`: the code and test diff against main equals the gated `ec7b2821` diff, apart from index lines and hunk offsets. Nothing outside the four files differs from main.

## Findings

N-1922. The uid-field predicate is written again in `HostedAdminReset` instead of shared with `HostedDisabledUsers`. The reset copy is what stayed on the old match after `#349`. Next id 1923.

## Security

The reset still runs only when hosted, inside the active-admins unit. It still removes every admin row and the claim. A session is removed only for an admin id, and only on that id's own `uid` field. A viewer's session, a key that merely ends in `uid`, and a longer id stay. A string that happens to contain the same `;uid|i:<id>;` bytes would also match. PHP's serializer writes the three anchored shapes, which the new test covers.

## Verdict

PASS at `a5c0ebd3` (full gate at `ec7b2821`, merge-only reads since). No high or medium finding. N-1922.

CI was not waited on. At the last full read, `refs/pull/355/head` was `f1d14c1f`, with Analyze (two jobs) and CodeQL passing and no other check reported.
