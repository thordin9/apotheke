# Tests

`npm test` runs the suite in real workerd (`vitest.config.ts`). Everything under `test/` is one of two
kinds, and the second kind declares its own deadline (D34).

## Cheap tests keep vitest's default 5 s

That default is not a budget, it is a hang detector: a test that stalls should fail fast rather than sit
in the runner. Most of the suite is cheap and should stay on the default.

## Expensive setup passes `SLOW_TEST_MS` and says why

`test/helpers/deadline.ts` exports `SLOW_TEST_MS` (60 s). A test belongs in that class when its *setup*,
not its assertions, is seconds of machine-dependent work: booting an installation (the 36-migration chain
plus the seed), driving repeated bcrypt verifies at PHP's default cost, or sweeping the route table. Both
in-class tests that were red on CI passed locally first, which is why the rule is a rule.

A test in the class passes it as the third argument and puts the measured number in a comment:

```ts
it("…", async () => {
	// Two fresh installations: 5069 ms on CI's runner, 1709 ms here.
}, SLOW_TEST_MS);
```

Specs that carry it today: `test/readiness.spec.ts` (double `prepareInstallation`), `test/seed-parity.spec.ts`
(install on the emulated D1 binding), `test/html/account.spec.ts` and `test/session/signin.spec.ts` (eleven
bcrypt verifies plus two sign-ins), `test/http/probes.spec.ts` (a request per route in the table). Add
yours here with the rest when you join the class.

The line is a measurement, not a rule of thumb: the numbers above are from the runner itself, and the
other specs that boot an installation were measured in the same run and stay on the default because they
are nowhere near it — `test/seed.spec.ts` 1221 ms for its heaviest test, `test/schema-parity.spec.ts`
2123 ms for six, `test/migrations.spec.ts` 940 ms. They are named so a later reader can see they were
considered rather than missed, and so the next one to creep past ~2.5 s on the runner is an obvious edit
rather than a red run.
