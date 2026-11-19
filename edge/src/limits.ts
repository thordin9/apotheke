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

/**
 * The plan_limits of installation i's subscription as one JSON value ({"key": [soft, hard]}; NULL without a
 * subscription or limit rows), for a query that has the installations row as i. Only rows with a hard value.
 * parseLimits() reads it.
 */
export const PLAN_LIMITS_JSON_SQL = "(SELECT json_group_object(l.limit_key, json_array(l.soft_value, l.hard_value))"
	+ " FROM subscriptions s JOIN plan_limits l ON l.plan_id = s.plan_id WHERE s.installation_id = i.id AND l.hard_value IS NOT NULL)";

/** plan_limits rows as PLAN_LIMITS_JSON_SQL returns them ({"key": [soft, hard]}, JSON). Soft defaults to 80% of hard. */
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

/** A body that does not have the length its valid Content-Length gives. */
export function lengthMismatch(): Response {
	return text(400, "Request body does not match Content-Length");
}

/** The app's API paths: /api and /api/..., no other spelling (ApiResponse::isApi()). */
export function isApiPath(path: string): boolean {
	return path === "/api" || path.startsWith("/api/");
}

/** The path as the app reads it (LegacyPathRouter::legacyPath()): the URL's path with each %XX decoded once, as
 *  rawurldecode() does, so /%61pi and /api%2Fv1 are under /api too. Bytes, not UTF-8, so a bad escape never throws. */
export function appPath(request: Request): string {
	return new URL(request.url).pathname.replace(/%([0-9A-Fa-f]{2})/g, (_, hex: string) => String.fromCharCode(parseInt(hex, 16)));
}

/**
 * A real CORS preflight the app answers for the SPA: OPTIONS under /api (appPath()) with Access-Control-Request-Method
 * and an Origin in CORS_ALLOWED_ORIGINS (corsAllowed()). Counted like any request but never refused, since a browser
 * needs a 2xx preflight to read the refusal of the request that follows. Any other OPTIONS is an ordinary request.
 */
export function isPreflight(request: Request, configured: string | undefined): boolean {
	return request.method.toUpperCase() === "OPTIONS" && request.headers.has("Access-Control-Request-Method")
		&& isApiPath(appPath(request)) && corsAllowed(request, configured) !== null;
}

/** PHP's trim(): only space, tab, newline, carriage return, NUL and vertical tab, not every Unicode space. A loop, not
 *  a regex, so a long run of these characters stays linear. */
function phpTrim(s: string): string {
	const space = " \t\n\r\0\x0B";
	let start = 0;
	let end = s.length;
	while (start < end && space.includes(s[start])) start++;
	while (end > start && space.includes(s[end - 1])) end--;
	return s.slice(start, end);
}

/** The exposed response headers, as the app sends them on /api (modules/Core/Http/Cors.php). */
export const CORS_EXPOSE = "ETag, Location, Retry-After, Link, " + WARNING_HEADER;

/** scheme://host[:port] in lower case, or null (Cors::origin()). */
function corsOrigin(raw: string): string | null {
	let origin = phpTrim(raw).toLowerCase();
	while (origin.endsWith("/")) origin = origin.slice(0, -1);
	return /^https?:\/\/(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(:[0-9]{1,5})?$/.test(origin) ? origin : null;
}

/** One trailing dot of the host is the same host (SameOrigin::normalise()). */
function corsNormalise(origin: string): string {
	return origin.replace(/^([a-z][a-z0-9+.-]*:\/\/[^/:[\]]+)\.(:\d+)?$/, "$1$2");
}

/** The usable https origins of CORS_ALLOWED_ORIGINS, normalised (Cors::__construct(), production rules). */
function corsOrigins(configured: string | undefined): string[] {
	return (configured ?? "").split(",").map(corsOrigin).filter((o): o is string => o !== null && o.startsWith("https://")).map(corsNormalise);
}

/** The request's Origin as the app echoes it when it is in CORS_ALLOWED_ORIGINS (Cors::allows()), else null. */
function corsAllowed(request: Request, configured: string | undefined): string | null {
	const sent = corsOrigin(request.headers.get("Origin") ?? "");
	return sent !== null && corsOrigins(configured).includes(corsNormalise(sent)) ? sent : null;
}

/**
 * Adds to a refusal the edge answers itself the CORS headers the app would send (ApiFilter::after() with
 * modules/Core/Http/Cors.php, production rules): only on /api, only for an exact https origin in CORS_ALLOWED_ORIGINS,
 * and Vary: Origin whenever that list has an origin. Nothing changes when the list is empty.
 */
export function withCors(res: Response, request: Request, configured: string | undefined): Response {
	if (!isApiPath(appPath(request)) || corsOrigins(configured).length === 0) return res;
	res.headers.set("Vary", "Origin");
	const sent = corsAllowed(request, configured);
	if (sent === null) return res;
	res.headers.set("Access-Control-Allow-Origin", sent);
	res.headers.set("Access-Control-Allow-Credentials", "true");
	res.headers.set("Access-Control-Expose-Headers", CORS_EXPOSE);
	if (request.method.toUpperCase() === "OPTIONS") {
		res.headers.set("Access-Control-Allow-Methods", "GET, POST, PATCH, PUT, DELETE");
		res.headers.set("Access-Control-Allow-Headers", "Content-Type, X-CSRF-Token, If-Match, Idempotency-Key");
		res.headers.set("Access-Control-Max-Age", "600");
	}
	return res;
}

/**
 * The Worker side, for an installation other than primary: refuses a body over import_file_bytes before reading it
 * when a valid Content-Length says so, and always counts the bytes as they stream, so a missing, invalid or wrong
 * Content-Length is capped the same. Forwards with the limits in LIMITS_HEADER, overwriting any client value.
 * corsOrigins is CORS_ALLOWED_ORIGINS, for the 413.
 */
export async function forwardWithLimits(request: Request, limits: Limits, forward: (r: Request) => Promise<Response>, corsOrigins?: string): Promise<Response> {
	const max = limits.import_file_bytes?.hard;
	const headers = new Headers(request.headers);
	headers.set(LIMITS_HEADER, JSON.stringify(limits));
	const init: RequestInit & { duplex?: "half" } = { headers };
	let over = false;
	let mismatch = false;
	if (max !== undefined && request.body !== null) {
		const length = (request.headers.get("content-length") ?? "").trim();
		const declared = /^[0-9]+$/.test(length) ? Number(length) : null;
		if (declared !== null && declared > max) return withCors(tooLarge(max), request, corsOrigins);
		let seen = 0;
		let body = request.body.pipeThrough(new TransformStream<Uint8Array, Uint8Array>({
			transform(chunk, controller) {
				seen += chunk.byteLength;
				// A valid length is at most max, so a longer body fails here first: a 400, not a 413.
				if (declared !== null && seen > declared) {
					mismatch = true;
					controller.error(new Error("request body longer than its Content-Length"));
				} else if (seen > max) {
					over = true;
					controller.error(new Error("request body over the plan limit"));
				} else controller.enqueue(chunk);
			},
			flush() {
				if (declared !== null && seen !== declared) {
					mismatch = true;
					throw new Error("request body shorter than its Content-Length");
				}
			},
		}));
		// The runtime's FixedLengthStream keeps a valid Content-Length on the forwarded request. A body of another length
		// fails above first and is answered 400. Otherwise, as for a body without one, it is forwarded without a length.
		headers.delete("content-length");
		if (declared !== null && typeof FixedLengthStream === "function") body = body.pipeThrough(new FixedLengthStream(declared));
		init.body = body;
		init.duplex = "half";
	}
	try {
		const res = await forward(new Request(request, init));
		if (!over && !mismatch) return res;
		await res.body?.cancel();
	} catch (e) {
		if (!over && !mismatch) throw e;
	}
	return withCors(mismatch ? lengthMismatch() : tooLarge(max!), request, corsOrigins);
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
	private loading?: Promise<T>;
	private dirty = false;
	private readonly storage: DurableObjectStorage;
	private readonly key: string;
	private readonly init: () => T;
	constructor(storage: DurableObjectStorage, key: string, init: () => T) {
		this.storage = storage;
		this.key = key;
		this.init = init;
	}

	/** One load from storage: callers before it finishes share the same value, so no change is lost. */
	async get(): Promise<T> {
		if (this.value) return this.value;
		this.loading ??= this.storage.get<T>(this.key).then((v) => (this.value ??= v ?? this.init()));
		return this.loading;
	}

	async changed(now = Date.now()): Promise<void> {
		if (this.dirty) return;
		this.dirty = true;
		if ((await this.storage.getAlarm()) === null) await this.storage.setAlarm(now + FLUSH_MS);
	}

	/** Called from the object's alarm. force writes a value changed without changed() (a recount in the alarm). */
	async flush(force = false): Promise<void> {
		if ((!this.dirty && !force) || !this.value) return;
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

	/**
	 * Before forwarding: the refusal for a used-up month, else counts the request. A real preflight (isPreflight()) is
	 * never refused: in a used-up month it gets the app's empty 204 without waking the container and is not counted,
	 * like a refusal; otherwise it is counted and forwarded like any other request.
	 */
	async admit(limits: Limits, now = Date.now(), preflight = false): Promise<Response | null> {
		const u = await this.current(now);
		const hours = limits.container_hours_month;
		const requests = limits.requests_month;
		const refusal = hours && u.containerMs >= hours.hard * 3_600_000 ? text(402, "This month's active time is used up")
			: requests && u.requests >= requests.hard ? text(429, "This Apotheke reached its monthly request limit. The owner can upgrade the plan.")
			: null;
		if (refusal) return preflight ? new Response(null, { status: 204 }) : refusal;
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
