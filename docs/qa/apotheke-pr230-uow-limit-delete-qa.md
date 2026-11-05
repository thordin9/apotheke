# QA review: PR #230, attachment limit and item delete in the unit

- PR: #230 `fix/attach-limit-delete-once` (Coder2): "fix(core): check the attachment limit and item delete in the unit"
- Head reviewed: `bc7a8f4d36eab4ff52075f91ec9d93681c74e071` (8 commits; main v3.18.0 plain-merged; `9ef2ba7` still an ancestor, no rebase)
- Scope: L-1666, L-1667, the controller's undo token for a skipped delete, L-1712 (lease test), L-1713 (run order by database id, applied_at on the database clock), N-1714 (sweep test), CHANGELOG
- QA gate, 5 Nov 2026 (Europe/Athens)

## Checklist

| Check | Result |
|---|---|
| Head, 0 behind, mergeable | confirmed, clean |
| CI on head | 20/20 success, including both upgrade and rollback jobs |
| Root suite, SQLite / PostgreSQL 17 | 463 OK each (6 / 1 skipped) |
| Module suite (both drivers in one run), SQLite / PostgreSQL env | 1357 OK each |
| lint, deptrac (0), DI, routes --check, check-commits (6), gitleaks | clean |
| Merge commits (remerge-diff) | CHANGELOG and HANDOFF only |
| CHANGELOG | one Unreleased line; merges with main without conflict |
| Schema or migrations | unchanged |
| Lock order | unchanged: add and delete use the `items` unit keyed by item id; the new guards are plain reads |
| Upgrade gate | not run locally (no PHP 8.3 on the QA host); green in CI on both DBs |
| Real-parallel probes (6 processes, 5 rounds, both modes, both DBs) | see L-1743 |
| Mutations | 18: 15 killed, 3 survived (N-1740, N-1741, N-1745) |

## Verified

- **L-1666.** The guard `existing + new <= limit` is queued ahead of the inserts. That puts it under the lock in connection mode and makes it a batch guard in batch mode. It has no off-by-one: the `<` and the count-one-file mutants were killed. On failure the stored files are removed through the storage interface, which covers disk and R2, and the same 422 is returned. Parallel adds at the limit held in connection mode on both DBs and in SQLite batch mode, with no orphaned files.
- **L-1667.** The re-read in the unit and the batch EXISTS guard both lead to `false`, with no history row and no file removal. Parallel deletes wrote exactly one deleted-history row in connection mode on both DBs and in SQLite batch mode.
- **Skipped delete in the controller.** No undo token is created, and the response is the normal redirect without an undo link.
- **L-1712.** The lease is held at 119 s, free at 121 s, and expires on the database clock at 120 ± 5 s. The 1 s lease mutant gives 4 failures; the two-week mutant gives 4 errors.
- **L-1713.** applied_at now comes from the database clock. The pruning never deletes the run being inserted, because the delete runs before that run's insert and keeps the newest KEEP-1. A run in progress has no marker row yet, and the lock row is excluded. Ordering by applied_at is killed (12 failures); keeping the oldest, `<`, and keeping KEEP rows are all killed.
- **N-1714.** Removing the no-lock-row branch of the sweep gives 8 failures.

## xmin / rowid analysis (L-1713)

- **(a) Updates after insert:** only the lock row is ever updated (lease renewal), and it is excluded from ordering and pruning. Run rows are insert-only, so a run never gets a new xmin.
- **(b) Freezing:** VACUUM FREEZE in the middle of a probe left the xmin values and the order unchanged. PostgreSQL 9.4 and later keep the raw xmin and mark frozen rows with a hint bit.
- **(c) Stability and wraparound:** age() is computed from one reference point within each read, so the order is stable while other transactions run. The order would flip only if a kept run's xid became more than 2^31 transactions old.
- **(d) SQLite/D1 rowid:** a rowid is reused only when the highest row is deleted, and the new row still gets max+1. The pruning never deletes the newest run, and VACUUM renumbers rows in rowid order, so no reuse case mis-orders runs.
- **(e) Production on PostgreSQL:** staged restores run only when the executor has no connection (batch-only D1 or Durable Object SQLite). The service always builds a connection executor for SQLite and PostgreSQL, and no setting changes that. On PostgreSQL the xmin order is therefore used only by tests and parity checks. No migration fallback is needed.

## Findings

### High
None.

### Medium
None.

### Low

- **L-1743.** In the local PostgreSQL batch model, real-parallel requests pass both new guards: more attachments than the limit are stored, and more than one deleted-history row is written. Batches there run under read committed with no lock, so concurrent guards all pass before any of them commits. D1 and SQLite run batches one at a time, and connection mode holds the unit's lock, so production is unaffected. The gap is in how faithfully the local model reproduces D1: the PostgreSQL batch tests pass only through deterministic seams. Suggested fix: serialise local PostgreSQL batches, for example with a transaction advisory lock.

### Note

- **N-1740.** No test covers the controller's skipped delete, so the undo-token mutant survived.
- **N-1741.** No test covers an error other than "item gone" during a delete, so the mutant that swallows any UserError survived.
- **N-1742.** The in-repo race tests simulate concurrency through seams. Real parallel runs exist only in this review (see L-1743).
- **N-1744.** delete() reads the attachment file list before its unit, so a file added in between can stay on disk. This predates the PR.
- **N-1745.** In the PostgreSQL ordering, rows written in one transaction share an xmin, and age() wraps after 2^31 transactions. Neither happens on the paths used in production. The `>=` mutant in outcome() survives because run order values are always distinct there.

## Security

- No secrets, tokens or identifiers; gitleaks is clean.
- No new routes, permissions, migrations or dependencies.
- The limit and delete checks narrow writes. A failed add removes its stored files.
- L-1743 affects only the local test model.

## Verdict

**PASS.** No High or Medium findings. L-1743 and the notes can be handled in a follow-up.
