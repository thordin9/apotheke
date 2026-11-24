// Generates v4's route table from the PHP app's own route artifact (plan §5 S0.5).
//
// The plan wants "a table in code, not a parser at runtime": this script runs at author time and in
// CI, never in the Worker. Its inputs are the two files the PHP app already maintains —
// `app/Config/RoutesCompiled.php` (what `php spark apotheke:routes` writes from `Routes.php`, and
// what `CompiledRoutesTest` keeps fresh) and `app/Config/Filters.php` (the filter chain) — plus
// `app/Config/Feature.php` for `oldFilterOrder`, because the chain's order depends on it.
//
// Outputs, both committed:
//   src/http/routes.generated.ts   the table the Worker imports
//   test/fixtures/php-routes.json  the same data as JSON, the oracle the workerd test compares to
//
// `--check` (what CI runs) regenerates in memory and fails when either committed file is stale, or
// when a source's sha256 differs from the one recorded — so editing Routes.php without re-running
// `php spark apotheke:routes`, or editing Filters.php at all, turns the job red instead of leaving a
// table that quietly disagrees with the app.
//
// Usage: node scripts/gen-routes.mjs [--check]

import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const here = (path) => fileURLToPath(new URL(path, import.meta.url));

const SOURCES = {
	routes: here("../../app/Config/Routes.php"),
	compiled: here("../../app/Config/RoutesCompiled.php"),
	filters: here("../../app/Config/Filters.php"),
	feature: here("../../app/Config/Feature.php"),
};

const OUT_TABLE = here("../src/http/routes.generated.ts");
const OUT_FIXTURE = here("../test/fixtures/php-routes.json");

/** The verbs a route can be declared for, in the order the compiled file lists them. */
const WEB_VERBS = ["GET", "HEAD", "POST", "PATCH", "PUT", "DELETE", "OPTIONS", "TRACE", "CONNECT"];

/* ------------------------------------------------------------------------------------------------
 * A reader for PHP's `var_export` output. RoutesCompiled.php is a plain `return array (...)` of
 * strings and arrays — no objects, no numbers in our case — so this is a tokenizer, not a PHP
 * interpreter, and it throws on anything it does not recognise rather than guessing.
 * ---------------------------------------------------------------------------------------------- */

const tokenizePhp = (source) => {
	const tokens = [];
	let i = 0;
	while (i < source.length) {
		const ch = source[i];
		if (ch === " " || ch === "\n" || ch === "\r" || ch === "\t") {
			i += 1;
			continue;
		}
		if (source.startsWith("array", i) && /[\s(]/.test(source[i + 5] ?? "")) {
			tokens.push({ kind: "array" });
			i += 5;
			continue;
		}
		if (ch === "(") {
			tokens.push({ kind: "open" });
			i += 1;
			continue;
		}
		if (ch === ")") {
			tokens.push({ kind: "close" });
			i += 1;
			continue;
		}
		if (source.startsWith("=>", i)) {
			tokens.push({ kind: "arrow" });
			i += 2;
			continue;
		}
		if (ch === ",") {
			tokens.push({ kind: "comma" });
			i += 1;
			continue;
		}
		if (ch === "'") {
			let value = "";
			i += 1;
			for (;;) {
				if (i >= source.length) {
					throw new Error("unterminated string in PHP array");
				}
				if (source[i] === "\\" && source[i + 1] === "'") {
					value += "'";
					i += 2;
					continue;
				}
				if (source[i] === "\\" && source[i + 1] === "\\") {
					value += "\\";
					i += 2;
					continue;
				}
				if (source[i] === "'") {
					i += 1;
					break;
				}
				value += source[i];
				i += 1;
			}
			tokens.push({ kind: "string", value });
			continue;
		}
		const number = /^-?\d+/.exec(source.slice(i));
		if (number) {
			tokens.push({ kind: "number", value: Number(number[0]) });
			i += number[0].length;
			continue;
		}
		throw new Error(`unexpected PHP token near: ${source.slice(i, i + 40)}`);
	}
	return tokens;
};

/** Parses `array ( ... )` at the cursor: string keys keep their string, integer keys stay numbers. */
const parseValue = (tokens, state) => {
	const token = tokens[state.i];
	if (!token) {
		throw new Error("PHP array ended early");
	}
	if (token.kind === "string") {
		state.i += 1;
		return token.value;
	}
	if (token.kind === "number") {
		state.i += 1;
		return token.value;
	}
	if (token.kind !== "array") {
		throw new Error(`expected array, got ${token.kind}`);
	}
	state.i += 1;
	if (tokens[state.i]?.kind !== "open") {
		throw new Error("expected ( after array");
	}
	state.i += 1;
	const entries = [];
	while (tokens[state.i]?.kind !== "close") {
		const key = parseValue(tokens, state);
		if (tokens[state.i]?.kind !== "arrow") {
			throw new Error("expected => in PHP array");
		}
		state.i += 1;
		const value = parseValue(tokens, state);
		entries.push([key, value]);
		if (tokens[state.i]?.kind === "comma") {
			state.i += 1;
		}
	}
	state.i += 1;
	const isList = entries.every(([key]) => typeof key === "number");
	return isList ? entries.map(([, value]) => value) : Object.fromEntries(entries);
};

const parsePhpArray = (source) => {
	const start = source.indexOf("return ");
	if (start < 0) {
		throw new Error("no `return` in the PHP file");
	}
	const tokens = tokenizePhp(source.slice(start + "return ".length).replace(/;\s*$/, ""));
	const state = { i: 0 };
	const value = parseValue(tokens, state);
	if (state.i !== tokens.length) {
		throw new Error("trailing tokens after the PHP array");
	}
	return value;
};

/* ------------------------------------------------------------------------------------------------
 * Config\Filters, read with a narrow scanner. `$required`, `$globals` and `$filters` are the three
 * arrays the chain comes from, and `$aliases` names every filter that may appear in them — so a
 * name in the chain that has no alias fails here rather than being ignored at runtime.
 * ---------------------------------------------------------------------------------------------- */

/** A PHP file with its comments removed, so bracket counting is not confused by prose. */
const stripComments = (source) => {
	let out = "";
	let i = 0;
	while (i < source.length) {
		const ch = source[i];
		if (ch === "'") {
			out += ch;
			i += 1;
			while (i < source.length && source[i] !== "'") {
				if (source[i] === "\\") {
					out += source[i];
					i += 1;
				}
				out += source[i];
				i += 1;
			}
			out += "'";
			i += 1;
			continue;
		}
		if (ch === "/" && source[i + 1] === "/") {
			while (i < source.length && source[i] !== "\n") {
				i += 1;
			}
			continue;
		}
		if (ch === "/" && source[i + 1] === "*") {
			const end = source.indexOf("*/", i + 2);
			i = end < 0 ? source.length : end + 2;
			continue;
		}
		if (ch === "#") {
			while (i < source.length && source[i] !== "\n") {
				i += 1;
			}
			continue;
		}
		out += ch;
		i += 1;
	}
	return out;
};

/** The text of the array that starts at or after `from`, brackets balanced. */
const arrayAfter = (source, from, label) => {
	const start = source.indexOf("[", from);
	if (start < 0) {
		throw new Error(`${label}: no array found`);
	}
	let depth = 0;
	for (let i = start; i < source.length; i += 1) {
		if (source[i] === "[") {
			depth += 1;
		} else if (source[i] === "]") {
			depth -= 1;
			if (depth === 0) {
				return source.slice(start + 1, i);
			}
		}
	}
	throw new Error(`${label}: unbalanced brackets`);
};

/** The text of a balanced `[ ... ]` starting at `at`. */
const balanced = (text, at, label) => {
	let depth = 0;
	for (let i = at; i < text.length; i += 1) {
		if (text[i] === "[") {
			depth += 1;
		} else if (text[i] === "]") {
			depth -= 1;
			if (depth === 0) {
				return text.slice(at + 1, i);
			}
		}
	}
	throw new Error(`${label}: unbalanced brackets`);
};

/** A list body of bare quoted strings: `'a', 'b'` → ["a", "b"]. Anything else is an error. */
const quotedList = (text, label) => {
	const names = [];
	for (const match of text.matchAll(/'([^']*)'|([^\s,])/g)) {
		if (match[2] !== undefined) {
			throw new Error(`${label}: unexpected token ${JSON.stringify(match[2])}`);
		}
		names.push(match[1]);
	}
	return names;
};

/** `'name'` or `'name' => [ ... ]` entries, in order; anything else is an error. */
const arrayEntries = (text, label) => {
	const entries = [];
	const entry = /\s*(?:'([^']*)'\s*(?:=>\s*(?=\[))?)?/y;
	let i = 0;
	while (i < text.length) {
		entry.lastIndex = i;
		const match = entry.exec(text);
		if (!match || match[1] === undefined) {
			if (text.slice(i).trim() === "") {
				break;
			}
			throw new Error(`${label}: unexpected token ${JSON.stringify(text.slice(i, i + 20))}`);
		}
		const name = match[1];
		i = entry.lastIndex;
		let body = null;
		if (text[i] === "[") {
			const inner = balanced(text, i, `${label}.${name}`);
			i += inner.length + 2;
			body = inner;
		}
		const rest = /^\s*,?/.exec(text.slice(i));
		i += rest ? rest[0].length : 0;
		entries.push({ name, body });
	}
	return entries;
};

const globalEntries = (text, label) =>
	arrayEntries(text, label).map((entry) => {
		if (entry.body === null) {
			return { name: entry.name };
		}
		const options = arrayEntries(entry.body, `${label}.${entry.name}`);
		if (options.length !== 1 || options[0].name !== "except" || options[0].body === null) {
			throw new Error(`${label}: ${entry.name} has options this scanner does not know`);
		}
		return { name: entry.name, except: quotedList(options[0].body, `${label}.${entry.name}.except`) };
	});

const readFilters = (source) => {
	const clean = stripComments(source);
	const aliasesAt = clean.indexOf("$aliases");
	if (aliasesAt < 0) {
		throw new Error("Config\\Filters: no $aliases");
	}
	const aliasBody = balanced(clean, clean.indexOf("[", aliasesAt), "$aliases");
	const aliases = [...aliasBody.matchAll(/'([^']+)'\s*=>/g)].map((match) => match[1]);
	const section = (name) => {
		const at = clean.indexOf(`$${name}`);
		if (at < 0) {
			throw new Error(`Config\\Filters: no $${name}`);
		}
		return balanced(clean, clean.indexOf("[", at), `$${name}`);
	};

	const required = section("required");
	const requiredBefore = quotedList(
		arraySection(required, "before", "$required"),
		"$required.before",
	);
	const requiredAfter = quotedList(arraySection(required, "after", "$required"), "$required.after");

	const globals = section("globals");
	const globalsBefore = globalEntries(arraySection(globals, "before", "$globals"), "$globals.before");
	const globalsAfter = globalEntries(arraySection(globals, "after", "$globals"), "$globals.after");

	const pathBody = section("filters");
	const pathFilters = arrayEntries(pathBody, "$filters").map((entry) => {
		if (entry.body === null) {
			throw new Error(`$filters.${entry.name}: expected an array`);
		}
		const options = arrayEntries(entry.body, `$filters.${entry.name}`);
		if (options.length !== 1 || options[0].name !== "before" || options[0].body === null) {
			throw new Error(`$filters.${entry.name}: only a single \`before\` list is read`);
		}
		return { name: entry.name, before: quotedList(options[0].body, `$filters.${entry.name}.before`) };
	});

	const used = [
		...requiredBefore,
		...requiredAfter,
		...globalsBefore.map((entry) => entry.name),
		...globalsAfter.map((entry) => entry.name),
		...pathFilters.map((entry) => entry.name),
	];
	for (const name of used) {
		if (!aliases.includes(name)) {
			throw new Error(`Config\\Filters: ${name} is used but has no alias`);
		}
	}

	return { aliases, requiredBefore, requiredAfter, globalsBefore, globalsAfter, pathFilters };
};

/** `$required['before']` and friends: the balanced array after `'before' =>`. */
function arraySection(text, key, label) {
	const pattern = new RegExp(`'${key}'\\s*=>`);
	const match = pattern.exec(text);
	if (!match) {
		throw new Error(`${label}: no ${key}`);
	}
	const at = text.indexOf("[", match.index + match[0].length - 1);
	if (at < 0) {
		throw new Error(`${label}: no array after ${key}`);
	}
	return balanced(text, at, `${label}.${key}`);
}

const readOldFilterOrder = (source) => {
	const match = /oldFilterOrder\s*=\s*(true|false)/.exec(stripComments(source));
	if (!match) {
		throw new Error("Config\\Feature: no oldFilterOrder");
	}
	return match[1] === "true";
};

/* ------------------------------------------------------------------------------------------------
 * The chain a request path runs, exactly as CI4 4.x builds it with oldFilterOrder = false:
 * required → globals (minus their `except`) → per-path filters for `before`, and globals → required
 * for `after` (CodeIgniter::runRequiredAfterFilters runs last).
 * ---------------------------------------------------------------------------------------------- */

const ci4Uri = (path) => decodeURIComponent(path).replace(/^\/+|\/+$/g, "").toLowerCase();

const pseudoRegex = (pattern) => {
	const source = pattern
		.replace(/^\/|\/$/g, "")
		.replace(/\//g, "\\/")
		.toLowerCase()
		.replace(/\*/g, ".*");
	return new RegExp(`^${source}$`, "u");
};

const pathApplies = (uri, patterns) =>
	patterns.length === 0 || patterns.some((pattern) => pseudoRegex(pattern).test(uri));

const chainFor = (path, filters) => {
	const uri = ci4Uri(path);
	return [
		...filters.requiredBefore,
		...filters.globalsBefore.filter((entry) => !entry.except || !pathApplies(uri, entry.except)).map((entry) => entry.name),
		...filters.pathFilters.filter((entry) => pathApplies(uri, entry.before)).map((entry) => entry.name),
	];
};

const afterFor = (filters) => [...filters.globalsAfter.map((entry) => entry.name), ...filters.requiredAfter];

/* ------------------------------------------------------------------------------------------------
 * Build
 * ---------------------------------------------------------------------------------------------- */

const sha256 = (path) => createHash("sha256").update(readFileSync(path)).digest("hex");

const build = () => {
	const compiled = parsePhpArray(readFileSync(SOURCES.compiled, "utf8"));
	const filters = readFilters(readFileSync(SOURCES.filters, "utf8"));
	const oldFilterOrder = readOldFilterOrder(readFileSync(SOURCES.feature, "utf8"));
	if (oldFilterOrder) {
		throw new Error("Config\\Feature::$oldFilterOrder is true: the chain order in chain.ts assumes false");
	}

	const routes = [];
	for (const verb of WEB_VERBS) {
		const declared = compiled.routes?.[verb] ?? {};
		const options = compiled.routesOptions?.[verb] ?? {};
		for (const [path, route] of Object.entries(declared)) {
			const permission = options[path]?.permission ?? null;
			if (permission === null) {
				// Fail closed, as PermissionFilter does: a route without the option is refused to everyone.
				// Kept visible in the table rather than defaulted here.
			}
			routes.push({
				method: verb,
				path,
				name: route.name ?? "",
				handler: route.handler ?? "",
				permission,
				catchAll: (route.handler ?? "").endsWith("ApiController::notFound"),
				filters: chainFor(path, filters),
			});
		}
	}

	const chains = [];
	const chainIndex = new Map();
	for (const route of routes) {
		const key = route.filters.join(",");
		if (!chainIndex.has(key)) {
			chainIndex.set(key, chains.length);
			chains.push(route.filters);
		}
	}

	return {
		oldFilterOrder,
		filters,
		after: afterFor(filters),
		chains,
		chainIndex,
		routes,
		sources: Object.fromEntries(
			Object.entries(SOURCES).map(([name, path]) => [name, { path: path.replace(`${here("../../")}`, ""), sha256: sha256(path) }]),
		),
	};
};

const exportTable = (model) => {
	const lines = [];
	lines.push("// Generated by `node scripts/gen-routes.mjs` from the PHP app's route artifact.");
	lines.push("// Do not edit: `npm run routes:check` (and v4.yml) fails when this file is stale.");
	lines.push("//");
	lines.push("// Sources, as they were when this was written — the check compares these hashes:");
	for (const [name, source] of Object.entries(model.sources)) {
		lines.push(`//   ${name}: ${source.path} sha256 ${source.sha256}`);
	}
	lines.push("//");
	lines.push("// `filters` is an index into CHAINS: the before-filters a request on that path runs, in");
	lines.push("// CI4's own order (required, then globals minus their `except`, then per-path filters).");
	lines.push("");
	lines.push("/** A route as the PHP app declares it: one row per method, exactly as RoutesCompiled.php has it. */");
	lines.push("export interface RouteRow {");
	lines.push("\treadonly method: string;");
	lines.push("\treadonly path: string;");
	lines.push("\treadonly name: string;");
	lines.push("\treadonly handler: string;");
	lines.push("\treadonly permission: string | null;");
	lines.push("\t/** The /api fallback (ApiController::notFound): answered by dispatch, never a 405 candidate. */");
	lines.push("\treadonly catchAll: boolean;");
	lines.push("\treadonly filters: number;");
	lines.push("}");
	lines.push("");
	lines.push("export const ROUTES: readonly RouteRow[] = [");
	for (const route of model.routes) {
		const permission = route.permission === null ? "null" : JSON.stringify(route.permission);
		lines.push(
			`\t{ method: ${JSON.stringify(route.method)}, path: ${JSON.stringify(route.path)}, name: ${JSON.stringify(route.name)}, handler: ${JSON.stringify(route.handler)}, permission: ${permission}, catchAll: ${route.catchAll}, filters: ${model.chainIndex.get(route.filters.join(","))} },`,
		);
	}
	lines.push("];");
	lines.push("");
	lines.push("/** Every distinct before-chain in the table, so a route names one instead of repeating it. */");
	lines.push("export const CHAINS: readonly (readonly string[])[] = [");
	for (const chain of model.chains) {
		lines.push(`\t[${chain.map((name) => JSON.stringify(name)).join(", ")}],`);
	}
	lines.push("];");
	lines.push("");
	return `${lines.join("\n")}`;
};

const fixture = (model) =>
	`${JSON.stringify(
		{
			note: "Generated by v4/scripts/gen-routes.mjs from the PHP app. Do not edit.",
			sources: model.sources,
			oldFilterOrder: model.oldFilterOrder,
			chain: {
				requiredBefore: model.filters.requiredBefore,
				requiredAfter: model.filters.requiredAfter,
				globalsBefore: model.filters.globalsBefore,
				globalsAfter: model.filters.globalsAfter,
				pathFilters: model.filters.pathFilters,
				after: model.after,
			},
			chains: model.chains,
			routes: model.routes.map((route) => ({
				method: route.method,
				path: route.path,
				name: route.name,
				handler: route.handler,
				permission: route.permission,
				catchAll: route.catchAll,
				filters: route.filters,
			})),
		},
		null,
		"\t",
	)}\n`;

const main = () => {
	const model = build();
	const table = exportTable(model);
	const fixtureText = fixture(model);

	if (process.argv.includes("--check")) {
		const stale = [];
		for (const [path, text] of [
			[OUT_TABLE, table],
			[OUT_FIXTURE, fixtureText],
		]) {
			let current = "";
			try {
				current = readFileSync(path, "utf8");
			} catch {
				current = "";
			}
			if (current !== text) {
				stale.push(path);
			}
		}
		if (stale.length > 0) {
			console.error(`Route table is out of date: ${stale.join(", ")}`);
			console.error("Run `node scripts/gen-routes.mjs` in v4/ after `php spark apotheke:routes` in the app root.");
			process.exitCode = 1;
			return;
		}
		console.log(`Route table is up to date: ${model.routes.length} routes, ${model.chains.length} chains.`);
		return;
	}

	writeFileSync(OUT_TABLE, table);
	writeFileSync(OUT_FIXTURE, fixtureText);
	console.log(`Wrote ${model.routes.length} routes in ${model.chains.length} chains.`);
};

main();
