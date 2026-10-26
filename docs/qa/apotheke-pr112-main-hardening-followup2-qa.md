# QA: PR #112 (main, fixes for #111 findings)

Head `1171a879245fc870cb3cdd135e9f813f9ed30610`, base main `bf6fce9` (v2.6.16). Legacy app, PHP 8.3, SQLite and PostgreSQL 17.
Scope: L-170, N-171 to N-175. Full security gate on the delta since the #111 PASS.

## Checklist

| Area | Result |
|---|---|
| Lint (`scripts/lint.sh`) | PASS |
| gitleaks `bf6fce9..head`; `.gitleaks.toml` sha b5bc9f98…3a8f unchanged | PASS, no leaks |
| PHPUnit SQLite / PostgreSQL 17 | 499/499 (13 / 3 skipped, environment) |
| `HardeningHttpTest` + QA probes, 3x per driver | PASS; `testEditsAtOnceKeepTheOriginal` flake not seen |
| Mutation, 22 mutants | PR suite 15/22; with QA probes 20/22 |
| The 3 #111 survivors (rollback queue, queue before COMMIT, account key) | killed on both drivers |
| Photo requirement | holds |
| CHANGELOG / docs | hardening wording, PR id only |

## Verification

- **L-170.** QA rotation probe through a trusted proxy, 50 wrong tries from one /64 per endpoint, both drivers:
  - Results: `/login` 30×401 then 20×429. `/account`, password change, sign-in off, and re-enable: each 30×403 then 20×429.
  - A right password at the cap gets 429, and the password, sign-in state and address rows (30) are unchanged.
  - The same 50 tries from separate IPv4 addresses get no 429, so IPv4 is unchanged.
  - Order: the address cap is checked before the account check (0 account rows at the cap). A success clears the account bucket and takes back only its own address row.
  - Side effect, as documented: these tries share the sign-in cap. The sign-in form from that /64 gets 429, and another /64 is unaffected.
- **N-171.** All callbacks run after the COMMIT, in order. A failing callback is logged as `apotheke: after-commit cleanup failed: <message>`, with no trace or arguments, and is not rethrown. Today's callbacks only remove files, so there are no secrets in the log.
- **N-172.** The queue lives in `src/db.php`, and `tax_set_default` goes through `db_tx`.
  - QA import probe (instrumented copy): a preview rollback or an exception drops the queue, the file is kept, and nothing runs later. A commit runs each callback once, after COMMIT.
  - QA restore probe: a forced exception or a database failure drops the queue and keeps the file. A commit runs the callback once.
  - Both probes fail on `bf6fce9`.
- **N-173.** The 3 #111 survivors are now killed on both drivers.
- **N-174.**
  - Compared with v2.6.16, keys change only for `::/96` (including `::1`) and `64:ff9b::/96`, which now keep the whole address. That is the 2.6.15 form.
  - IPv4, IPv4-mapped, the other IPv6 /64 keys and the per-account keys are unchanged.
  - The comment is fixed.
- **N-175.** The test catches only `DomainException` and asserts outside the `try`.
- **Photo requirement.** 10 parallel adds against a limit of 3 give exactly 3.
  - Each original is byte-identical to the upload, and `/media/full` serves the resized copy.
  - A rolled-back delete keeps all files. A committed delete removes the served file, thumbnail, original and map entry.

## Mutation

| Group | PR suite | With QA probes |
|---|---|---|
| L-170 logic (8) | 6/8 (SQLite and PostgreSQL) | 8/8 |
| N-171, N-174, rollback drop (6) | 6/6 | 6/6 |
| #111 survivors (3) | 3/3 (both drivers) | 3/3 |
| N-172 call sites: import, restore, migrate (5) | 0/5 | 3/5 |

The 2 left after the QA probes:
- **Restore drain on commit.** Equivalent today, because the settings save that follows drains the queue.
- **Migrate drain.** Not probed.

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-190 | N | Test gap: no test sends wrong current-password or re-enable tries up to the address cap. The suite misses mutants that take back the address row before the password check. Hardening: add a test where wrong tries reach the cap. |
| N-191 | N | Test gap: the import, restore and migrate drain call sites have no direct tests (0/5 mutants killed). Hardening: add call-site tests like the QA probes. |
| N-192 | N | Info: on upgrade from v2.6.16, address-cap counters reset once for `::/96` (including `::1`) and `64:ff9b::/96` clients. That's at most 15 minutes for sign-in and 1 hour for registration. Local-use NAT64 prefixes (`64:ff9b:1::/48`, network-specific) are still grouped by /64. Hardening: note this in the docs. |

No High, Medium or Low findings.

## Security

- **L-170.** Every password check, not just the sign-in form, now counts toward the per-address cap. A /64 rotation stops at 30 on all five endpoints, and a right password at the cap is refused.
- **IPv4.** Behaviour and stored keys are unchanged.
- **File cleanup.** It runs only after the outermost COMMIT on every path that ends a transaction. A rollback never removes files, and one failing cleanup doesn't skip the rest.
- **Gates.** gitleaks is clean, the config is unchanged, and there are no secrets or exploit detail in the docs or CHANGELOG.

## Verdict

**PASS (0H/0M/0L/3N).** N-190 to N-192 can go into a follow-up PR. Commit this doc byte-for-byte as the last commit.
