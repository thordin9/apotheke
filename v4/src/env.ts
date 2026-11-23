// Bindings and vars the Worker and its Durable Object are given (plan §10). One place, so a test can
// never invent a binding the Worker does not have.
//
// The config vars (S0.3, plan §11) are the same names the PHP app reads, so one `.env.example`
// describes both runtimes. Absent or empty means "use the built-in default", exactly as
// `config_load()` treats an empty environment variable.

/** The environment variables the config loader and the adapters read (plan §11). */
export interface ConfigVars {
	/** Becomes the `admin` account's password on a fresh install (`cfg_hash`), and `auth_enabled`. */
	readonly APP_PASSWORD?: string;
	/** At-rest key for `enc:v1:` values: base64 of 32 bytes, optional `base64:` prefix (§4 rule 7). */
	readonly APP_KEY?: string;
	/** Pins "now" to an ISO instant — the seam the PHP app already has (`APOTHEKE_CLOCK`). */
	readonly APOTHEKE_CLOCK?: string;
	/** Hosted: sign-in is on even before APP_PASSWORD is set. */
	readonly APOTHEKE_HOSTED?: string;
	readonly PUBLIC_URL?: string;
	readonly CURRENCY?: string;
	readonly TZ?: string;
	readonly PAGE_SIZE?: string;
	readonly MAX_UPLOAD_MB?: string;
	readonly IMAGE_FULL_MAX?: string;
	readonly IMAGE_THUMB_MAX?: string;
	readonly EXPIRY_SOON_DAYS?: string;
}

export interface Env extends ConfigVars {
	/** Hosted: the installation's D1 database (D8, one database per installation). */
	readonly DB: D1Database;
	/** `sqlite` | `d1` | `rqlite` — the mode the app is running in. */
	readonly DB_DRIVER: string;
	/** Self-host: `http(s)://host:4001` of the rqlite node. Empty means "not an rqlite install". */
	readonly RQLITE_URL: string;
	/** The installation's Durable Object (D12): scheduler, migration lock, and — under `sqlite` — the database. */
	readonly INSTALLATION: DurableObjectNamespace;
	/** R2: files, media and backups behind the `Blobs` adapter (plan §3). Keys keep the PHP layout. */
	readonly BLOBS: R2Bucket;
}
