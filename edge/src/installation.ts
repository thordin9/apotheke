// Hosted mode (ADR-002, P4-5): one SQLite-backed Durable Object per installation holds that installation's
// database. The container reaches it only through http://d1.internal (D1Outbound), and only while the storage
// mode is selected. The object answers the same /query, /batch and /exec as the D1 bridge (d1.ts).
import { DurableObject } from "cloudflare:workers";
import { handleBridgeRead, type ControlDb } from "./bridge";
import { d1UnboundResponse, handleD1Request, type D1Like, type D1LikeStatement } from "./d1";
import { storageSelected, type R2Env } from "./r2";

/** The first installation: the routing fallback, with the container object and R2 prefix installations/primary/. */
export const INSTALLATION_NAME = "primary";

export type InstallationEnv = { INSTALLATION?: DurableObjectNamespace<Installation> };

/** Storage identity the container object passes to D1Outbound and R2Outbound as props. */
export type StorageProps = { doName?: unknown; prefix?: unknown };

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

export class Installation extends DurableObject<Env & R2Env> {
	async fetch(request: Request): Promise<Response> {
		// Reached only through D1Outbound; this check keeps the object closed on its own too.
		if (!storageSelected(this.env)) return d1UnboundResponse();
		const db = sqlDatabase(this.ctx.storage);
		// GET /stats, /users and /limits (bridge.ts); the installation is this object's own name, never the request's.
		const name = this.ctx.id?.name;
		const control = (this.env as { DB?: ControlDb }).DB;
		return (await handleBridgeRead(request, db, control, validDoName(name) ? name : undefined)) ?? handleD1Request(request, db);
	}
}
