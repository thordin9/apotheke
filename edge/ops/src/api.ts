// /api/ops/v1 for apotheke-ops (docs/operator-console.md#console-api). The caller is already an operator (Access,
// index.ts) and a write has already passed the CSRF check. Errors are {ok:false, code} with 401, 403, 404, 409, 410,
// 422 or 503 (405 for a known path with another method).
import { audited, type AuditDb } from "./audit";
import { readRoute, runRead } from "./reads";

export const API_PREFIX = "/api/ops/v1";
/** The largest request body an action reads. */
export const MAX_BODY_BYTES = 4096;
/** The installation the console never acts on (edge/src/installation.ts, INSTALLATION_NAME). */
export const PRIMARY = "primary";

export type OpsFailure = { ok: false; code: string; status?: number };
/** apotheke-api's Ops entrypoint (edge/src/index.ts, edge/src/ops-accounts.ts), over the service binding. */
export type OpsEntrypoint = {
	claimCreate(installation: string): Promise<{ ok: true; token: string; expires_at: string } | OpsFailure>;
	resetAdmins(installation: string): Promise<{ ok: true } | OpsFailure>;
};

export type ApiEnv = { DB?: AuditDb; OPS?: unknown; OPS_PROVISIONING?: string; LIMITS_ENABLED?: string };
export type Operator = { email: string; requestId: string };

export function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
	return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json; charset=utf-8", ...headers } });
}

export function failure(status: number, code: string, extra: Record<string, unknown> = {}): Response {
	return json(status, { ok: false, code, ...extra });
}

/** The Ops entrypoint's refusal codes as console answers. Codes not listed (the container did not start or answer,
 *  is not hosted, has no key, gave a bad answer, any other status) are 503 unavailable, with the code as detail. */
const EDGE_CODES: Record<string, [number, string]> = {
	active_admin_exists: [409, "active_admin_exists"],
	refused: [403, "primary"],
	unknown_installation: [404, "not_found"],
	ambiguous_installation: [409, "ambiguous_installation"],
	not_startable: [409, "not_startable"],
};

function edgeFailure(result: OpsFailure): Response {
	const mapped = EDGE_CODES[result.code];
	if (mapped) return failure(mapped[0], mapped[1]);
	return failure(503, "unavailable", { detail: typeof result.code === "string" ? result.code.slice(0, 64) : "unknown" });
}

/** The request's JSON object body, at most MAX_BODY_BYTES, or null. A declared Content-Length over the cap is not
 *  read at all; without one, reading stops as soon as more than MAX_BODY_BYTES have arrived. */
async function readBody(request: Request): Promise<Record<string, unknown> | null> {
	const declared = request.headers.get("Content-Length");
	if (declared !== null && !(Number(declared) <= MAX_BODY_BYTES)) return null;
	const bytes = new Uint8Array(MAX_BODY_BYTES);
	let length = 0;
	const reader = request.body?.getReader();
	while (reader) {
		const { done, value } = await reader.read();
		if (done) break;
		if (length + value.byteLength > MAX_BODY_BYTES) {
			await reader.cancel().catch(() => {});
			return null;
		}
		bytes.set(value, length);
		length += value.byteLength;
	}
	try {
		const v = JSON.parse(new TextDecoder().decode(bytes.subarray(0, length)));
		return v !== null && typeof v === "object" && !Array.isArray(v) ? (v as Record<string, unknown>) : null;
	} catch {
		return null;
	}
}

type Target = { id: string; host: string };

/** The installation id from the path, the guards both actions share, and the confirmed host. primary: 403 (before
 *  any lookup and again by its row). Unknown: 404. deleted: 410. Not provisioning or active: 409 not_startable. No
 *  host: 409 no_host. confirm_host not one of its hosts: 422 confirm_host. */
async function target(db: AuditDb, rawId: string, body: Record<string, unknown>): Promise<Target | Response> {
	let id: string;
	try {
		id = decodeURIComponent(rawId);
	} catch {
		return failure(404, "not_found");
	}
	if (id === "" || id.length > 128 || /[\u0000-\u001f\u007f]/.test(id)) return failure(404, "not_found");
	if (id === PRIMARY) return failure(403, "primary");
	const row = await db.prepare("SELECT id, do_name, status FROM installations WHERE id = ?").bind(id).first<{ id: string; do_name: string; status: string }>();
	if (!row) return failure(404, "not_found");
	if (row.id === PRIMARY || row.do_name === PRIMARY) return failure(403, "primary");
	if (row.status === "deleted") return failure(410, "gone");
	if (row.status !== "provisioning" && row.status !== "active") return failure(409, "not_startable");
	const { results } = await db.prepare("SELECT host FROM installation_routes WHERE installation_id = ? ORDER BY host").bind(id).all<{ host: string }>();
	if (results.length === 0) return failure(409, "no_host");
	const confirm = typeof body.confirm_host === "string" ? body.confirm_host.trim().toLowerCase() : "";
	const host = results.find((r) => r.host === confirm)?.host;
	if (!host) return failure(422, "confirm_host");
	return { id, host };
}

type Action = "claim" | "admin-reset";

async function installationAction(action: Action, request: Request, env: ApiEnv, op: Operator, rawId: string): Promise<Response> {
	const db = env.DB;
	const ops = env.OPS as OpsEntrypoint | undefined;
	if (!db || !ops) return failure(503, "not_configured");
	const body = await readBody(request);
	if (!body) return failure(422, "invalid_body");
	const t = await target(db, rawId, body);
	if (t instanceof Response) return t;

	let result: Awaited<ReturnType<OpsEntrypoint["claimCreate"]>> | Awaited<ReturnType<OpsEntrypoint["resetAdmins"]>>;
	try {
		result = action === "claim" ? await ops.claimCreate(t.id) : await ops.resetAdmins(t.id);
	} catch (e) {
		console.error("ops action failed", action, op.requestId, e instanceof Error ? e.name : "error");
		return failure(503, "unavailable");
	}
	if (!result || result.ok !== true) return edgeFailure((result ?? { ok: false, code: "bad_answer" }) as OpsFailure);

	// The claim token is only in this answer: the audit row has the expiry and the host, never the token or the link.
	// If the audit row cannot be written the link is withheld (503 audit_failed); a new claim voids this one.
	const claim = action === "claim" ? (result as { token?: unknown; expires_at?: unknown }) : null;
	if (claim && (typeof claim.token !== "string" || claim.token === "" || typeof claim.expires_at !== "string")) {
		return failure(503, "unavailable", { detail: "bad_answer" });
	}
	try {
		await audited(db, {
			operator: op.email,
			action: action === "claim" ? "installation.claim" : "installation.admin_reset",
			target: t.id,
			after: claim ? { host: t.host, expires_at: claim.expires_at } : { host: t.host },
			requestId: op.requestId,
		});
	} catch (e) {
		console.error("ops audit failed", action, op.requestId, e instanceof Error ? e.name : "error");
		return failure(503, "audit_failed");
	}
	if (!claim) return json(200, { ok: true });
	return json(200, { ok: true, link: `https://${t.host}/claim#${encodeURIComponent(claim.token as string)}`, expires_at: claim.expires_at });
}

const ACTION_PATH = /^\/installations\/([^/]+)\/(claim|admin-reset)$/;

/** A request under API_PREFIX. nowMs is the clock for the reads (subscriptions due, the usage period). */
export async function handleApi(request: Request, env: ApiEnv, op: Operator, nowMs: number = Date.now()): Promise<Response> {
	const url = new URL(request.url);
	const path = url.pathname.slice(API_PREFIX.length);
	const method = request.method.toUpperCase();
	if (path === "/config") {
		if (method !== "GET" && method !== "HEAD") return withAllow("GET, HEAD");
		return json(200, { ok: true, provisioning_enabled: env.OPS_PROVISIONING === "1", limits_enabled: env.LIMITS_ENABLED === "1" });
	}
	const read = readRoute(path);
	if (read) {
		if (method !== "GET" && method !== "HEAD") return withAllow("GET, HEAD");
		if (!env.DB) return failure(503, "not_configured");
		return runRead(read, env.DB, url.searchParams, nowMs);
	}
	const m = ACTION_PATH.exec(path);
	if (m) {
		if (method !== "POST") return withAllow("POST");
		return installationAction(m[2] as Action, request, env, op, m[1]);
	}
	return failure(404, "not_found");
}

function withAllow(allow: string): Response {
	return json(405, { ok: false, code: "method_not_allowed" }, { Allow: allow });
}
