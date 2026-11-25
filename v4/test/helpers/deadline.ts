// The deadline a spec declares when its own setup is the expensive part (D34).
//
// Vitest's default is 5 s, which is right for a cheap test: a hang still fails fast. It is wrong as a
// budget for a test whose *setup* boots an installation (the 36-migration chain plus the seed), drives
// repeated bcrypt verifies at PHP's default cost, or both — those are seconds of work that depend on the
// machine, not on the code. Three tests were red on CI for exactly this within two hours, each having
// passed locally: `html/account.spec.ts`'s eleven password checks (3601 ms on the runner), `readiness.spec.ts`'s
// double `prepareInstallation()` (5069 ms against a 5000 ms default), and `session/signin.spec.ts`'s
// throttle replay, which timed out in the sqlite leg (run 45579682071 attempt 1) where the same test
// took 3284 ms in a unit leg.
//
// So: a spec in that class passes `SLOW_TEST_MS` as the test's third argument and says the measurement in
// a comment beside it. The number is a deadline, not slack — 60 s is two orders of magnitude above the
// work, so it does not hide a slow regression, only a slower machine. Cheap tests keep the default.
//
// Measured on the ubuntu-26.04 runner in run 45578984620 attempt 1 (2026-11-25), for the record:
// readiness 5069 ms, seed-parity's D1 seed 4683 ms, account's throttle 3601 ms, signin's throttle 3284 ms.
// `v4/docs/testing.md` names the specs that carry it; add yours there with the rest.
export const SLOW_TEST_MS = 60_000;
