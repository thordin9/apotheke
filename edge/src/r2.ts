// Container-facing storage: installation identity and prefix stay in the Worker.
// No client-supplied prefix or Cloudflare credential crosses r2.internal.
const PREFIX = "installations/primary/";
const PART = 5 * 1024 * 1024;
const MARKER = ".mpu/";
const TOKEN = /^(?!.*[\x20\x22\x27\x28\x29\x2a])[\x21-\x7e]{1,1024}$/;
export type R2Env = { STORAGE?: R2Bucket };

export function validKey(key: string): boolean {
	if (key === "" || key.length > 1024 || key.startsWith("/") || key.includes("\\") || /[?#\x00-\x1f\x7f]/.test(key)) return false;
	return !key.split("/").some((segment) => segment === "" || segment.startsWith("."));
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

async function boundUploadKey(bucket: R2Bucket, path: string, id: string): Promise<boolean> {
	const marker = await bucket.get(await markerKey(id));
	if (!marker || marker.size > 2048) return false;
	try {
		const body: unknown = JSON.parse(await marker.text());
		return typeof body === "object" && body !== null && !Array.isArray(body)
			&& Object.keys(body).join(",") === "id,key" && (body as { key: unknown; id: unknown }).key === path
			&& (body as { id: unknown }).id === id;
	} catch {
		return false;
	}
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
			if (objects.some((item) => item !== "" && !item.startsWith(MARKER) && !validKey(item))) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 502);
			return json({ ok: true, objects: objects.filter((item) => !item.startsWith(MARKER)), cursor: result.truncated ? result.cursor : null });
		}
		if (request.method === "GET") {
			const object = await bucket.get(path);
			if (!object) return json({ ok: true, found: false });
			return json({ ok: true, found: true, bytes: encodeBase64(await object.arrayBuffer()) });
		}
		if (request.method === "HEAD") {
			const object = await bucket.head(path);
			return json({ ok: true, found: Boolean(object), ...(object ? { modified: Math.floor((object.uploaded?.getTime() ?? 0) / 1000) } : {}) });
		}
		if (request.method === "DELETE") {
			if (action === "abort") {
				if (!await boundUploadKey(bucket, path, id)) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
				await bucket.delete(await markerKey(id));
				await bucket.resumeMultipartUpload(path, id).abort();
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
			let marker: R2Object | null;
			try {
				marker = await bucket.put(await markerKey(upload.uploadId), JSON.stringify({ id: upload.uploadId, key: path }), { onlyIf: { etagDoesNotMatch: "*" } });
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
			await bucket.delete(await markerKey(id));
			await bucket.resumeMultipartUpload(path, id).complete(parts);
			return json({ ok: true });
		}
		if (action !== "") return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 400);
		const body = await readBody(request, 100 * 1024 * 1024);
		if (body === undefined) return json({ ok: false, code: "storage_failed", error: "Object storage request failed." }, 413);
		if (url.searchParams.has("if_none_match") || url.searchParams.has("if_match")) {
			const onlyIf: R2Conditional = {};
			if (url.searchParams.get("if_none_match") === "*") onlyIf.etagDoesNotMatch = "*";
			else if (url.searchParams.get("if_match") === "*") onlyIf.etagMatches = "*";
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
