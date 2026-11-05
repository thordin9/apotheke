# QA re-check: PR 235 at 500cead

Previous hold was `95a1dd8d742d3ac97c107f352296bdb78dcd9583`. This head is `500cead6e27ba9f44abc83afcaa289aca6a4686d` (3 commits). Base `main` `e93a069976238fb376df63eef94cf1c544ae4969`, unchanged. Remote head matches. The fix commit is the only delta since the hold (+191/−28, 8 files).

## Verdict

**PASS.** No H or M findings. Next free ID: 1793.

## Checklist

| Check | Result |
|---|---|
| Head / base | Remote head is the pinned SHA. Base unchanged. One new commit. |
| M-1783 | Closed. Suffixed guard text, including an offset and the extended not-null code, maps to the guard code on the HTTP path. A wrong code, a busy code, a lowercase code, a second space, a trailing token, and a unique-constraint line stay plain failures. The value on a second line stays out of the exception. |
| L-1784 | Closed. Success is a JSON boolean true. String false, string true, and numeric 1 fail closed. A marker in the error or in rows was absent from the message, trace, and previous exception. |
| L-1785 | Closed. Redirect following is off. A redirect was a generic failure; the follow-up success body was not used. The unit test matches the source flag; the transport check was separate. |
| N-1787 | Closed. 1001 tiny rows are two chunks. Three wide items rows are three chunks, each large enough that two exceed the items cap. Mutants of 1001 rows and a 512 KB items cap fail. |
| N-1788 | Closed. The new ancestor-cycle test hangs when that bound is removed. The descendant-cycle test still hangs when its bounds are removed. Both drivers, batch mode. |
| N-1789 | Closed for the dump. An ordinary table that only shares a virtual prefix is kept. The five shadow names and a vocab shadow are omitted. A real FTS catalog dump still emits both virtual statements and drops the shadow tables. |
| N-1790 | Closed. The database guide states the 100 KB statement cap and the restore caps (1000 rows, 256 KB for items, 512 KB otherwise). |
| N-1786 | Still deferred. `exec()` does not apply the shared refusals. No caller. Not a hold. |
| Prior limits and sanitization | Unsuffixed guards, 100 parameters, 100000 bytes, pragma and function refusals, and generic failure text still hold. Deptrac 0 violations. DI clean. Commit subjects pass. Diff token scan: 0. |

## Closed

M-1783, L-1784, L-1785, N-1787, N-1788, N-1789, N-1790.

## Open

### N-1786 `exec()` skips the shared refusals

Unchanged. Deferred with the migration slice. Not a hold.

### N-1791 Shadow helper with no parent list

`isFtsShadow` with an empty parent list still treats any name that ends in one of the five suffixes as a shadow, including a name that is not under a virtual table. The dump passes the virtual names and uses exact `name = virtual + suffix` matches, so this branch does not drop catalog tables. No production caller uses the empty list.

### N-1792 Guide states a hard byte cap for every staged statement

The new restore sentence says each staged statement stays within 512 KB, or 256 KB for items. A single row larger than that cap is still sent alone. The row cap of 1000 is exact.

## Security

Failure text is still not copied into the exception for batch, query, or exec, including suffixed guard text and a non-string error value. A non-boolean success flag fails closed. The default transport does not follow redirects. Bounds on query and batch are unchanged. The constructor still rejects userinfo. No production code builds the executor from request data. The fix diff has no credentials, account ids, or database identifiers.

## Counts

PHP 8.4.27. PostgreSQL 16. Changed tests on SQLite: 39 tests, 91 assertions, pass. Cycle and cap tests on both drivers: 6 tests, 50 assertions, pass.

| Run | Result |
|---|---|
| Module, both drivers | 1513 tests, 48881 assertions, 2 failures |
| Module inside root, both drivers | 1513 tests, 48885 assertions, same 2 failures |
| Module, SQLite only | 1085 tests, 43374 assertions, same 2 failures, 14 skipped |
| Root, SQLite | 463 tests, 8840 assertions, 1 failure, 29 skipped, 1 incomplete |
| Root, PostgreSQL | 463 tests, 9925 assertions, 1 error, 1 failure, 2 skipped |

The two module failures are the zip size case and the served-copy alpha case. Neither file is in this diff. The root failure is that wrapper. The incomplete SQLite case is the connect test with no server configured. The PostgreSQL error is the `LOGIN` event name, which this server does not provide. Same environment limits as the hold. No new product failure in the delta.

Mutants, all killed, sources restored: guard always true (10/23); always false (14/24); `D1_ERROR` token removed (2/23); suffix clauses removed (5/24); truthy success flag (1/1); redirect following on (1/1); row cap 1001 (2/2); items cap 512 KB (2/2); ancestor bound removed (hung); descendant bounds removed (hung); prefix shadow match (1/1).
