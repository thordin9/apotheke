// Module boundaries for v4, mirroring the deptrac graph the PHP app is held to (plan §3).
// The tree is one file deep today; these rules exist so the first real module cannot cross a line
// that would have to be undone later.
//
// `parser: "swc"` is not a preference: dependency-cruiser 18 supports TypeScript < 7.0 or >= 7.1, and
// the workspace is on 7.0.2 (the version `edge/` pins). Without a transpiler it cruises zero modules
// and every rule passes vacuously, which is worse than no rule.
/** @type {import('dependency-cruiser').IConfiguration} */
module.exports = {
	forbidden: [
		{
			name: "no-circular",
			severity: "error",
			comment: "A cycle means the layer split is wrong, not that the cycle is convenient.",
			from: {},
			to: { circular: true },
		},
		{
			name: "no-unresolvable",
			severity: "error",
			comment: "An import that does not resolve is a broken build that typecheck may still pass.",
			from: {},
			to: { couldNotResolve: true },
		},
		{
			name: "test-code-not-imported-by-src",
			severity: "error",
			comment: "src never imports test; test imports src.",
			from: { path: "^src/" },
			to: { path: "^test/" },
		},
		{
			name: "no-dev-dependencies-in-src",
			severity: "error",
			comment: "devDependencies do not exist in the deployed bundle.",
			from: { path: "^src/" },
			to: { dependencyTypes: ["npm-dev"] },
		},
	],
	options: {
		doNotFollow: { path: "node_modules" },
		parser: "swc",
		exclude: { path: "\\.spec\\.ts$" },
		tsConfig: { fileName: "tsconfig.json" },
		enhancedResolveOptions: { exportsFields: ["exports"], conditionNames: ["import", "node", "default"] },
	},
};
