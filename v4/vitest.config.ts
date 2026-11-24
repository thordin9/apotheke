import { cloudflareTest } from "@cloudflare/vitest-pool-workers";
import { defineConfig } from "vitest/config";

// Tests run inside real workerd (plan §3), so the runtime that ships is the runtime under test.
// `wrangler.jsonc` is the single source of bindings; a test never invents one. The two rqlite values
// are already declared there — this only carries in the real node's address when one is provided, so
// `RQLITE_URL=... npm test` runs the live specs (`rqlite-live.spec.ts`) that `v4.yml`'s rqlite job
// requires, and an ordinary run skips them.
//
// `DB_DRIVER` is carried the same way, and only when it is set: `v4.yml`'s sqlite job names the
// backend it proves instead of inheriting `wrangler.jsonc`'s default, and `health.spec.ts` still
// asserts what the Worker *resolved* — the env var is how the leg is selected, not its evidence.
const fromEnvironment = (name: string): string => {
	const proc = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process;
	return proc?.env?.[name] ?? "";
};

const bindings = (): Record<string, string> => {
	const driver = fromEnvironment("DB_DRIVER");

	return {
		RQLITE_URL: fromEnvironment("RQLITE_URL"),
		RQLITE_AUTH: fromEnvironment("RQLITE_AUTH"),
		...(driver === "" ? {} : { DB_DRIVER: driver }),
	};
};

export default defineConfig({
	plugins: [
		cloudflareTest({
			wrangler: { configPath: "./wrangler.jsonc" },
			miniflare: { bindings: bindings() },
		}),
	],
	test: {
		include: ["test/**/*.spec.ts"],
	},
});
