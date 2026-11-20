// operator_audit for apotheke-ops (edge/migrations/control/0001_control_plane.sql): every console action writes its
// D1 change and its audit row in one D1 batch (one transaction), so neither is kept without the other. An action that
// changes nothing in D1 (a claim or an admin reset, done by the installation's own app) writes the audit row alone.
// Never put a token or a link in an audit row: the helper refuses before writing.

export type AuditEntry = {
	operator: string;
	action: string;
	/** The installation acted on (operator_audit.installation_id), or null. */
	target: string | null;
	before?: unknown;
	after?: unknown;
	requestId: string;
};

/** The control-plane calls the helper uses: D1Database, or a test double. */
export type AuditDb = Pick<D1Database, "prepare" | "batch">;

const SECRET_KEYS = /"(?:token|link|claim_token|password|secret)"\s*:/i;

function json(value: unknown): string | null {
	if (value === undefined || value === null) return null;
	const text = JSON.stringify(value);
	if (SECRET_KEYS.test(text)) throw new Error("audit: refused a value that looks like a credential");
	return text;
}

/** The INSERT for entry, for a batch. */
export function auditStatement(db: AuditDb, entry: AuditEntry): D1PreparedStatement {
	return db
		.prepare("INSERT INTO operator_audit (id, operator, action, installation_id, before_json, after_json, request_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
		.bind(crypto.randomUUID(), entry.operator, entry.action, entry.target, json(entry.before), json(entry.after), entry.requestId);
}

/** Runs changes and entry's audit row as one D1 batch. Throws when the batch fails: nothing is kept then. */
export async function audited(db: AuditDb, entry: AuditEntry, changes: D1PreparedStatement[] = []): Promise<void> {
	await db.batch([...changes, auditStatement(db, entry)]);
}
