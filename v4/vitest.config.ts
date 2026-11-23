import { cloudflareTest } from "@cloudflare/vitest-pool-workers";
import { defineConfig } from "vitest/config";

// Tests run inside real workerd (plan §3), so the runtime that ships is the runtime under test.
// `wrangler.jsonc` is the single source of bindings; a test never invents one.
export default defineConfig({
	plugins: [cloudflareTest({ wrangler: { configPath: "./wrangler.jsonc" } })],
	test: {
		include: ["test/**/*.spec.ts"],
	},
});
