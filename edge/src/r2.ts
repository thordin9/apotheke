// Container-facing storage: installation identity and prefix stay in the Worker.
// No client-supplied prefix or Cloudflare credential crosses r2.internal.
const PREFIX = "installations/primary/";
const PART = 5 * 1024 * 1024;
export type R2Env = { STORAGE?: R2Bucket };
export type R2SessionMap = Map<string, Session>;
type Session = { key: string; parts: R2UploadedPart[]; next: number };

export function validKey(key: string): boolean {
	if (key === "" || key.length > 1024 || key.startsWith("/") || key.includes("\\") || /[?#\x00-\x1f\x7f]/.test(key)) return false;
	return !key.split("/").some((segment) => segment === "" || segment === "." || segment === "..");
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

function json(body: object, status = 200): Response {
	return Response.json(body, { status, headers: { "Cache-Control": "no-store" } });
}

export function r2UnboundResponse(): Response {
	return json({ ok: false }, 503);
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

/** Handles only relative keys. Caller sessions are local calls, not an external API. */
export async function handleR2Request(request: Request, bucket: R2Bucket, sessions: R2SessionMap): Promise<Response> {
	const relative = key(request);
	if (!relative) return json({ ok: false }, 400);
	const path = PREFIX + relative;
	const url = new URL(request.url);
	const action = url.searchParams.get("action") ?? "";
	const id = url.searchParams.get("upload_id") ?? "";
	const session = sessions.get(id);
	if (["part", "complete", "abort"].includes(action) && (!/^[A-Za-z0-9_-]{1,200}$/.test(id) || !session || session.key !== path)) return json({ ok: false }, 400);
	try {
		if (request.method === "GET" && action === "list") {
			const prefix = url.searchParams.get("prefix") ?? "";
			const limit = Number(url.searchParams.get("limit") ?? "100");
			const cursor = url.searchParams.get("cursor") ?? "";
			if ((prefix !== "" && !validKey(prefix)) || !Number.isInteger(limit) || limit < 1 || limit > 1000) return json({ ok: false }, 400);
			const options: R2ListOptions = { prefix: prefix === "" ? PREFIX : PREFIX + prefix, limit };
			if (cursor !== "") options.cursor = cursor;
			const result = await bucket.list(options);
			if (!result.objects.every((item) => item.key.startsWith(PREFIX))) return json({ ok: false }, 502);
			return json({ ok: true, objects: result.objects.map((item) => item.key.slice(PREFIX.length)), cursor: result.truncated ? result.cursor : null });
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
				sessions.delete(id);
				await bucket.resumeMultipartUpload(path, id).abort();
				return json({ ok: true });
			}
			if (action !== "") return json({ ok: false }, 400);
			await bucket.delete(path);
			return json({ ok: true });
		}
		if (request.method !== "PUT") return json({ ok: false }, 405);
		if (action === "begin") {
			if (request.body || url.searchParams.has("prefix") || url.searchParams.has("if_none_match") || url.searchParams.has("if_match")) return json({ ok: false }, 400);
			const upload = await bucket.createMultipartUpload(path);
			sessions.set(upload.uploadId, { key: path, parts: [], next: 1 });
			return json({ ok: true, upload_id: upload.uploadId });
		}
		if (action === "part" && session) {
			const part = Number(url.searchParams.get("part"));
			if (part !== session.next || part > 10000) return json({ ok: false }, 400);
			const body = await readPart(request);
			if (!body) return json({ ok: false }, 400);
			const uploaded = await bucket.resumeMultipartUpload(path, id).uploadPart(part, body);
			session.parts.push(uploaded);
			session.next++;
			return json({ ok: true, bytes: body.byteLength });
		}
		if (action === "complete" && session) {
			sessions.delete(id);
			await bucket.resumeMultipartUpload(path, id).complete(session.parts);
			return json({ ok: true });
		}
		if (action !== "") return json({ ok: false }, 400);
		const body = await readBody(request, 100 * 1024 * 1024);
		if (body === undefined) return json({ ok: false }, 413);
		if (url.searchParams.has("if_none_match") || url.searchParams.has("if_match")) {
			const onlyIf: R2Conditional = {};
			if (url.searchParams.get("if_none_match") === "*") onlyIf.etagDoesNotMatch = "*";
			else if (url.searchParams.get("if_match") === "*") onlyIf.etagMatches = "*";
			else return json({ ok: false }, 400);
			const created = await bucket.put(path, body, { onlyIf });
			return json({ ok: true, created: Boolean(created) }, created ? 200 : 412);
		}
		await bucket.put(path, body);
		return json({ ok: true });
	} catch {
		if (session && sessions.get(id) === session) {
			sessions.delete(id);
			try { await bucket.resumeMultipartUpload(path, id).abort(); } catch { /* Best effort retains a generic failure. */ }
		}
		return json({ ok: false }, 500);
	}
}

function encodeBase64(bytes: ArrayBuffer): string {
	const input = new Uint8Array(bytes);
	let raw = "";
	for (let index = 0; index < input.length; index += 32768) raw += String.fromCharCode(...input.subarray(index, index + 32768));
	return btoa(raw);
}
