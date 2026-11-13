// Operator console C2: the request the hosting side sends its own container for a lifecycle job's backup
// (POST http://ops.internal/internal/backup, docs/operator-console.md#backup-for-a-lifecycle-job). Signed with a key
// derived from the installation's APP_KEY (installationAppKey), the same derivation as PHP's OpsSignature.

export const OPS_HOST = "ops.internal";
export const OPS_BACKUP_PATH = "/internal/backup";
export const OPS_SIGNATURE_HEADER = "X-Apotheke-Ops-Signature";

const enc = new TextEncoder();

function appKeyBytes(appKey: string): Uint8Array {
	const raw = appKey.trim().replace(/^base64:/, "").trim();
	const bytes = Uint8Array.from(atob(raw), (c) => c.charCodeAt(0));
	if (bytes.length !== 32) throw new Error("APP_KEY is not a 32-byte key");
	return bytes;
}

async function hmac(key: Uint8Array, data: string): Promise<Uint8Array> {
	const k = await crypto.subtle.importKey("raw", key, { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
	return new Uint8Array(await crypto.subtle.sign("HMAC", k, enc.encode(data)));
}

const hex = (b: Uint8Array) => [...b].map((x) => x.toString(16).padStart(2, "0")).join("");

/** `t=<unix seconds>,v1=<hex>` for METHOD, PATH and body at time t (seconds). */
export async function opsSignature(appKey: string, method: string, path: string, body: string, t: number): Promise<string> {
	const key = await hmac(appKeyBytes(appKey), "apotheke-ops-v1");
	const bodyHash = hex(new Uint8Array(await crypto.subtle.digest("SHA-256", enc.encode(body))));
	return `t=${t},v1=${hex(await hmac(key, `${method.toUpperCase()}\n${path}\n${t}\n${bodyHash}`))}`;
}

/** The signed backup request for job jobId, for the container's TCP port fetch. */
export async function opsBackupRequest(appKey: string, jobId: string, nowMs = Date.now()): Promise<Request> {
	const body = JSON.stringify({ job_id: jobId });
	const sig = await opsSignature(appKey, "POST", OPS_BACKUP_PATH, body, Math.floor(nowMs / 1000));
	return new Request(`http://${OPS_HOST}${OPS_BACKUP_PATH}`, {
		method: "POST",
		headers: { "Content-Type": "application/json", [OPS_SIGNATURE_HEADER]: sig },
		body,
	});
}
