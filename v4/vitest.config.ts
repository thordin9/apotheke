import { cloudflareTest } from "@cloudflare/vitest-pool-workers";
import { defineConfig } from "vitest/config";

// Tests run inside real workerd (plan §3), so the runtime that ships is the runtime under test.
// `wrangler.jsonc` is the single source of bindings; a test never invents one. The two rqlite values
// are already declared there — this only carries in the real node's address when one is provided, so
// `RQLITE_URL=... npm test` runs the live specs (`rqlite-live.spec.ts`) that `v4.yml`'s rqlite job
// requires, and an ordinary run skips them.
const fromEnvironment = (name: string): string => {
	const proc = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process;
	return proc?.env?.[name] ?? "";
};

export default defineConfig({
	plugins: [
		cloudflareTest({
			wrangler: { configPath: "./wrangler.jsonc" },
			miniflare: {
				bindings: { RQLITE_URL: fromEnvironment("RQLITE_URL"), RQLITE_AUTH: fromEnvironment("RQLITE_AUTH") },
			},
		}),
	],
	test: {
		include: ["test/**/*.spec.ts"],
	},
});
