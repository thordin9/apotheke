# QA: PR #111 (main hardening follow-up to #106)

Head `43486664e786f3196899151b84c8c6195718949b`, base main `cbdae36`. Legacy app, PHP 8.3, SQLite and PostgreSQL 17.
Scope: L-120 to L-123, N-124 to N-126. Full security gate.

## Checklist

| Area | Result |
|---|---|
| Lint (`scripts/lint.sh`: PHP, JS, links, shellcheck, changelog) | PASS |
| gitleaks on `cbdae36..head` (3 commits), `.gitleaks.toml` sha b5bc9f98…3a8f unchanged | PASS, no leaks |
| PHPUnit SQLite | 494/494 (13 skipped, environment) |
| PHPUnit PostgreSQL 17 | 494/494 (3 skipped, environment) |
| New tests on old main (`cbdae36` + PR tests), both drivers | address cap, re-enable limit, photo adds, after-commit and IPv6 tests fail; the rest pass as expected |
| `HardeningHttpTest` repeated 3x per driver, plus QA bursts | PASS; no `testEditsAtOnceKeepTheOriginal` flake seen |
| Mutation spot-check, 22 mutants on the new logic | 19/22 killed on each driver |
| CHANGELOG and docs wording | hardening wording, PR id only |

## Verification

- **L-120.** 20 address rows plus 1 foreign row, then 5 successful sign-ins at once: 20 rows stay, the foreign row and another client's account row stay. Wrong tries still count, and a 429 stores no row. On old main the address rows drop to 0.
- **L-121.** Docs read: the configuration, Control Panel and deployment pages, plus the comments on the route and on `rate_limit()`, place `TRUSTED_PROXIES` next to the per-address cap. No device skip was added.
- **L-122.** Parallel bursts: 12 adds at once × 8 rounds, with 0, 1 or 2 photos already there and `max_photos=3`.
  - PR head: always exactly 3 photos, on both drivers and across 4 runs each.
  - Old main: up to 5 photos on PostgreSQL, and 500s on SQLite.
  - Refused adds leave no served file and no original.
- **Photo requirement.** In every burst round, each kept original is byte-identical to the upload. The served copy is resized and differs from the original, and `/media/full` serves the resized copy.
- **L-123.** The new tests exercise what they claim.
  - Mutation spot-checks: removing the current-password throttle, the re-enable throttle or the admin lock is killed. So is moving the `items` write after the count, or letting a missing-original copy pass.
  - The copy-missing-original test now asserts outside the `try`.
- **N-124.** Re-enabling sign-in now shares the per-account sign-in bucket. The 11th try gets 429 and sign-in stays off, and a right password clears that bucket.
- **N-125.** `address_scope` results:
  - IPv6 groups by /64, whatever the case or zero-padding.
  - IPv4 and the stored IPv4 keys are unchanged (old sha256 key).
  - IPv4-mapped addresses keep their old key.
  - Zone ids, malformed values and `cli` pass through unchanged.
  - The per-account key still uses the full address.
  - XFF is ignored with no trusted proxies, or when the peer isn't listed. With a listed peer, the rightmost untrusted hop is used and scoped.
- **N-126.** Checked behaviour:
  - Callbacks run once, after the outermost COMMIT, and nested callbacks wait for it.
  - A rollback drops the queue, and the queue is empty after every `db_tx`.
  - Over HTTP, a photo delete or cover replace that fails keeps all files, and after a commit the old files go.
  - PHP statics are per request, so the queue cannot leak between requests.

## Findings

| ID | Grade | Finding |
|---|---|---|
| L-170 | L | The current-password checks and the re-enable check use only the per-account throttle. That throttle is keyed by the full client address and has no /64 or per-address cap behind it. QA evidence: 50/50 tries pass across one /64, while the address cap stops at 30. Hardening: also apply the per-address (/64) cap on these checks, or scope the account key's address. Follow-up. |
| N-171 | N | If an after-commit callback throws, the request reports a failure after the data has committed, and later callbacks are skipped. Files can be left behind, but no data is lost. Hardening: run each callback in its own try and log. |
| N-172 | N | Transactions opened directly (import, default taxonomy, migrations, restore) don't drain the queue, so a callback queued there runs at the next `db_tx` in the same request. Only no-op callbacks reach this today. Hardening: route these through `db_tx`, or drain or clear the queue there. |
| N-173 | N | Test gaps: 3 mutants survive. Keeping the queue after a rollback, running it before COMMIT, and scoping the per-account key by /64 are not detected. Add assertions that a rolled-back callback never runs later and that the account key keeps the full address. |
| N-174 | N | The `address_scope` comment says IPv4-mapped addresses become "the IPv4 address", but the code keeps the mapped form. The behaviour is right because the keys don't change. Also, translated prefixes (`64:ff9b::/96`, `::/96`) collapse into one /64. Hardening: fix the comment, and consider treating translated /96 prefixes like IPv4. |
| N-175 | N | `testAReplacedPhotoIsRemovedOnlyAfterTheCommit` catches `RuntimeException`, which also catches PHPUnit failures. The message check still fails the test, but asserting after the `try` would be cleaner. |

No High or Medium findings.

## Security

- **Sign-in caps.** A success no longer resets the per-address cap; it takes back only its own row. Concurrent successes keep the other attempts counted.
- **IPv6.** IPv6 clients are counted by /64 for the address-only buckets. IPv4 and existing counters are untouched.
- **Re-enable.** Re-enabling sign-in is throttled like a sign-in. L-170 tracks the remaining gap for password checks off the sign-in page.
- **Photo limit.** The photo limit holds under concurrency on both drivers.
- **File cleanup.** File removal is tied to the outermost COMMIT, so a rollback never deletes stored files. The photo requirement still holds end to end.
- **Gates.** gitleaks is clean, the config is unchanged, and no secrets or exploit detail appear in the docs or CHANGELOG.

## Verdict

**PASS (0H/0M/1L/5N).** L-170 and N-171 to N-175 can go into a follow-up PR. Commit this doc byte-for-byte as the last commit.
