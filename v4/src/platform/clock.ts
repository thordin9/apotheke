// The clock adapter (plan §5 S0.3): one injectable source of "now", so a test pins time and the
// app never calls `new Date()` in a place that decides a stored value.
//
// The stored form is PHP's `gmdate('Y-m-d H:i:s')` in UTC (§4 rule 9). `APOTHEKE_CLOCK` is the
// self-host and test seam the PHP app already has; it takes an ISO-8601 instant.

/** `2026-11-23 12:00:00` — the app's stored UTC form (§4 rule 9). */
export const formatUtc = (instant: Date): string => instant.toISOString().slice(0, 19).replace("T", " ");

export interface Clock {
	now(): Date;
	/** `formatUtc(now())`, the value a row stores. */
	stamp(): string;
}

export const systemClock: Clock = {
	now: () => new Date(),
	stamp() {
		return formatUtc(this.now());
	},
};

/** A clock frozen at one instant: the same clock the PHP fixtures are generated with. */
export const fixedClock = (instant: Date): Clock => ({
	now: () => new Date(instant.getTime()),
	stamp: () => formatUtc(instant),
});

/**
 * The clock for an environment: `APOTHEKE_CLOCK` pins it (an ISO instant), otherwise the wall
 * clock. An unparseable value is ignored rather than silently freezing time at 1970.
 */
export const clockFrom = (appClock: string | undefined): Clock => {
	if (appClock === undefined || appClock.trim() === "") {
		return systemClock;
	}
	const at = new Date(appClock);
	return Number.isNaN(at.getTime()) ? systemClock : fixedClock(at);
};
