// Host routing (operator console E2): the control-plane D1 (DB) maps a request host to an installation through
// installation_routes (edge/migrations/control/). No row, no DB binding, or a failed lookup falls back to the one
// installation every request went to before, so primary keeps working through a D1 outage.
import { INSTALLATION_NAME } from "./installation";

export type Route = { doName: string; status: string };
type RouteDb = { prepare(sql: string): { bind(...v: unknown[]): { first<T>(): Promise<T | null> } } };

const FALLBACK: Route = { doName: INSTALLATION_NAME, status: "active" };
const TTL_MS = 30_000;
/** At most this many hosts are cached per isolate: expired entries go first, then the oldest. */
export const ROUTE_CACHE_MAX = 1000;
const cache = new Map<string, { route: Route | null; until: number }>();

/** Lower case, no port, no trailing dots. */
export function normalizeHost(host: string): string {
	return host.toLowerCase().replace(/:\d+$/, "").replace(/\.+$/, "");
}

function remember(key: string, route: Route | null, now: number): void {
	cache.delete(key);
	if (cache.size >= ROUTE_CACHE_MAX) {
		for (const [k, v] of cache) if (v.until <= now) cache.delete(k);
		// Map keeps insertion order, so the first key is the oldest.
		for (const k of cache.keys()) {
			if (cache.size < ROUTE_CACHE_MAX) break;
			cache.delete(k);
		}
	}
	cache.set(key, { route, until: now + TTL_MS });
}

/** The route for a host: a row from installation_routes (a hit or a miss is cached in the isolate for 30 s), else primary. */
export async function routeFor(host: string, db: RouteDb | undefined, now = Date.now()): Promise<Route> {
	if (!db) return FALLBACK;
	const key = normalizeHost(host);
	const hit = cache.get(key);
	if (hit && hit.until > now) return hit.route ?? FALLBACK;
	try {
		const row = await db
			.prepare("SELECT i.do_name AS doName, i.status AS status FROM installation_routes r JOIN installations i ON i.id = r.installation_id WHERE r.host = ?")
			.bind(key)
			.first<Route>();
		remember(key, row, now);
		return row ?? FALLBACK;
	} catch (e) {
		console.error("route lookup failed, using the fallback", String(e));
		return FALLBACK;
	}
}

/** Tests only. */
export function clearRouteCache(): void {
	cache.clear();
}

/** Tests only: the cached hosts, oldest first. */
export function cachedHosts(): string[] {
	return [...cache.keys()];
}

const STATUS: Record<string, [number, string]> = {
	provisioning: [503, "This Apotheke is being set up. Try again in a moment."],
	suspended: [402, "This Apotheke is paused. Its owner can contact support to resume it."],
	deleting: [410, "This Apotheke has been removed."],
	deleted: [410, "This Apotheke has been removed."],
};

/** The answer for a route that must not reach a container, or null to forward. Never wakes a container. */
export function routeResponse(route: Route): Response | null {
	// primary is never status-gated: it is served as before whatever its row says. Pausing it is a separate operator flow.
	if (route.doName === INSTALLATION_NAME) return null;
	if (route.status === "active") {
		// Until per-installation storage (E3) lands, only primary's storage exists: another name must not start.
		console.error("route to an installation without its own storage yet", route.doName);
		return text(503, "Apotheke is not available right now.", { "Retry-After": "30" });
	}
	const [status, body] = STATUS[route.status] ?? [503, "Apotheke is not available right now."];
	return text(status, body, status === 503 ? { "Retry-After": "30" } : {});
}

function text(status: number, body: string, headers: Record<string, string>): Response {
	return new Response(body + "\n", { status, headers: { "Cache-Control": "no-store", "Content-Type": "text/plain; charset=utf-8", ...headers } });
}
