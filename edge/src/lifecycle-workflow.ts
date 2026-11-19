// The Workflow that runs lifecycle jobs (src/lifecycle.ts). It is not part of the apotheke-api Worker: index.ts does not
// export it and wrangler.jsonc has no workflow, cron or binding for it. The operator Worker (apotheke-ops) deploys it
// later with the bindings below. An instance is created per confirmed job, with the job id as the instance id.
import { WorkflowEntrypoint, type WorkflowEvent, type WorkflowStep } from "cloudflare:workers";
import type { ApothekeContainer } from "./index";
import { installationStub, type Installation } from "./installation";
import { LifecycleError, LifecycleRetry, runJob, type LifecycleDeps } from "./lifecycle";

export type LifecycleEnv = {
	DB: D1Database;
	STORAGE: R2Bucket;
	INSTALLATION: DurableObjectNamespace<Installation>;
	APOTHEKE: DurableObjectNamespace<ApothekeContainer>;
};

export class LifecycleWorkflow extends WorkflowEntrypoint<LifecycleEnv, { jobId: string }> {
	async run(event: Readonly<WorkflowEvent<{ jobId: string }>>, step: WorkflowStep): Promise<void> {
		await runJob(lifecycleDeps(this.env), event.payload.jobId, step);
	}
}

/** Not available yet: the step fails and the job is marked failed with this code. */
const pending = (code: string) => async (): Promise<never> => {
	throw new LifecycleError(code);
};

/** The container's backup answer as a job outcome. 409 (another request for the job is still running), 5xx other than
 *  503 and a call that did not finish are retried. A returned code (refused, not_startable, bad_app_key), any other
 *  status (400, 401, 404, 410, 503 not_configured) and a 200 without a usable key fail the job; the last as bad_export. */
export type OpsBackupResult = { key: string } | { status: number } | { code: string };

export async function backupKey(call: () => Promise<OpsBackupResult>): Promise<{ key: string }> {
	let r: OpsBackupResult;
	try {
		r = await call();
	} catch {
		throw new LifecycleRetry("backup_unreachable");
	}
	if ("key" in r) return { key: r.key };
	if ("code" in r) throw new LifecycleError(r.code);
	if (r.status === 409 || (r.status >= 500 && r.status !== 503)) throw new LifecycleRetry(`backup_${r.status}`);
	throw new LifecycleError(r.status === 200 ? "bad_export" : `backup_${r.status}`);
}

export function lifecycleDeps(env: LifecycleEnv): LifecycleDeps {
	// The container object of an installation: the same getByName(do_name) the Worker routes to.
	const container = (doName: string) => env.APOTHEKE.getByName(doName);
	return {
		db: env.DB,
		bucket: env.STORAGE,
		installation: { purge: (doName) => installationStub(env.INSTALLATION, doName).purge(doName) },
		container: {
			stop: (doName) => container(doName).stopContainer(),
			purge: (doName) => container(doName).purge(),
			// BOOTSTRAP=1 start and the first-admin claim come with C1. The backup is signed inside the container object,
			// which holds APP_KEY; this Worker has none.
			bootstrap: pending("pending_c1"),
			backup: (doName, backupId) => backupKey(() => container(doName).opsBackup(backupId)),
		},
		claim: { create: pending("pending_c1") },
		// Routes are cached only inside each apotheke-api isolate, for at most 30 seconds (route.ts): nothing to purge here.
		routeCache: { purge: async () => {} },
	};
}
