# PR 282 storage preview paths — QA

**Verdict: PASS** at `87cf087ce0d85c34e2cdcb6916f1e8f959c17487` (parents `cf2c4d84b5449c278c245b53fd770ab3a2ae73e8` and main `362e747750e316818e586b7c2c8c08cd794e7c01`). This is a merge-only read on top of the full-gate PASS at `e64414979a63e97fc32b6b15d780cb7262c988e6`. L-1852 is closed. H-1849 stays closed and N-1850 stays open. There is no hold.

## Checklist

| Check | Result |
|---|---|
| Ancestry | `e6441497` is an ancestor. The head contains main and is 0 behind. |
| Link commit | `cf2c4d84` changes only the Unreleased bullet, which now ends with the `(#282)` link. |
| Own diff | Apart from CHANGELOG and HANDOFF, the diff against main matches the reviewed `0840308c..e6441497` diff with index lines dropped. It is 15 files, +314/-15. |
| Changelog | The bullet appears once under Unreleased. The release sections match main, including 3.36.3 and 3.36.4. |
| HANDOFF | Main's lines are kept and the #282 sentences are present. A stale wording about later work is left for a later edit, so it is not a finding here. |
| README, VERSION | Both match main. |
| Boot, auth status, metadata | The same code as the full gate. The checks there still apply. |
| CI | D1 (local workerd), CodeQL, Lint, DI rule, upgrade and rollback (sqlite and pgsql) and the headless mobile checks succeeded. Tests (PHP 8.4, sqlite and pgsql), E2E and the Docker jobs were still running at the time of this read. |

## Findings

**L-1852 (closed).** The Unreleased bullet now has the `(#282)` link that the release test requires.

N-1853 (open, non-blocking). The new Worker refuses HTTP HEAD. An older PHP build that still sends HEAD cannot read metadata until both sides are updated. This change updates both.

H-1849 stays closed. N-1850 stays open. The next free id is 1854.

## Security

The merge brings in no code change from this PR. The full-gate security notes still hold. `?action=head` uses the same handler, key check and installation prefix as GET, and it returns no object bytes. HEAD returns 405 with no body. `auth status` does not write the password or the bridge error text. A successful d1 boot returns an empty body.

## Verdict

PASS. This depends on Tests (PHP 8.4, sqlite) and (PHP 8.4, pgsql) going green on this head.
