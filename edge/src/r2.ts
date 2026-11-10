// Container-facing storage: installation identity and prefix stay in the Worker.
// No client-supplied prefix or Cloudflare credential crosses r2.internal.
const PREFIX = "installations/primary/";
const PART = 5 * 1024 * 1024;
const MARKER = ".mpu/";
// R2 accepts 1024 UTF-8 key bytes, not 1024 code units. Escapes and
// multi-byte UTF-8 cannot combine: quote/backslash/control-escapable bytes
// use at most two of the former and six emitted bytes. Syntax costs 15 bytes.
export const MARKER_BODY_LIMIT = 6 * (1024 + 1024) + new TextEncoder().encode(PREFIX).length + 15;
const TOKEN = /^(?!.*[\x20\x22\x27\x28\x29\x2a])[\x21-\x7e]{1,1024}$/;
export type R2Env = { STORAGE?: R2Bucket; DB?: unknown };

/**
 * P4-5: the storage the container uses follows the Worker's bindings, not a var. The fragment edge/storage.jsonc
 * binds STORAGE and DB together, only behind EDGE_STORAGE_READY. Neither bound: LocalStorage (the default). Both:
 * R2. One without the other is a broken config: refuse rather than fall back, so files and data never split.
 */
export function storageBackend(env: R2Env): "local" | "r2" {
	const r2 = env.STORAGE != null;
	if (r2 !== (env.DB != null)) throw new Error("storage bindings incomplete");
	return r2 ? "r2" : "local";
}

/** The bucket r2.internal may use: only when R2 is the selected backend. */
export function selectedBucket(env: R2Env): R2Bucket | undefined {
	try {
		return storageBackend(env) === "r2" ? env.STORAGE : undefined;
	} catch {
		return undefined;
	}
}

function internalSegment(segment: string): boolean {
	return segment.startsWith(".");
}

export function validKey(key: string): boolean {
	if (key === "" || key.length > 1024 || key.startsWith("/") || key.includes("\\") || /[?#\x00-\x1f\x7f]/.test(key)) return false;
	return !key.split("/").some((segment) => segment === "" || internalSegment(segment));
}

/** @internal Raw-path unit guard; production uses the URL-normalized Request path. */
export function validateRawPath(pathname: string): string | undefined {
	const raw = pathname.slice(1);
	try {
		const decoded = raw.split("/").map((segment) => decodeURIComponent(segment));
		if (decoded.some((segment) => segment.includes("/") || segment.includes("\\"))) return undefined;
		const path = decoded.join("/");
		return validKey(path) ? path : undefined;
	} catch {
		return undefined;
	}
}

function key(request: Request): string | undefined {
	return validateRawPath(new URL(request.url).pathname);
}

async function markerKey(id: string): Promise<string> {
	const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(id));
	return PREFIX + MARKER + Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

/** Plan §1A.4. The marker's uploaded time is when the upload began; parts do not refresh it. */
export const UNFINISHED_UPLOAD_RETENTION_MS = 24 * 60 * 60 * 1000;
const SWEEP_LIMIT_DEFAULT = 50;
const SWEEP_LIMIT_MAX = 100;
const MARKER_PREFIX = PREFIX + MARKER;

export type MultipartSweep = {
	examined: number;
	aborted: number;
	markersDeleted: number;
	malformed: number;
	kept: number;
	cursor: string | null;
	interrupted: boolean;
};

/** The marker object name for an upload id. Tests use this to place fixtures under the install prefix. */
export function multipartMarkerKey(id: string): Promise<string> {
	return markerKey(id);
}

function uploadGone(error: unknown): boolean {
	if (typeof error !== "object" || error === null) return false;
	const value = error as { code?: unknown; message?: unknown };
	if (value.code === 10024) return true;
	const message = value.message?.toString();
	return typeof message === "string" && message.endsWith(" (10024)");
}

function retryCursor(cursor: string | undefined): string | null {
	return cursor ? cursor : null;
}

/**
 * Expire unfinished multipart uploads that still have a marker, one list page at a time.
 * The binding's list() returns objects, not multipart uploads. A marker is removed only after
 * resumeMultipartUpload().abort() returns, or after that call reports the upload is already gone.
 * Deleting a marker does not abort an upload. A malformed marker is removed without an abort.
 * An upload with no marker is invisible here; the account lifecycle rule is what can expire it.
 * This function is not wired to a request or a schedule.
 */
export async function sweepExpiredMultipartUploads(
	bucket: R2Bucket,
	now: number,
	options: { cursor?: string; limit?: number; retentionMs?: number } = {},
): Promise<MultipartSweep> {
	const requested = options.limit ?? SWEEP_LIMIT_DEFAULT;
	const limit = Number.isInteger(requested) && requested >= 1 ? Math.min(requested, SWEEP_LIMIT_MAX) : SWEEP_LIMIT_DEFAULT;
	const retention = options.retentionMs ?? UNFINISHED_UPLOAD_RETENTION_MS;
	const listOptions: R2ListOptions = { prefix: MARKER_PREFIX, limit };
	if (options.cursor) listOptions.cursor = options.cursor;
	const page = await bucket.list(listOptions);
	const result: MultipartSweep = {
		examined: 0,
		aborted: 0,
		markersDeleted: 0,
		malformed: 0,
		kept: 0,
		cursor: page.truncated ? page.cursor : null,
		interrupted: false,
	};
	for (const object of page.objects) {
		if (!object.key.startsWith(MARKER_PREFIX)) continue;
		result.examined += 1;
		const uploaded = object.uploaded;
		if (!(uploaded instanceof Date) || !Number.isFinite(uploaded.getTime()) || !Number.isFinite(now) || now - uploaded.getTime() < retention) {
			result.kept += 1;
			continue;
		}
		const target = await expiredUpload(bucket, object);
		if (target === null) {
			try {
				await bucket.delete(object.key);
			} catch {
				result.interrupted = true;
				result.cursor = retryCursor(options.cursor);
				return result;
			}
			result.malformed += 1;
			result.markersDeleted += 1;
			continue;
		}
		try {
			await bucket.resumeMultipartUpload(target.key, target.id).abort();
		} catch (error) {
			if (!uploadGone(error)) {
				result.interrupted = true;
				result.cursor = retryCursor(options.cursor);
				return result;
			}
		}
		result.aborted += 1;
		try {
			await bucket.delete(object.key);
		} catch {
			result.interrupted = true;
			result.cursor = retryCursor(options.cursor);
			return result;
		}
		result.markersDeleted += 1;
	}
	return result;
}

/** A dated marker whose body still names one upload under this install. Null means do not abort. */
async function expiredUpload(bucket: R2Bucket, object: R2Object): Promise<{ key: string; id: string } | null> {
	if (object.size > MARKER_BODY_LIMIT) return null;
	const marker = await bucket.get(object.key);
	if (!marker || marker.size > MARKER_BODY_LIMIT) return null;
	let body: unknown;
	try {
		body = JSON.parse(await marker.text());
	} catch {
		return null;
	}
	if (typeof body !== "object" || body === null || Array.isArray(body)) return null;
	if (Object.keys(body).join(",") !== "id,key") return null;
	const id = (body as { id: unknown }).id;
	const key = (body as { key: unknown }).key;
	if (typeof id !== "string" || typeof key !== "string" || !TOKEN.test(id)) return null;
	if (!key.startsWith(PREFIX) || object.key !== await markerKey(id)) return null;
	const relative = key.slice(PREFIX.length);
	if (!validKey(relative)) return null;
	return { key, id };
}

async function boundUploadKey(bucket: R2Bucket, path: string, id: string): Promise<boolean> {
	const marker = await bucket.get(await markerKey(id));
	if (!marker || marker.size > MARKER_BODY_LIMIT) return false;
	try {
		const body: unknown = JSON.parse(await marker.text());
		return typeof body === "object" && body !== null && !Array.isArray(body)
			&& Object.keys(body).join(",") === "id,key" && (body as { key: unknown; id: unknown }).key === path
			&& (body as { id: unknown }).id === id;
	} catch {
		return false;
	}
}

async function finalizeUpload(bucket: R2Bucket, path: string, id: string, action: () => Promise<unknown>): Promise<void> {
	try {
		await action();
	} catch (error) {
		if (typeof error === "object" && error !== null
			&& ((error as { code?: unknown }).code === 10024 || (error as { message?: unknown }).message?.toString().endsWith(" (10024)"))) {
			await bucket.delete(await markerKey(id));
		}
		throw error;
	}
	await bucket.delete(await markerKey(id));
}

function json(body: object, status = 200): Response {
	return Response.json(body, { status, headers: { "Cache-Control": "no-store" } });
}

export function r2UnboundResponse(): Response {
	return json({ ok: false, code: "unbound", error: "Object storage is not available." }, 503);
}

export async function readPart(request: Request): Promise<Uint8Array | undefined> {
	const size = Number(new URL(request.url).searchParams.get("size"));
	if (!Number.isSafeInteger(size) || size < 0 || size > PART || !request.body) return undefined;
	const reader = request.body.getReader();
	const body = new Uint8Array(size);
	let cursor = 0;
	while (cursor < size) {
		const { done, value } = await reader.read();
		if (done || cursor + value.length > size) return undefined;
		body.set(value, cursor);
		cursor += value.length;
	}
	const extra = await reader.read();
	return extra.done && cursor === size ? body : undefined;
}

async function readBody(request: Request, maximum: number): Promise<Uint8Array | undefined> {
	if (!request.body || Number(request.headers.get("content-length") ?? maximum) > maximum) return undefined;
	const reader = request.body.getReader();
	let body = new Uint8Array(256 * 1024);
	let size = 0;
	while (true) {
		const { done, value } = await reader.read();
		if (done) return body.subarray(0, size);
		if (size + value.byteLength > maximum) return undefined;
		if (size + value.byteLength > body.byteLength) {
			const next = new Uint8Array(Math.min(maximum, Math.max(256 * 1024, body.byteLength * 2)));
			next.set(body);
			body = next;
		}
		body.set(value, size);
		size += value.byteLength;
	}
}

/** Handles only relative keys. The caller carries upload IDs and part metadata, not an external API. */
function completedParts(request: Request): R2UploadedPart[] | undefined {
	try {
		const raw = new URL(request.url).searchParams.get("parts");
		if (raw === null) return undefined;
		const parsed: unknown = JSON.parse(raw);
		if (raw === null || !Array.isArray(parsed) || parsed.length > 10000) return undefined;
		if (parsed.some((part) => typeof part !== "object" || part === null || Array.isArray(part))) return undefined;
		return parsed.map((part: object, index: number) => {
			const value = part as Record<string, unknown>;
			if (Object.keys(value).sort().join(",") !== "etag,partNumber"
				|| value.partNumber !== index + 1 || typeof value.etag !== "string" || !TOKEN.test(value.etag)) throw new Error("invalid part");
			return { partNumber: value.partNumber, etag: value.etag };
		});
	} catch {
		return undefined;
	}
}

export async function handleR2Request(request: Request, bucket: R2Bucket): Promise<Response> {
	const relative = key(request);
	if (!relative) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
	const path = PREFIX + relative;
	const url = new URL(request.url);
	const action = url.searchParams.get("action") ?? "";
	const id = url.searchParams.get("upload_id") ?? "";
	if (["part", "complete", "abort"].includes(action)) {
		if (request.method !== (action === "abort" ? "DELETE" : "PUT") || !TOKEN.test(id)
			|| url.searchParams.has("parts") !== (action === "complete")) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
		if (action === "part" && (!/^[1-9][0-9]{0,7}$/.test(url.searchParams.get("part") ?? "") || !/^(0|[1-9][0-9]{0,7})$/.test(url.searchParams.get("size") ?? ""))) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
	}
	try {
		if (request.method === "GET" && action === "list") {
			const prefix = url.searchParams.get("prefix") ?? "";
			const limit = Number(url.searchParams.get("limit") ?? "100");
			const cursor = url.searchParams.get("cursor") ?? "";
			if ((prefix !== "" && !validKey(prefix)) || !Number.isInteger(limit) || limit < 1 || limit > 1000) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
			const options: R2ListOptions = { prefix: prefix === "" ? PREFIX : PREFIX + prefix, limit };
			if (cursor !== "") options.cursor = cursor;
			const result = await bucket.list(options);
			if (!result.objects.every((item) => item.key.startsWith(PREFIX))) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 502);
			const objects = result.objects.map((item) => item.key.slice(PREFIX.length));
			if (objects.some((item) => item !== "" && !validKey(item) && !item.split("/").some(internalSegment))) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 502);
			return json({ ok: true, objects: objects.filter((item) => !item.split("/").some(internalSegment)), cursor: result.truncated ? result.cursor : null });
		}
		if (request.method === "GET") {
			const object = await bucket.get(path);
			if (!object) return json({ ok: true, found: false });
			const etag = object.etag;
			return json({ ok: true, found: true, bytes: encodeBase64(await object.arrayBuffer()), etag });
		}
		if (request.method === "HEAD") {
			const object = await bucket.head(path);
			return json({ ok: true, found: Boolean(object), ...(object ? { etag: object.etag, modified: Math.floor((object.uploaded?.getTime() ?? 0) / 1000) } : {}) });
		}
		if (request.method === "DELETE") {
			if (action === "abort") {
				if (!await boundUploadKey(bucket, path, id)) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
				await finalizeUpload(bucket, path, id, () => bucket.resumeMultipartUpload(path, id).abort());
				return json({ ok: true });
			}
			if (action !== "") return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
			await bucket.delete(path);
			return json({ ok: true });
		}
		if (request.method !== "PUT") return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 405);
		if (action === "begin") {
			if (request.body || url.searchParams.has("prefix") || url.searchParams.has("if_none_match") || url.searchParams.has("if_match")) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
			const upload = await bucket.createMultipartUpload(path);
			if (!TOKEN.test(upload.uploadId)) {
				try { await upload.abort(); } catch { /* Reject an unusable ID without exposing it. */ }
				return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 502);
			}
			const body = JSON.stringify({ id: upload.uploadId, key: path });
			if (new TextEncoder().encode(body).length > MARKER_BODY_LIMIT) {
				try { await upload.abort(); } catch { /* Reject an unrepresentable binding without exposing it. */ }
				return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 502);
			}
			let marker: R2Object | null;
			try {
				marker = await bucket.put(await markerKey(upload.uploadId), body, { onlyIf: { etagDoesNotMatch: "*" } });
				if (!marker) throw new Error("multipart marker collision");
			} catch (error) {
				try { await upload.abort(); } catch { /* Best effort cleanup before the generic failure. */ }
				throw error;
			}
			return json({ ok: true, upload_id: upload.uploadId });
		}
		if (action === "part") {
			const part = Number(url.searchParams.get("part"));
			if (!Number.isSafeInteger(part) || part < 1 || part > 10000) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
			if (!await boundUploadKey(bucket, path, id)) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
			const body = await readPart(request);
			if (!body) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
			const uploaded = await bucket.resumeMultipartUpload(path, id).uploadPart(part, body);
			if (uploaded.partNumber !== part || !TOKEN.test(uploaded.etag)) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 502);
			return json({ ok: true, bytes: body.byteLength, part_number: uploaded.partNumber, etag: uploaded.etag });
		}
		if (action === "complete") {
			const parts = completedParts(request);
			if (!parts || !await boundUploadKey(bucket, path, id)) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
			await finalizeUpload(bucket, path, id, () => bucket.resumeMultipartUpload(path, id).complete(parts));
			return json({ ok: true });
		}
		if (action !== "") return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
		const body = await readBody(request, 100 * 1024 * 1024);
		if (body === undefined) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 413);
		if (url.searchParams.has("if_none_match") || url.searchParams.has("if_match")) {
			const onlyIf: R2Conditional = {};
			if (url.searchParams.get("if_none_match") === "*") onlyIf.etagDoesNotMatch = "*";
			else if (url.searchParams.get("if_match")) onlyIf.etagMatches = url.searchParams.get("if_match")!;
			else return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
			const created = await bucket.put(path, body, { onlyIf });
			return json({ ok: true, created: Boolean(created) }, created ? 200 : 412);
		}
		await bucket.put(path, body);
		return json({ ok: true });
	} catch (error) {
		console.error("r2.internal request failed", error);
		return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 500);
	}
}

function encodeBase64(bytes: ArrayBuffer): string {
	const input = new Uint8Array(bytes);
	let raw = "";
	for (let index = 0; index < input.length; index += 32768) raw += String.fromCharCode(...input.subarray(index, index + 32768));
	return btoa(raw);
}
