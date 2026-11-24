// The `Sql` a request handler uses (plan §5 S1.1). One place picks the backend, from the same
// `loadDatabaseConfig()` the boot gate used, so a page never learns which driver it is on — §10's
// promise, applied to the first handler that reads the database.
//
// Under `DB_DRIVER=sqlite` the DO owns the database (D12), so the driver is the DO's RPC; D1 and
// rqlite are reached directly, as the boot gate reaches them.

import type { Env } from "../env";
import { loadDatabaseConfig } from "../platform/config";
import { D1Sql } from "./d1";
import { durableObjectSql, type InstallationStub } from "./do-sql";
import { rqliteSql } from "./rqlite";
import type { Sql } from "./sql";

export const requestSql = (env: Env, installation: InstallationStub): Sql => {
	const database = loadDatabaseConfig(env);
	if (database.mode === "sqlite") {
		return durableObjectSql(installation);
	}
	if (database.mode === "d1") {
		return new D1Sql(env.DB);
	}
	return rqliteSql(database);
};
