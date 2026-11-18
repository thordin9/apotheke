// Hosted mode (ADR-002, P4-5): one SQLite-backed Durable Object per installation holds that installation's
// database. The container reaches it only through http://d1.internal (D1Outbound), and only while the storage
// mode is selected. The object answers the same /query, /batch and /exec as the D1 bridge (d1.ts).
import { DurableObject } from "cloudflare:workers";
import { handleBridgeRead, type ControlDb } from "./bridge";
import { d1UnboundResponse, handleD1Request, type D1Like, type D1LikeStatement } from "./d1";
import { Counter } from "./limits";
import { prefixBytes, selectedBucket, storageSelected, type R2Env } from "./r2";

/** The first installation: the routing fallback, with the container object and R2 prefix installations/primary/. */
export const INSTALLATION_NAME = "primary";

export type InstallationEnv = { INSTALLATION?: DurableObjectNamespace<Installation> };

/** Storage identity the container object passes to D1Outbound and R2Outbound as props. */
export type StorageProps = { doName?: unknown; prefix?: unknown; storageHard?: unknown };

/** An installation's DO name, as the control plane allows it (edge/migrations/control/0001). */
export function validDoName(name: unknown): name is string {
	return typeof name === "string" && name.length <= 63 && /^[a-z0-9]+(-[a-z0-9]+)*$/.test(name);
}

/** The R2 key prefix of an installation. */
export function installationPrefix(doName: string): string {
	return `installations/${doName}/`;
}

/** The DO name from props when they are a consistent pair, else undefined (the caller refuses storage). */
export function propsDoName(props: StorageProps | undefined): string | undefined {
	const doName = props?.doName;
	return validDoName(doName) && props?.prefix === installationPrefix(doName) ? doName : undefined;
}

/**
 * APP_KEY for an installation's container. primary gets the Worker secret unchanged (no derivation), so its stored
 * secrets keep opening. Any other installation gets HKDF-SHA256 over the secret's 32 key bytes, with the installation
 * name as info, in the form PHP accepts (SecretKey::bytes): "base64:" and the standard base64 of 32 bytes.
 * Throws when the secret is not a 32-byte key, so a second installation never starts with an unusable or shared key.
 */
export async function installationAppKey(doName: string, master: string | undefined): Promise<string> {
	if (doName === INSTALLATION_NAME) return master ?? "";
	const ikm = keyBytes(master ?? "");
	if (!ikm) throw new Error("APP_KEY is not a 32-byte key");
	const base = await crypto.subtle.importKey("raw", ikm, "HKDF", false, ["deriveBits"]);
	const bits = await crypto.subtle.deriveBits(
		{ name: "HKDF", hash: "SHA-256", salt: new TextEncoder().encode(APP_KEY_SALT), info: new TextEncoder().encode(doName) },
		base,
		256,
	);
	return "base64:" + btoa(String.fromCharCode(...new Uint8Array(bits)));
}

/** Fixed HKDF salt: versions the derivation, so a later scheme can change it without reusing keys. */
const APP_KEY_SALT = "apotheke-installation-app-key-v1";

/** The 32 key bytes of an APP_KEY, decoded as PHP does (trim, optional "base64:", strict base64), else null. */
function keyBytes(raw: string): Uint8Array | null {
	let text = raw.trim();
	if (text.startsWith("base64:")) text = text.slice(7).trim();
	if (!/^[A-Za-z0-9+/]{43}=?$/.test(text)) return null;
	const bytes = Uint8Array.from(atob(text), (c) => c.charCodeAt(0));
	return bytes.length === 32 ? bytes : null;
}

/** The name a lifecycle action may touch: a valid DO name that is not primary. Anything else throws (fail closed). */
export function lifecycleName(name: unknown): string {
	if (!validDoName(name) || name === INSTALLATION_NAME) throw new Error("lifecycle refused for this installation");
	return name;
}

/** The only place an Installation id is made: from the EU-jurisdiction namespace, fixed when the id is created. */
export function installationStub(ns: DurableObjectNamespace<Installation>, doName: string): DurableObjectStub<Installation> {
	const eu = ns.jurisdiction("eu");
	return eu.get(eu.idFromName(doName));
}

type Prepared = D1LikeStatement & { sql: string; params: unknown[] };

/**
 * D1's prepare/batch/exec over the object's own SQLite. Every call runs synchronously inside one
 * transactionSync, so a batch is all or nothing and no other request runs between its statements.
 * D1's changes/last_row_id meta is not reported: the PHP bridge reads rows only, and guards use changes() in SQL.
 */
export function sqlDatabase(storage: DurableObjectStorage): D1Like {
	const run = (s: Prepared) => ({ results: storage.sql.exec(s.sql, ...s.params).toArray() });
	const statement = (sql: string, params: unknown[] = []): Prepared => ({
		sql,
		params,
		bind: (...values: unknown[]) => statement(sql, values),
		all: async () => storage.transactionSync(() => run({ sql, params } as Prepared)),
	});
	return {
		prepare: (sql) => statement(sql),
		batch: async (statements) => storage.transactionSync(() => (statements as Prepared[]).map(run)),
		exec: async (sql) => {
			storage.transactionSync(() => storage.sql.exec(sql).toArray());
			return { count: 1 };
		},
	};
}

/** Limits (E5): how old a recount of the stored bytes may be, in the alarm and before a refusal. */
export const RECOUNT_MS = 24 * 60 * 60 * 1000;
export const RECOUNT_BEFORE_REFUSAL_MS = 60 * 60 * 1000;

type StoredBytes = { bytes: number; doName?: string; counted?: number };

export class Installation extends DurableObject<Env & R2Env> {
	/** Limits (E5): bytes stored under this installation's prefix, kept by R2Outbound and recounted from R2. */
	private stored?: Counter<StoredBytes>;
	/** The one recount in flight: concurrent callers share it. */
	private recounting: Promise<boolean> | null = null;

	private storedCounter(): Counter<StoredBytes> {
		return (this.stored ??= new Counter<StoredBytes>(this.ctx.storage, "storage_bytes", () => ({ bytes: 0 })));
	}

	/** Adds delta (negative for a delete or a smaller overwrite) to the stored bytes. With a hard value, refuses (false,
	 *  nothing added) a non-negative change that would go past it; a change of 0 is refused once the store is full.
	 *  Before a refusal the bytes are recounted from R2 when the last recount is over an hour old. doName is the
	 *  caller's installation (R2Outbound), kept for the recount when the runtime gives this object no name. */
	async storageAdd(delta: number, hard: number | null, doName?: string): Promise<boolean> {
		const counter = this.storedCounter();
		const value = await counter.get();
		if (validDoName(doName) && doName !== INSTALLATION_NAME) value.doName = doName;
		const over = () => hard !== null && delta >= 0 && value.bytes + Math.max(delta, 1) > hard;
		if (over() && Date.now() - (value.counted ?? 0) >= RECOUNT_BEFORE_REFUSAL_MS && await this.recount(value)) await counter.changed();
		if (over()) return false;
		value.bytes = Math.max(0, value.bytes + delta);
		await counter.changed();
		return true;
	}

	/**
	 * Resets the stored bytes to the total listed under the installation's prefix. r2.internal reads an object's old
	 * size, then writes, so concurrent writes to one key can count the same bytes twice (or free them twice); a recount
	 * ends any such drift. Writes during the listing can leave it off by their own size until the next recount.
	 * False, with the counter unchanged, when there is no bucket, no installation name (or it is primary) or the listing
	 * fails.
	 */
	private async recount(value: StoredBytes): Promise<boolean> {
		const bucket = selectedBucket(this.env);
		const name = this.ctx.id?.name ?? value.doName;
		if (!bucket || !validDoName(name) || name === INSTALLATION_NAME) return false;
		this.recounting ??= (async () => {
			const started = Date.now();
			try {
				value.bytes = await prefixBytes(bucket, installationPrefix(name));
				value.counted = started;
				return true;
			} catch (e) {
				console.error("stored bytes recount failed", String(e));
				return false;
			}
		})().finally(() => {
			this.recounting = null;
		});
		return this.recounting;
	}

	/** Writes the stored-bytes counter (set at most FLUSH_MS after a change), recounted first when the last recount is
	 *  over a day old. The alarm is set only by a change, so an idle installation is not listed. */
	async alarm(): Promise<void> {
		const counter = this.storedCounter();
		const value = await counter.get();
		const recounted = Date.now() - (value.counted ?? 0) >= RECOUNT_MS && await this.recount(value);
		await counter.flush(recounted);
	}

	async fetch(request: Request): Promise<Response> {
		// Reached only through D1Outbound; this check keeps the object closed on its own too.
		if (!storageSelected(this.env)) return d1UnboundResponse();
		const db = sqlDatabase(this.ctx.storage);
		// GET /stats, /users and /limits (bridge.ts); the installation is this object's own name, never the request's.
		const name = this.ctx.id?.name;
		const control = (this.env as { DB?: ControlDb }).DB;
		return (await handleBridgeRead(request, db, control, validDoName(name) ? name : undefined)) ?? handleD1Request(request, db);
	}

	/** Lifecycle purge (src/lifecycle.ts): deletes this installation's database. The caller names the installation, and it
	 *  must match this object's own name when the runtime gives one. Refused for primary. */
	async purge(doName: string): Promise<void> {
		const name = lifecycleName(doName);
		const own = this.ctx.id?.name;
		if (own !== undefined && own !== name) throw new Error("lifecycle refused for this installation");
		await this.ctx.storage.deleteAll();
	}
}
