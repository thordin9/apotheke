// Operator console PR0b: the hosting side's first-admin claim and admin reset for one installation (POST
// http://ops.internal/internal/accounts/claim and /internal/accounts/reset-admins,
// docs/operator-console.md#claim-and-admin-reset-from-the-operator-console).
// Signed exactly like the backup request (ops-signature.ts): the request's own path, an empty JSON object as body,
// and the installation's derived APP_KEY. The claim token is only ever in the returned result: never logged, never in
// an error message.
import { INSTALLATION_NAME, validDoName } from "./installation";
import { OPS_HOST, OPS_SIGNATURE_HEADER, opsSignature } from "./ops-signature";

export const OPS_CLAIM_PATH = "/internal/accounts/claim";
export const OPS_RESET_ADMINS_PATH = "/internal/accounts/reset-admins";

export type OpsAccountsAction = "claim" | "reset-admins";

const PATHS: Record<OpsAccountsAction, string> = { claim: OPS_CLAIM_PATH, "reset-admins": OPS_RESET_ADMINS_PATH };

/** The body of both requests. The app signs and checks it but reads nothing from it. */
export const OPS_ACCOUNTS_BODY = "{}";

/** A refusal or failure. code is stable; status is the container's HTTP status when it answered. */
export type OpsAccountsFailure = { ok: false; code: string; status?: number };
export type OpsClaimResult = { ok: true; token: string; expires_at: string } | OpsAccountsFailure;
export type OpsResetResult = { ok: true } | OpsAccountsFailure;
export type OpsAccountsResult = OpsClaimResult | OpsResetResult;

export function isOpsAccountsAction(action: unknown): action is OpsAccountsAction {
	return action === "claim" || action === "reset-admins";
}

/** The signed request for action, for the container's TCP port fetch. */
export async function opsAccountsRequest(appKey: string, action: OpsAccountsAction, nowMs = Date.now()): Promise<Request> {
	const path = PATHS[action];
	const sig = await opsSignature(appKey, "POST", path, OPS_ACCOUNTS_BODY, Math.floor(nowMs / 1000));
	return new Request(`http://${OPS_HOST}${path}`, {
		method: "POST",
		headers: { "Content-Type": "application/json", [OPS_SIGNATURE_HEADER]: sig },
		body: OPS_ACCOUNTS_BODY,
	});
}

/** The container's error statuses as codes (OpsAccountsEndpoint). 404: the endpoint is not there (not hosted). */
const STATUS_CODES: Record<number, string> = { 401: "unauthorized", 404: "not_hosted", 500: "accounts_failed", 503: "not_configured" };

/** The container's answer as a typed result. Only a 200 of the expected shape is ok; nothing from the body other than
 *  the claim's token and expiry is kept. */
export async function opsAccountsResult(action: "claim", res: Response): Promise<OpsClaimResult>;
export async function opsAccountsResult(action: "reset-admins", res: Response): Promise<OpsResetResult>;
export async function opsAccountsResult(action: OpsAccountsAction, res: Response): Promise<OpsAccountsResult>;
export async function opsAccountsResult(action: OpsAccountsAction, res: Response): Promise<OpsAccountsResult> {
	if (res.status !== 200) {
		await res.body?.cancel();
		if (res.status === 409 && action === "claim") return { ok: false, code: "active_admin_exists", status: 409 };
		return { ok: false, code: STATUS_CODES[res.status] ?? `http_${res.status}`, status: res.status };
	}
	const body = (await res.json().catch(() => null)) as { ok?: unknown; token?: unknown; expires_at?: unknown } | null;
	if (body === null || body.ok !== true) return { ok: false, code: "bad_answer", status: 200 };
	if (action === "reset-admins") return { ok: true };
	const { token, expires_at } = body;
	if (typeof token !== "string" || token === "" || typeof expires_at !== "string" || expires_at === "") {
		return { ok: false, code: "bad_answer", status: 200 };
	}
	return { ok: true, token, expires_at };
}

/** The control-plane statement behind resolveOpsInstallation: D1Database, or a test double. */
type ControlDb = { prepare(sql: string): { bind(...v: unknown[]): { all<T>(): Promise<{ results: T[] }> } } };

/** An installation the console may act on: its container object's name, or why not. Accepts the installation id or its
 *  do_name. primary is refused before any lookup and again by its row; so are unknown names, an id that is one
 *  installation's id and another's do_name, and installations that are not provisioning or active. */
export async function resolveOpsInstallation(db: ControlDb | undefined, ref: unknown): Promise<{ doName: string } | OpsAccountsFailure> {
	if (typeof ref !== "string" || ref === "" || ref.length > 63) return { ok: false, code: "unknown_installation" };
	if (ref === INSTALLATION_NAME) return { ok: false, code: "refused" };
	if (!db) return { ok: false, code: "not_configured" };
	const { results } = await db
		.prepare("SELECT id, do_name, status FROM installations WHERE id = ? OR do_name = ? LIMIT 2")
		.bind(ref, ref)
		.all<{ id: string; do_name: string; status: string }>();
	if (results.length === 0) return { ok: false, code: "unknown_installation" };
	if (results.length > 1) return { ok: false, code: "ambiguous_installation" };
	const row = results[0];
	if (row.id === INSTALLATION_NAME || row.do_name === INSTALLATION_NAME || !validDoName(row.do_name)) return { ok: false, code: "refused" };
	if (row.status !== "provisioning" && row.status !== "active") return { ok: false, code: "not_startable" };
	return { doName: row.do_name };
}
