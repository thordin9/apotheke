# QA gate: PR 245 backup storage and conditional manifests (re-check)

**Verdict: PASS.** No high or medium findings. The bytes and the tag now come from one object read, and the edge check that failed on the parent is green on this head.

Gated head `3d10715ac85b0c10b781a22f65263bb24beb9432`. Latest remote head is the same SHA. No movement. It is a fast-forward of `b507e90b9b802acf045f9f50e6ecb81d50ab71fc`, whose parent is the previous hold. Branch `feat/p4-4-restore-storage`. Base `main` `b58b30c80917dc36d126b9de7c5a02b702d69485`, 0 behind, 15 commits. Diff versus main +768/−34, 20 files. This commit changes 2 files, +14/−2. CI on this head: 22 success and 1 skipped deploy job. The parent’s edge dry-run had already failed; that check now passes.

## Checklist

| Check | Result |
|---|---|
| Head / base | Matches. 0 behind. 15 commits. Fast-forward of the reviewed parent. |
| Version pair | The handler copies the tag from the same object it then reads. PHP `version()` uses that one read and does not call metadata afterward. |
| Stale write | After the read response, the test replaces the object. The conditional write sends the read’s tag and is refused. The newer body remains. |
| Missing objects | A genuine not-found is recorded or skipped. Transport errors and a bare not-found status fail the export. |
| Locks | Digest names under a locks directory, not beside the object. The export does not add that directory. |
| Opt-in | Unset and local stay on disk. Remote is the exact backend value. Backup is the only new construction site. |
| Restore | Unchanged. Deferring leases and lock-first work leaves the default disk export and the existing restore path as they are. |
| Edge check | The client loads the production storage classes in dependency order and does not use Composer. The check job installs PHP 8.4 and curl with the same pinned setup action as the release workflow. Permissions stay contents read. No new secret. The deploy job is unchanged and still skipped. |
| Memory | Remote reads remain whole objects inside the existing response cap. |

## Findings

No new findings.

## Closed

M-1833. Dropping the tag from the object read, reading the tag from a later metadata call, and taking the tag only after the body is read all fail the new tests. The race client is `R2Storage` on `R2Bridge` with no injected transport.

M-1830. The tag is no longer expected from a second response. A read that omits it fails before any conditional write, and that test sees only the one read.

M-1831. A null remote read is recorded or skipped. Treating it as a hard failure fails the missing-object test. Transport and protocol failures still fail the export.

N-1832. The lock is not beside the object. Putting it back there fails the version test.

## Open

None from this change. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked and are not a hold.

## Security

The tag and the bytes are properties of one fetched object. A later replacement is a different object and does not change the tag already copied. The conditional write is refused when that tag no longer matches. Selection stays on disk unless the backend is exactly remote. Keys still cannot leave the storage root. Error text stays the fixed sentence. The edge check does not gain a token or a deploy. This diff adds no credentials or account, store, or database identifiers.

## Counts

Product PHP and the handler are unchanged since `b507e90b`. Those suite results are reused. The Worker and deploy scripts were re-run on this head. PHP 8.4.27. Scratch PostgreSQL 16 was stopped and its data directory removed. Distro cluster left down.

| Run | Result |
|---|---|
| Version, factory, and version tests | 14 tests, 47 assertions, pass |
| Backup storage | 2 tests, 34 assertions, pass |
| Worker and deploy scripts, this head | 57 pass |
| Typecheck and wrangler 4.157.0 types check | Pass |
| Root, both drivers | 564 tests; SQLite 9935 assertions, PostgreSQL 9984; 1 failure each |
| Module, SQLite | 1131 tests, 44043 assertions, 2 failures, 18 skipped |
| Module, PostgreSQL | 1589 tests, 50151 assertions, 2 failures, 4 skipped |
| Upgrade, both drivers | All steps equal |

The only local failures are the known module wrapper, zip-entry text, and served-copy alpha check.

Mutants on the parent handler, which this commit does not change: dropping the read tag, splitting the tag onto a later metadata read, and reading the tag after the body were killed.
