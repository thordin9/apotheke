// Hosted mode (ADR-002, P4-5): one SQLite-backed Durable Object per installation holds that installation's
// database. The container reaches it only through http://d1.internal (D1Outbound), and only while the storage
// mode is selected. The object answers the same /query, /batch and /exec as the D1 bridge (d1.ts).
import { DurableObject } from "cloudflare:workers";
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
		return handleD1Request(request, sqlDatabase(this.ctx.storage));
	}
}
