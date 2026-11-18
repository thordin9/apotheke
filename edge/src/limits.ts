// Plan limits in the Worker (operator console E5). Off unless the Worker var LIMITS_ENABLED is "1", and never applied
// to primary. Values come from plan_limits through the installation's subscription, loaded and cached with its route
// (route.ts). Soft means a warning header only; hard refuses the request. Counters live in memory in the owning
// Durable Object and are written to its storage at most 60 s after a change (one alarm).

/** The internal header the Worker sets for the container object, which removes it before anything reaches PHP. */
export const LIMITS_HEADER = "X-Apotheke-Limits";
/** Response header listing the limits at or past their soft value, comma-separated (for a banner in the SPA). */
export const WARNING_HEADER = "Apotheke-Limit-Warning";
/** How long a counter change may stay in memory before it is written to the object's storage. */
export const FLUSH_MS = 60_000;

export type Limit = { soft: number; hard: number };
/** Only limits with a hard value; an absent key means no limit. */
export type Limits = Record<string, Limit>;

export function limitsEnabled(env: { LIMITS_ENABLED?: string }): boolean {
	return env.LIMITS_ENABLED === "1";
}

/** plan_limits rows as the route query returns them ({"key": [soft, hard]}, JSON). Soft defaults to 80% of hard. */
export function parseLimits(raw: unknown): Limits {
	const out: Limits = {};
	let rows: unknown;
	try {
		rows = typeof raw === "string" ? JSON.parse(raw) : null;
	} catch {
		return out;
	}
	if (typeof rows !== "object" || rows === null) return out;
	for (const [key, pair] of Object.entries(rows)) {
		const [soft, hard] = Array.isArray(pair) ? pair : [];
		if (typeof hard !== "number" || !Number.isFinite(hard) || hard < 0) continue;
		out[key] = { soft: typeof soft === "number" && Number.isFinite(soft) ? soft : hard * 0.8, hard };
	}
	return out;
}

function text(status: number, body: string): Response {
	return new Response(body + "\n", { status, headers: { "Cache-Control": "no-store", "Content-Type": "text/plain; charset=utf-8" } });
}

export function tooLarge(bytes: number): Response {
	return text(413, `File is larger than your plan allows (${Math.floor(bytes / 104_857.6) / 10} MB)`);
}

/**
 * The Worker side, for an installation other than primary: refuses a body over import_file_bytes before reading it
 * (Content-Length, or a streaming cap when there is none) and forwards with the limits in LIMITS_HEADER, overwriting
 * any client value.
 */
export async function forwardWithLimits(request: Request, limits: Limits, forward: (r: Request) => Promise<Response>): Promise<Response> {
	const max = limits.import_file_bytes?.hard;
	const headers = new Headers(request.headers);
	headers.set(LIMITS_HEADER, JSON.stringify(limits));
	const init: RequestInit & { duplex?: "half" } = { headers };
	let over = false;
	if (max !== undefined && request.body !== null) {
		const length = request.headers.get("content-length");
		if (length !== null && Number(length) > max) return tooLarge(max);
		if (length === null) {
			let seen = 0;
			init.body = request.body.pipeThrough(new TransformStream<Uint8Array, Uint8Array>({
				transform(chunk, controller) {
					seen += chunk.byteLength;
					if (seen > max) {
						over = true;
						controller.error(new Error("request body over the plan limit"));
					} else controller.enqueue(chunk);
				},
			}));
			init.duplex = "half";
		}
	}
	try {
		const res = await forward(new Request(request, init));
		if (!over) return res;
		await res.body?.cancel();
	} catch (e) {
		if (!over) throw e;
	}
	return tooLarge(max!);
}

/** The container object's view: the limits from LIMITS_HEADER (null for primary, when off, or without the header),
 *  and the request without that header. */
export function takeLimits(request: Request, env: { LIMITS_ENABLED?: string }, doName: string | undefined, primary: string): { request: Request; limits: Limits | null } {
	if (!limitsEnabled(env) || !request.headers.has(LIMITS_HEADER)) return { request, limits: null };
	const raw = request.headers.get(LIMITS_HEADER);
	const headers = new Headers(request.headers);
	headers.delete(LIMITS_HEADER);
	let limits: Limits | null = null;
	if (doName !== undefined && doName !== primary) {
		try {
			const parsed: unknown = JSON.parse(raw ?? "");
			limits = typeof parsed === "object" && parsed !== null ? (parsed as Limits) : {};
		} catch {
			limits = {};
		}
	}
	return { request: new Request(request, { headers }), limits };
}

/** A value kept in memory and written to the object's storage under one key, at most FLUSH_MS after a change. */
export class Counter<T extends object> {
	private value?: T;
	private dirty = false;
	private readonly storage: DurableObjectStorage;
	private readonly key: string;
	private readonly init: () => T;
	constructor(storage: DurableObjectStorage, key: string, init: () => T) {
		this.storage = storage;
		this.key = key;
		this.init = init;
	}

	async get(): Promise<T> {
		return (this.value ??= (await this.storage.get<T>(this.key)) ?? this.init());
	}

	async changed(now = Date.now()): Promise<void> {
		if (this.dirty) return;
		this.dirty = true;
		if ((await this.storage.getAlarm()) === null) await this.storage.setAlarm(now + FLUSH_MS);
	}

	/** Called from the object's alarm. */
	async flush(): Promise<void> {
		if (!this.dirty || !this.value) return;
		this.dirty = false;
		await this.storage.put(this.key, this.value);
	}
}

export type ContainerUsage = { period: string; requests: number; containerMs: number; lastActivity: number; countedTo: number };

/** "YYYY-MM" in UTC, as usage_counters.period. */
export function period(now: number): string {
	return new Date(now).toISOString().slice(0, 7);
}

/**
 * Requests and container active time for one installation's container object, per calendar month (UTC). Active time
 * is metered from start to stop as the inactivity timeout defines them: a container started by a request runs until
 * sleepAfter ms after the last one.
 */
export class UsageMeter {
	readonly counter: Counter<ContainerUsage>;
	private readonly sleepAfter: () => number;
	constructor(storage: DurableObjectStorage, sleepAfter: () => number) {
		this.sleepAfter = sleepAfter;
		this.counter = new Counter(storage, "usage", () => ({ period: "", requests: 0, containerMs: 0, lastActivity: 0, countedTo: 0 }));
	}

	/** Brings the counters up to now: a new month starts from zero, and running time is added up to the estimated stop. */
	async current(now = Date.now()): Promise<ContainerUsage> {
		const u = await this.counter.get();
		if (u.period !== period(now)) Object.assign(u, { period: period(now), requests: 0, containerMs: 0 });
		const end = Math.min(now, u.lastActivity + this.sleepAfter());
		if (u.lastActivity > 0 && end > u.countedTo) {
			u.containerMs += end - u.countedTo;
			u.countedTo = end;
		}
		return u;
	}

	private activity(u: ContainerUsage, now: number): void {
		// Asleep since lastActivity + sleepAfter: this request starts a new run.
		if (now > u.lastActivity + this.sleepAfter()) u.countedTo = now;
		u.lastActivity = now;
	}

	/** Before forwarding: the refusal for a used-up month, else counts the request. */
	async admit(limits: Limits, now = Date.now()): Promise<Response | null> {
		const u = await this.current(now);
		const hours = limits.container_hours_month;
		if (hours && u.containerMs >= hours.hard * 3_600_000) return text(402, "This month's active time is used up");
		const requests = limits.requests_month;
		if (requests && u.requests >= requests.hard) return text(429, "This Apotheke reached its monthly request limit. The owner can upgrade the plan.");
		u.requests += 1;
		this.activity(u, now);
		await this.counter.changed(now);
		return null;
	}

	/** After forwarding: marks the activity and sets WARNING_HEADER when a soft value is reached. */
	async done(limits: Limits, headers: Headers, now = Date.now()): Promise<void> {
		const u = await this.current(now);
		this.activity(u, now);
		await this.counter.changed(now);
		const warn: string[] = [];
		if (limits.requests_month && u.requests >= limits.requests_month.soft) warn.push("requests_month");
		if (limits.container_hours_month && u.containerMs >= limits.container_hours_month.soft * 3_600_000) warn.push("container_hours_month");
		if (warn.length > 0) headers.set(WARNING_HEADER, warn.join(", "));
	}

	/** This month's totals, in the metric names a later Cron copies into D1 usage_counters. */
	async usage(now = Date.now()): Promise<{ period: string; requests: number; container_seconds: number }> {
		const u = await this.current(now);
		return { period: u.period, requests: u.requests, container_seconds: Math.floor(u.containerMs / 1000) };
	}
}
