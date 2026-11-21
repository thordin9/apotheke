// The Workflow that runs lifecycle jobs (src/lifecycle.ts), hosted by apotheke-api: index.ts exports it and
// storage.jsonc declares it (apotheke-lifecycle), so it has apotheke-api's own bindings below. apotheke-ops starts an
// instance per confirmed job through its LIFECYCLE binding (script_name apotheke-api), with the job id as the instance
// id. Before any step, preflight() refuses unless OPS_WRITES_ENABLED is "1", and refuses primary.
import { WorkflowEntrypoint, type WorkflowEvent, type WorkflowStep } from "cloudflare:workers";
import { AUTOFILL_HOST } from "./autofill";
import type { ApothekeContainer } from "./index";
import { INSTALLATION_NAME, installationStub, type Installation } from "./installation";
import { LifecycleError, LifecycleRetry, runJob, type LifecycleDeps } from "./lifecycle";

export type LifecycleEnv = {
	DB: D1Database;
	STORAGE: R2Bucket;
	INSTALLATION: DurableObjectNamespace<Installation>;
	APOTHEKE: DurableObjectNamespace<ApothekeContainer>;
	/** The autofill service's HostedAutofill entrypoint, as on apotheke-api (src/autofill.ts). */
	AUTOFILL?: Fetcher;
	/** "1" lets the Workflow act; anything else refuses every instance (edge/wrangler.jsonc sets "1"). */
	OPS_WRITES_ENABLED?: string;
};

/** Runs before any step, also when an instance resumes: OPS_WRITES_ENABLED must be exactly "1", and the job's
 *  installation is refused as primary by its id and again by its installations row. Reads only: a refusal fails the
 *  instance (NonRetryableError) with nothing changed, so the job keeps its state. */
export async function preflight(env: LifecycleEnv, jobId: string): Promise<void> {
	if (env.OPS_WRITES_ENABLED !== "1") throw new LifecycleError("writes_disabled");
	const job = await env.DB.prepare("SELECT installation_id FROM lifecycle_jobs WHERE id = ?").bind(jobId).first<{ installation_id: string }>();
	if (!job) throw new LifecycleError("job_not_found");
	if (job.installation_id === INSTALLATION_NAME) throw new LifecycleError("refused");
	const row = await env.DB.prepare("SELECT id, do_name FROM installations WHERE id = ?").bind(job.installation_id).first<{ id: string; do_name: string }>();
	if (!row) throw new LifecycleError("not_found");
	if (row.id === INSTALLATION_NAME || row.do_name === INSTALLATION_NAME) throw new LifecycleError("refused");
}

export class LifecycleWorkflow extends WorkflowEntrypoint<LifecycleEnv, { jobId: string }> {
	async run(event: Readonly<WorkflowEvent<{ jobId: string }>>, step: WorkflowStep): Promise<void> {
		await preflight(this.env, event.payload.jobId);
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

/** Deletes an installation's autofill budget: DELETE /v1/installations/<do_name>/budget on HostedAutofill. 200 is done
 *  (with the number of records deleted). No binding, a call that did not finish and 5xx (503 when its storage failed)
 *  are retried; any other status, or a 200 without a usable count, fails the job as autofill_<status> or
 *  bad_autofill_answer. primary is refused before any call. */
export async function deleteAutofillBudget(autofill: Fetcher | undefined, doName: string): Promise<{ deleted: number }> {
	if (doName === INSTALLATION_NAME) throw new LifecycleError("refused");
	if (!autofill) throw new LifecycleRetry("autofill_not_configured");
	let res: Response;
	try {
		res = await autofill.fetch(`https://${AUTOFILL_HOST}/v1/installations/${encodeURIComponent(doName)}/budget`, { method: "DELETE" });
	} catch {
		throw new LifecycleRetry("autofill_unreachable");
	}
	if (res.status !== 200) {
		await res.body?.cancel();
		if (res.status >= 500) throw new LifecycleRetry(`autofill_${res.status}`);
		throw new LifecycleError(`autofill_${res.status}`);
	}
	const body = (await res.json().catch(() => null)) as { deleted?: unknown } | null;
	const deleted = body?.deleted;
	if (typeof deleted !== "number" || !Number.isSafeInteger(deleted) || deleted < 0) throw new LifecycleError("bad_autofill_answer");
	return { deleted };
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
		autofill: { delete: (doName) => deleteAutofillBudget(env.AUTOFILL, doName) },
	};
}
