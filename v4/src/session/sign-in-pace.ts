// The failed sign-in deadline (plan §5 S1.4): `modules/Auth/SignInPace.php`, ported.
//
// v3 answers every *failed* sign-in at the same deadline after the attempt began, so the answer time
// says nothing about whether the account exists or how its stored hash was made. PHP buys that with
// `usleep()`; a Worker cannot block, so the deadline is an `await`ed timer. The wall time is the
// same and the isolate is free to serve other requests while it waits — but the request is held for
// up to `deadlineMs`, which is duration a caller can measure and a hosted installation pays for in
// wall time (not CPU time, which is what `limits.cpu_ms` bills).
//
// The three constants are v3's, and `test/api/session.spec.ts` compares them with the fixture the
// PHP class generated: a clamp that drifts would be a timing difference nobody would notice.

/** `SignInPace::DEFAULT_MS`: the deadline an unconfigured sign-in answers at. */
export const DEFAULT_MS = 1000;

/** `SignInPace::MIN_MS`: legacy's flat `usleep(400000)`, the shortest deadline accepted. */
export const MIN_MS = 400;

/** `SignInPace::MAX_MS`. */
export const MAX_MS = 10_000;

/** `new SignInPace($ms)->deadlineMs`: clamped to [MIN_MS, MAX_MS]. */
export const deadlineMs = (configured?: number): number => {
	const value = configured ?? DEFAULT_MS;
	if (!Number.isFinite(value)) {
		return DEFAULT_MS;
	}
	return Math.min(MAX_MS, Math.max(MIN_MS, Math.trunc(value)));
};

/** Monotonic milliseconds, as `hrtime(true)` is for v3: a clock jump must not shorten a deadline. */
const now = (): number => performance.now();

/** `SignInPace::start()`: the start of an attempt, for `padSignIn()`. */
export const startSignIn = (): number => now();

/**
 * `SignInPace::pad()`: waits until the deadline after `started` and returns the milliseconds waited
 * (0 when the attempt already took longer). `0` is allowed by the *caller* — tests pass it so a
 * failed sign-in costs nothing — but the production configuration never goes below `MIN_MS`.
 */
export const padSignIn = async (started: number, configured?: number): Promise<number> => {
	const deadline = configured ?? DEFAULT_MS;
	if (deadline <= 0) {
		return 0;
	}
	const left = started + deadlineMs(deadline) - now();
	if (left <= 0) {
		return 0;
	}
	await new Promise<void>((resolve) => setTimeout(resolve, left));
	return left;
};
