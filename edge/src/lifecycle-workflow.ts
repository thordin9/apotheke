// The Workflow that runs lifecycle jobs (src/lifecycle.ts). It is not part of the apotheke-api Worker: index.ts does not
// export it and wrangler.jsonc has no workflow, cron or binding for it. The operator Worker (apotheke-ops) deploys it
// later with the bindings below. An instance is created per confirmed job, with the job id as the instance id.
import { WorkflowEntrypoint, type WorkflowEvent, type WorkflowStep } from "cloudflare:workers";
import type { ApothekeContainer } from "./index";
import { installationStub, type Installation } from "./installation";
import { LifecycleError, runJob, type LifecycleDeps } from "./lifecycle";

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
			// BOOTSTRAP=1 start and the first-admin claim come with C1; the backup call with the bridge endpoint for it.
			bootstrap: pending("pending_c1"),
			backup: pending("pending_backup_endpoint"),
		},
		claim: { create: pending("pending_c1") },
		// Routes are cached only inside each apotheke-api isolate, for at most 30 seconds (route.ts): nothing to purge here.
		routeCache: { purge: async () => {} },
	};
}
