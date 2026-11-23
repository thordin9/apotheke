/// <reference types="@cloudflare/vitest-pool-workers/types" />

// The bindings a test sees are the bindings wrangler.jsonc declares — no test-only binding can slip
// in — so the test type is the Worker's Env, merged into the ambient `Cloudflare.Env` that
// `cloudflare:workers` types `env` with.
import type { Env as ApothekeEnv } from "../src/env";

declare global {
	namespace Cloudflare {
		interface Env extends ApothekeEnv {}
	}
}
