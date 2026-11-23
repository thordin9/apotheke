// Bindings and vars the Worker and its Durable Object are given (plan §10). One place, so a test can
// never invent a binding the Worker does not have.

export interface Env {
	/** Hosted: the installation's D1 database (D8, one database per installation). */
	readonly DB: D1Database;
	/** `sqlite` | `d1` | `rqlite` — the mode the app is running in. */
	readonly DB_DRIVER: string;
	/** Self-host: `http(s)://host:4001` of the rqlite node. Empty means "not an rqlite install". */
	readonly RQLITE_URL: string;
	/** The installation's Durable Object (D12): scheduler, migration lock, and — under `sqlite` — the database. */
	readonly INSTALLATION: DurableObjectNamespace;
}
