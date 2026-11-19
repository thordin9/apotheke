// Autofill for installations other than primary (operator console, L-2190). The container reaches the
// autofill service only at http://autofill.internal, through AutofillOutbound (index.ts), and only with LIMITS_ENABLED
// "1". The Worker calls the service's HostedAutofill entrypoint over the AUTOFILL service binding: no bearer token is
// involved, and the container holds none. Only this Worker sets the installation (the container object's own name)
// and the monthly limit (autofill_calls_month from plan_limits, loaded with the route); every other request header
// from the container is dropped except the body's type and length.
import { INSTALLATION_NAME, validDoName } from "./installation";
import { limitsEnabled, type Limit } from "./limits";

export const AUTOFILL_HOST = "autofill.internal";
export const INSTALLATION_HEADER = "X-Apotheke-Installation";
export const LIMIT_HEADER = "X-Apotheke-Autofill-Limit";
export const SOFT_HEADER = "X-Apotheke-Autofill-Soft";

/** The service binding to the autofill Worker's HostedAutofill entrypoint. Not bound means autofill is refused. */
export type AutofillEnv = { AUTOFILL?: Fetcher; LIMITS_ENABLED?: string };
/** Set by the container object: its own name, and autofill_calls_month (null for no limit). No autofill key: refused. */
export type AutofillProps = { doName?: unknown; autofill?: Limit | null };

const PATHS = new Set(["/v1/info", "/v1/suggest"]);
const KEPT = ["content-type", "content-length", "accept"];

function refused(status: number, message: string): Response {
	return new Response(JSON.stringify({ error: { code: status === 404 ? "bad_request" : "backend_unavailable", message } }), {
		status,
		headers: { "content-type": "application/json", "cache-control": "no-store" },
	});
}

/** A count the service accepts: a non-negative integer of at most 15 digits, rounded down or up. */
function count(value: number, round: (n: number) => number = Math.floor): string {
	return String(Math.min(999_999_999_999_999, Math.max(0, round(value))));
}

/** The request the Worker sends to the service: the container's path, method and body; headers only from here. */
export function autofillRequest(request: Request, doName: string, limit: Limit | null): Request {
	const url = new URL(request.url);
	const headers = new Headers();
	for (const name of KEPT) {
		const value = request.headers.get(name);
		if (value !== null) headers.set(name, value);
	}
	headers.set(INSTALLATION_HEADER, doName);
	if (limit) {
		headers.set(LIMIT_HEADER, count(limit.hard));
		// Rounded up, so a fractional soft value (80% of hard by default) warns no earlier than the service would.
		headers.set(SOFT_HEADER, count(limit.soft, Math.ceil));
	}
	const init: RequestInit & { duplex?: "half" } = { method: request.method, headers, redirect: "manual" };
	if (request.body !== null) {
		init.body = request.body;
		init.duplex = "half";
	}
	return new Request(`https://${AUTOFILL_HOST}${url.pathname}`, init);
}

/** One autofill call from the container. Fails closed: limits off, primary, no installation, no limit set by the
 *  container object, or no AUTOFILL binding each answer 503 without calling the service. */
export async function handleAutofill(request: Request, env: AutofillEnv, props: AutofillProps | undefined): Promise<Response> {
	const doName = props?.doName;
	if (!limitsEnabled(env) || !validDoName(doName) || doName === INSTALLATION_NAME || props?.autofill === undefined) {
		return refused(503, "autofill is not available for this installation");
	}
	if (!env.AUTOFILL) return refused(503, "autofill is not configured");
	if (!PATHS.has(new URL(request.url).pathname)) return refused(404, "Not Found");
	return env.AUTOFILL.fetch(autofillRequest(request, doName, props.autofill));
}
