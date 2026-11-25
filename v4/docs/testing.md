# Tests

`npm test` runs the suite in real workerd (`vitest.config.ts`). Everything under `test/` is one of two
kinds, and the second kind declares its own deadline (D34).

## Cheap tests keep vitest's default 5 s

That default is not a budget, it is a hang detector: a test that stalls should fail fast rather than sit
in the runner. Most of the suite is cheap and should stay on the default.

## Expensive setup passes `SLOW_TEST_MS` and says why

`test/helpers/deadline.ts` exports `SLOW_TEST_MS` (60 s). A test belongs in that class when its *setup*,
not its assertions, is seconds of machine-dependent work: booting an installation (the 36-migration chain
plus the seed), driving repeated bcrypt verifies at PHP's default cost, or sweeping the route table.
Three tests were red on CI for exactly this in two hours — four red job runs, since the account replay
failed in two jobs of the same run — and each had passed locally first, which is why the rule is a rule
rather than an opinion. A fourth, `test/http/api-session.spec.ts`'s eleventh-attempt
replay, timed out here at 5078 ms on the first run that carried this ledger — same class, found off CI.

A test in the class passes it as the third argument and puts the measured number in a comment:

```ts
it("…", async () => {
	// Two fresh installations: 5069 ms on CI's runner, 1709 ms here.
}, SLOW_TEST_MS);
```

Specs that carry it today: `test/readiness.spec.ts` (double `prepareInstallation`), `test/seed-parity.spec.ts`
(install on the emulated D1 binding), `test/html/account.spec.ts`, `test/session/signin.spec.ts` and
`test/http/api-session.spec.ts` (eleven bcrypt verifies plus two sign-ins), `test/http/probes.spec.ts`
(a request per route in the table). Add yours here with the rest when you join the class.

The line is a measurement, not a rule of thumb: the numbers above are from the runner itself, and the
other specs that boot an installation were measured in the same run and stay on the default because they
are nowhere near it — `test/seed.spec.ts` 1221 ms for its heaviest test, `test/schema-parity.spec.ts`
2123 ms for six, `test/migrations.spec.ts` 940 ms. They are named so a later reader can see they were
considered rather than missed, and so the next one to creep past ~2.5 s on the runner is an obvious edit
rather than a red run.

The three S1.7 ports joined that group, not the class, on measurement rather than on shape: each booted
an installation, so each was given `SLOW_TEST_MS` first, and the run that landed them showed the
assumption was wrong — `test/settings.spec.ts` 678 ms, `test/auth/users.spec.ts` 767 ms and
`test/auth/roles.spec.ts` 756 ms for each one's first case (run 45604858171's unit job). 0.7 s against
a 5 s default is not the class, and a 60 s deadline there would be slack; the declaration came off in
the next commit. They were slow here (3.2 s, 1.6 s, 1.2 s), which is why the rule is the runner's
number and not this box's.

## Where the seven oracles come from (D36)

The fixtures under `test/fixtures/` are v3's own answers, captured over HTTP. `npm run fixtures:check` is
the gate: it recomputes the SHA-256 of every source each fixture recorded and never boots v3, so it needs
no PHP and it runs in CI.

Regenerating them is the one step that does need PHP:

```bash
cd v4 && npm run fixtures:capture
```

It needs **PHP 8.4 CLI with `intl` and `sqlite3`** (v3's own driver), **`curl`** and **`jq`**, and it runs
**by hand, never in CI**: each generator starts v3 on a temp data dir and captures what it answers, which is
reproducible only against the PHP major the captures were taken with, and a runner would need a PHP
toolchain for a step whose output is committed. The script refuses to run when `CI` or `GITHUB_ACTIONS` is
set, and it ends by running `fixtures:check` — a capture that cannot reproduce its own source hashes is not
a capture. Point `PHP` at the binary when `php` is not on PATH.

The v3 PHPUnit suite left this branch at `5d65939e` (D36b, 231 `.php` files, 1,435 test methods); it stays
on `main` until cutover, and nothing in this gate ever depended on it. What it asserted is not lost:
`test/php-ledger.json` gives every retired path one destination, and names the 14 `.php` that survive
under `tests/` plus the 6 capture-side ones under `v4/` (D37, below).

## The gate is PHP-free, and stays that way (D36)

`npm run workflows:check` fails if any workflow that can gate `experimental-v4` gains
`actions/setup-php`, a `composer` command, `phpunit` or a `php` step. It is a step of `v4.yml`'s unit job
and it reads each workflow's own `on:` block rather than a hand-kept list. It runs its own self-test first,
so a parser change that stops reading a trigger fails there rather than passing quietly. Path-filtered
pull-request workflows — the v3 edge and frontend deploys — are not gates for this branch and are named in
the output on every run, so the exclusion is visible rather than silent.

## Every PHP path in this line is accounted for (D37, revision 43)

`5d65939e` removed 231 `.php` files carrying 1,435 `public function test*` methods and 379
`#[DataProvider]` attributes. Fourteen `.php` still live under `tests/` (13 v3 tools, plus
`tests/visual/ci4-fixtures.php`), and six under `v4/` are the only PHP the line keeps on purpose.
`test/php-ledger.json` names all of them, so "no PHP in the v4 branch" is checkable rather than a
claim.

| rows | means | the check enforces |
| --- | --- | --- |
| `retired`, `ported` | a TypeScript spec already asserts the same behaviour | the named file exists and carries `it()`/`test()` |
| `retired`, `scheduled` | the slice that builds the behaviour will port it | the target is a slice or gate `docs/v4-migration.md` defines |
| `retired`, `dropped` | v4 makes it meaningless (PHP harness, CI4, Postgres-only rows) | a written reason, and no target |
| `surviving`, `scheduled` | the PHP file is here until the harness slice replaces it | the file exists, and it is not one `5d65939e` deleted |
| `surviving`, `dropped` | it serves v3 runs that stay on `main` | the same, plus a reason |

The `exceptions` list is the other half: the six capture-side generators under `v4/` (`gen-*-fixture.php`,
`api-fixture-state.php`, `php-schema-dump.php`), each with the reason it is allowed to be PHP — they run
by hand, never in CI, and `fixtures:capture` refuses under `CI`.

`npm run ledger:check` is a step of `v4.yml`'s unit job. It runs a self-test first — sixteen synthetic
ledgers, each with exactly one rule broken — so a validator that stops catching a missing row, a duplicate,
a dangling `ported` target, an unknown kind, an undefined slice, or an unaccounted `.php` fails there
instead of passing quietly.
It also refuses a ledger whose retired totals do not add up to the recorded 1,435 / 379, which catches an
edit that drops a row without anyone noticing.

When git history is present it reconciles the retired rows against `git show --name-status 5d65939e` in
both directions — no row for a path that commit did not delete, no deleted path without a row — and
requires every surviving or exception file to exist. The unit job checks out at full depth
(`.github/workflows/v4.yml:56`, that job only) so the reconciliation always runs in CI; on a shallow clone
without the commit the recorded totals are the fallback and the check says which mode it ran in.

It also looks forward, not just back: every `.php` the branch commits under `tests/` or `v4/` must be
accounted for — a row if it is under `tests/`, a named exception if it is under `v4/`. The rule is
Stathis's "no php in the v4 branch", and a check that only reconciled one old commit would let the
next PHP file in unwatched. Two of the self-test cases break exactly that.

A row moves from `scheduled` to `ported` in the same commit as the spec that ports it, and a later slice
(and any PR touching a retired module) owes its own row before it lands.
