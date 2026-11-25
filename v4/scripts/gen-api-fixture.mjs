#!/usr/bin/env node
// The /api/v1 session fixture's other half (plan §5 S1.4): `gen-api-fixture.sh` has already booted v3
// and captured each answer with `curl`; this turns the captures into `test/fixtures/php-api.json`.
//
//   node scripts/gen-api-fixture.mjs write <capture-dir> <out.json> <root> <php> <settings-json>
//        <pacing-json> <password> <sources…>
//   node scripts/gen-api-fixture.mjs check <fixture.json> <root>      (alias: check-session-fixture.mjs)
//
// What is normalised, and nothing else:
//
//   * `csrf_token` — 64 random hex that a client is meant to read and repeat. It becomes `<csrf>`.
//   * the session cookie's value — 32 random bytes; it becomes `<id>`. The cookie's name, flags and
//     `Max-Age` are *not* touched (S1.2's fixture already pins the shape), and neither is
//     `Expires`, whose value becomes `<date>` because it is `now + 30 days`.
//
// Everything else is stored as the bytes PHP sent, including the key order of every JSON body: the
// six keys of `state()` and the four of `account()` are the slice's DoD, and a comparison of parsed
// objects would pass on a body that spells them in another order.
//
// The OpenAPI document is read here too, and its four components are copied into the fixture: the
// contract test then compares v3's answers *and* v4's against the same schema without either side
// hand-typing it. `docs/api/openapi.yaml` is in the fixture's `sources`, so editing the document
// without regenerating fails `npm run fixtures:check`.

import { createHash } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const [, , mode, ...args] = process.argv;
const sha256 = (bytes) => createHash("sha256").update(bytes).digest("hex");

/** The headers the fixture pins: the API's own security set, and the two conditional ones. */
const PINNED = [
	"content-type",
	"cache-control",
	"retry-after",
	"content-security-policy",
	"x-content-type-options",
	"referrer-policy",
	"cross-origin-resource-policy",
	"cross-origin-opener-policy",
	"strict-transport-security",
];

const CSRF = /([0-9a-f]{64})/;
const SESSION_VALUE = /((?:__Host-)?apothekesid)=[^;]+/;
const EXPIRES = /expires=[^;]+/i;

/** The session id and the token out, a placeholder in. */
const normaliseBody = (body) => body.replace(/"csrf_token":"([0-9a-f]{64})"/, '"csrf_token":"<csrf>"');

const normaliseCookie = (line) =>
	line.replace(SESSION_VALUE, "$1=<id>").replace(EXPIRES, "expires=<date>");

const readHeaders = (path) => {
	const text = readFileSync(path, "utf8");
	const pinned = {};
	const cookies = [];
	for (const line of text.split(/\r?\n/)) {
		const at = line.indexOf(":");
		if (at === -1) {
			continue;
		}
		const name = line.slice(0, at).trim().toLowerCase();
		const value = line.slice(at + 1).trim();
		if (name === "set-cookie") {
			cookies.push(normaliseCookie(value));
		} else if (PINNED.includes(name)) {
			pinned[name] = value;
		}
	}
	const sorted = Object.fromEntries(Object.entries(pinned).sort(([a], [b]) => a.localeCompare(b)));
	return cookies.length === 0 ? sorted : { ...sorted, "set-cookie": cookies };
};

const write = ([dir, out, root, php, settingsJson, pacingJson, password, ...sources]) => {
	const steps = readFileSync(join(dir, "steps.jsonl"), "utf8")
		.split("\n")
		.filter((line) => line.trim() !== "")
		.map((line) => {
			const step = JSON.parse(line);
			const body = readFileSync(join(dir, `${step.name}.body`), "utf8");
			const normalised = normaliseBody(body);
			// Every token in the answer is `<csrf>`; a leftover 64-hex value would make the fixture
			// fail on the next run for a reason that is not a contract change.
			if (CSRF.test(normalised.replace(/"csrf_token":"<csrf>"/, ""))) {
				throw new Error(`${step.name}: a CSRF-shaped value survived normalisation`);
			}
			return {
				...step,
				response: {
					status: Number((readFileSync(join(dir, `${step.name}.headers`), "utf8").match(/^HTTP\/\S+ (\d+)/) ?? [])[1]),
					headers: readHeaders(join(dir, `${step.name}.headers`)),
					body: normalised,
				},
			};
		});

	const openapiPath = join(root, "docs/api/openapi.yaml");
	const openapi = JSON.parse(readFileSync(openapiPath, "utf8"));
	const components = openapi.components ?? {};
	const contract = {
		schemas: {
			Session: components.schemas?.Session,
			Me: components.schemas?.Me,
			Problem: components.schemas?.Problem,
			Permissions: components.schemas?.Permissions,
		},
		parameters: { CsrfToken: components.parameters?.CsrfToken },
	};
	for (const [name, value] of Object.entries(contract.schemas)) {
		if (value === undefined) {
			throw new Error(`docs/api/openapi.yaml has no components.schemas.${name}`);
		}
	}
	if (contract.parameters.CsrfToken === undefined) {
		throw new Error("docs/api/openapi.yaml has no components.parameters.CsrfToken");
	}

	const fixture = {
		generatedBy: "v4/scripts/gen-api-fixture.sh",
		php,
		note:
			"Raw answers of v3's four /api/v1 session routes, captured over HTTP from a migrated and seeded SQLite install. " +
			"Pinned: status, the API's own headers (content-type, cache-control, retry-after, set-cookie, the security set) " +
			"and the body bytes. Normalised, and only these: csrf_token -> <csrf>, the session cookie's value -> <id>, " +
			"expires -> <date>. PHP's own Expires/Pragma, sent because session_start() runs, are not part of the API " +
			"contract and are not pinned. `setup` is what the capture changed first: a settings row written the way the " +
			"settings page writes it, or an account whose password is `password`.",
		appPassword: password,
		sources: Object.fromEntries(sources.map((file) => [file, sha256(readFileSync(join(root, file)))])),
		settings: JSON.parse(settingsJson),
		pacing: JSON.parse(pacingJson),
		contract,
		steps,
	};
	writeFileSync(out, `${JSON.stringify(fixture, null, "\t")}\n`);
	console.log(
		`wrote ${out}: ${steps.length} answers, ${Object.keys(fixture.sources).length} sources, PHP ${php}`,
	);
};

const check = ([fixturePath, root]) => {
	const fixture = JSON.parse(readFileSync(fixturePath, "utf8"));
	const changed = [];
	for (const [file, expected] of Object.entries(fixture.sources)) {
		const full = join(root, file);
		if (!existsSync(full)) {
			changed.push(`${file}: missing`);
			continue;
		}
		if (sha256(readFileSync(full)) !== expected) {
			changed.push(`${file}: changed`);
		}
	}
	if (changed.length > 0) {
		console.error("The /api fixture is stale — regenerate it on a box with PHP 8.4:");
		console.error("    v4/scripts/gen-api-fixture.sh");
		for (const line of changed) {
			console.error(`  ${line}`);
		}
		process.exit(1);
	}
	console.log(`The /api fixture is current: ${Object.keys(fixture.sources).length} sources unchanged.`);
};

if (mode === "write") {
	write(args);
} else if (mode === "check") {
	check(args);
} else {
	console.error(
		"usage: gen-api-fixture.mjs write <dir> <out> <root> <php> <settings> <pacing> <password> <sources…> | check <fixture> <root>",
	);
	process.exit(2);
}
