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
Three tests were red on CI for exactly this in two hours, and each had passed locally first, which is
why the rule is a rule rather than an opinion. A fourth, `test/http/api-session.spec.ts`'s eleventh-attempt
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

The v3 PHPUnit suite is not on this branch: `tests/Unit`, `tests/Modules`, `phpunit.xml` and `tests/run.sh`
stay on `main` until cutover, and nothing in this gate ever depended on them.

## The gate is PHP-free, and stays that way (D36)

`npm run workflows:check` fails if any workflow that can gate `experimental-v4` gains
`actions/setup-php`, a `composer` command, `phpunit` or a `php` step. It is a step of `v4.yml`'s unit job
and it reads each workflow's own `on:` block rather than a hand-kept list. It runs its own self-test first,
so a parser change that stops reading a trigger fails there rather than passing quietly. Path-filtered
pull-request workflows — the v3 edge and frontend deploys — are not gates for this branch and are named in
the output on every run, so the exclusion is visible rather than silent.
