# QA: apotheke #299, operator console control-plane schema and primary seed

- PR: https://github.com/thordin9/apotheke/pull/299 (Coder), base `main`. Operator console E0+E1: the control-plane schema and `primary`'s seed as migrations in `edge/migrations/control/`, plus the design note `docs/operator-console.md`.
- Gated head: `880dd685fce3b5704c9f11253db878ada6f9f5f4`, 0 behind main `91a0c4ae` (#301), mergeable. It is a plain merge (parents `7947fefa` and `91a0c4ae`) of main into `7947fefa`. `7947fefa` is the previous version of this doc committed on `8b966d93`; it is byte-identical to that issue (6014 B, sha256 `c3b4aa5b…`) and is replaced by this reissue.
- Content reviewed at `8b966d935988f91d456b4f7cf0122fbcdc0ff63c` (0 behind `5fe1f141`, 9 ahead, clean). Earlier gates: the full gate at `96bdf248` and deltas at `dfd04329` and `8b966d93`.
- Merge-only read, `7947fefa` to `880dd685`:
  - For each of the 9 files, the +/- lines of `main...880dd685` equal those of `5fe1f141...7947fefa`. That includes `docs/adr/002-edge-storage.md` and `docs/index.md`, which #301 also touches: both auto-merged with only #299's lines on top of #301's text.
  - For each of the 13 files #301 changed, the +/- lines of `7947fefa...880dd685` equal those of `5fe1f141...91a0c4ae`, so main's content is intact. #301 adds no control-plane text that conflicts with this PR.
  - `docs/operator-console.md`, the two migrations (pins unchanged), both test files and the committed QA doc are byte-identical to `7947fefa`.
  - The CHANGELOG differs from main only by the #299 `### Added` bullet (full URL) under [Unreleased]. From `## [3.37.2]` down it is byte-identical to main.
  - CI on `880dd685` at read time: Deploy edge 43296692164 and PR #299 43296683968 succeeded; CI 43296692126 was queued.
- Commits since `dfd04329`:
  - `359583c6` fix(edge): refuse installation REPLACE and NULL keys in control plane (68 chars).
  - Plain merges `3ae2ce7d` (main `99b76094`, #293) and `8b966d93` (main `5fe1f141`, v3.37.2). Their subjects are git-generated and exempt.
  - The PR title (70 chars) is unchanged and suits the squash.
- Merge-only read:
  - For each of the 8 files, the +/- lines of `main...8b966d93` equal those of `3be6990d...359583c6`.
  - The 7 non-CHANGELOG files are byte-identical at `359583c6` and `8b966d93` (sha256).
  - The CHANGELOG differs from main only by the #299 bullet (full URL) under [Unreleased] `### Added`.
- Gate (at `8b966d93`): a full read of `359583c6`; the edge suite; applies to empty SQLite (node:sqlite) and to a local D1 (wrangler 4.157.0, `--local` only); edge-case probes; and 30 mutants, each run with the pin and again with it recomputed.
- Reviewer: Apotheke QA 2

## Checklist

- [x] CI on `8b966d93` (content head), all succeeded: CI 43290996965, Deploy edge 43290995185 (Check and dry run; the deploy job was skipped, as intended on PRs), PR #299 / CodeQL 43290981155.
- [x] `node --test --experimental-strip-types scripts/deploy-scripts.test.mjs edge/tests/r2.test.mjs`: 119/119 pass.
- [x] Pins match the files: 0001 is `a066b939…`, and 0002 is unchanged (`7c0ecfeb…`). Nothing has applied 0001 in prod yet (#300 is open), so editing it in place is still fine now.
- [x] Local D1 apply and re-apply work. On local D1:
  - `REPLACE` of `primary` with a new name is refused ("installation already exists").
  - NULL route hosts and NULL audit ids are refused (NOT NULL).
  - `a--b` and `a..b.test` are refused (CHECK).
  - A new installation `tenant-1` inserts normally, and `primary` is unchanged.
- [x] SQLite probes, installations:
  - New installations insert normally, with a subscription and a route. `INSERT OR IGNORE` and `REPLACE` of a new installation work.
  - `REPLACE` with the same id or with a new id that reuses `primary`'s `do_name` is refused.
  - `do_name`: 63 chars accepted and 64 refused. `a-`, `-a`, `a--b`, upper case and non-ASCII are refused; `0` is accepted.
  - The rules use only `length()` and `GLOB`, both available on D1; there is no REGEXP.
- [x] SQLite probes, hosts: 253 chars accepted and 254 refused. `-a`, `a-`, `a..b`, upper case and non-ASCII are refused. `a--b`, single labels and dotted IPv4 are accepted.
- [x] SQLite probes, NULL keys: all five TEXT keys refuse NULL.
- [x] REPLACE on other tables: on `installation_routes`, `subscriptions` and `plans` it only re-points a host, changes a plan or status, or renames a plan. Plain `UPDATE` already allows all of that, and the FK check stays clean. No invariant is lost; only `installations` has fixed names.
- [x] Mutants:
  - **With the pin:** all 30 are killed. They drop `installations_no_replace` or each of its three clauses; drop each new NOT NULL; loosen each `do_name` rule (length, leading or trailing `-`, `--`, upper case) and each host rule (length, `..`, leading or trailing `-` or `.`, `.-`, `-.`); drop `names_fixed`, the audit no-replace trigger or the UNIQUEs; edit a default; rename the seed; add a 0003 `REPLACE`.
  - **With the pin recomputed:** 27 are killed by their own tests. Survivors:
    - Dropping the `r2_prefix` clause of `installations_no_replace` is an equivalent mutant: the CHECK ties the prefix to `do_name`.
    - Editing a default is caught only by the pin, by design.
    - Raising the host limit from 253 to 254 survives (N-2061).
- [x] Docs: `docs/operator-console.md:25-26` describe the new name and host rules and the REPLACE refusal.

## Findings

- **L-2009: closed.** `installations_no_replace` (0001:39-41) refuses `REPLACE` and `INSERT OR REPLACE` over an existing id, `do_name` or `r2_prefix`, on SQLite and local D1.
- **L-2010: closed.** NOT NULL is on `plans.id`, `installations.id`, `installation_routes.host`, `subscriptions.installation_id` and `operator_audit.id`, with tests.
- **N-2011: closed.** `do_name` is 1-63 characters in the form `^[a-z0-9]+(-[a-z0-9]+)*$`. The host is 1-253 characters, with no `..` and no label that starts or ends with `-`.
- **Earlier status:** L-2001, L-2002, L-2003, N-2004, N-2006 and N-2007 stay closed. N-2005 is mostly closed (`metric` and the dates are left to the console). N-2008 stays open for the console PR.
- **N-2061 (new):** a host label over 63 characters is accepted (`edge/migrations/control/0001_control_plane.sql:45-47`), and no test checks the 253/254 boundary, so 254 survives a mutation. Consider a 254-character refusal test. A per-label limit can be left to the console's host validation.
- **N-2062 (new):** idempotent insert forms now abort on an existing installation (0001:39-41). `INSERT OR IGNORE`, `ON CONFLICT DO NOTHING` and `ON CONFLICT DO UPDATE` all raise "installation already exists", because RAISE(ABORT) runs before conflict handling. This is intended for the names, but the console should create installations with a plain `INSERT` and change them with `UPDATE`. Worth one line in `docs/operator-console.md`.

0 High, 0 Medium, 0 Low, 3 Nit open (N-2008, N-2061, N-2062).

## Verdict

**PASS** at `880dd685` (content as at `8b966d93`; the merge-only read of `91a0c4ae` is clean), 0 behind main `91a0c4ae`, provided CI 43296692126 goes green. No High, Medium or Low open. After this squash-merges and #300 applies 0001 and 0002, any change is a new numbered file; the pin enforces that.

If main moves (for example #300), the PR needs a plain catch-up, a merge-only read and a reissued doc. Squash-merge with the PR title as the subject.
