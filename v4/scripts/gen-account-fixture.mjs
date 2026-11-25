#!/usr/bin/env node
// The /account fixture's other half (plan §5 S1.5): `gen-account-fixture.sh` has already booted v3 and
// captured each answer with `curl`; this turns the captures into `test/fixtures/php-account.json`.
//
//   node scripts/gen-account-fixture.mjs write <capture-dir> <out.json> <root> <php> <password>
//        <newPassword> <sources…>
//
// What is normalised, and nothing else:
//
//   * every CSRF token — 64 random hex a client reads from the page it was served and repeats in the
//     form. It becomes `<csrf>`, in the response bodies and in the recorded request bodies, so the
//     replay substitutes its own token and the comparison substitutes it back.
//   * the session cookie's value, which becomes `<id>`, and its `Expires`, which becomes `<date>`.
//   * the asset build's cache key (`?v=`), which becomes `<hash>`: it fingerprints the built files,
//     which `assets:check` pins on this side, not the page. The login golden fixture normalises the
//     same value for the same reason.
//
// Everything else is stored as the bytes PHP sent. The page is one document — shell, header, menu,
// account card and toast — so its bytes are the contract this slice is about.
//
// The token is the one thing the replay cannot learn from a normalised body, which is why a step's
// request body carries `<csrf>` and the *client* (the test) fills it in from the live page it just
// rendered. That is exactly what a browser does.

import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const [, , mode, ...args] = process.argv;
const sha256 = (bytes) => createHash("sha256").update(bytes).digest("hex");

/** The headers the fixture pins. The page shell's security set is asserted by the chain tests. */
const PINNED = ["content-type", "cache-control", "location", "retry-after"];

const TOKEN = /[0-9a-f]{64}/g;
// The asset cache key (`/assets/app.css?v=<md5>`): PHP's own fingerprint of the built file, which
// `assets:check` pins on this side. The page's contract is the markup, not the build hash.
const ASSET_VERSION = /\?v=[0-9a-f]{8}/g;
const SESSION_VALUE = /((?:__Host-)?apothekesid)=[^;]+/;
const EXPIRES = /expires=[^;]+/i;

const normalise = (text) => text.replace(TOKEN, "<csrf>").replace(ASSET_VERSION, "?v=<hash>");
const normaliseCookie = (line) => line.replace(SESSION_VALUE, "$1=<id>").replace(EXPIRES, "expires=<date>");

const readHeaders = (path) => {
	const text = readFileSync(path, "utf8");
	const status = Number((text.match(/^HTTP\/\S+ (\d+)/) ?? [])[1]);
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
	return { status, headers: cookies.length === 0 ? sorted : { ...sorted, "set-cookie": cookies } };
};

const write = ([dir, out, root, php, password, newPassword, settingsJson, ...sources]) => {
	const steps = readFileSync(join(dir, "steps.jsonl"), "utf8")
		.split("\n")
		.filter((line) => line.trim() !== "")
		.map((line) => {
			const step = JSON.parse(line);
			const body = normalise(readFileSync(join(dir, `${step.name}.body`), "utf8"));
			const request = {
				...step.request,
				body: step.request.body === null ? null : normalise(step.request.body),
			};
			// A token that survived would make the next run differ for a reason that is not a
			// contract change — unless the document legitimately contains 64 hex characters that
			// are not a token, which would need this list to grow, not the regex to shrink.
			const leftover = body.match(TOKEN);
			if (leftover !== null) {
				throw new Error(`${step.name}: a token-shaped value survived normalisation: ${leftover[0]}`);
			}
			return { ...step, request, response: { ...readHeaders(join(dir, `${step.name}.headers`)), body } };
		});

	const fixture = {
		generatedBy: "v4/scripts/gen-account-fixture.sh",
		php,
		note:
			"Raw answers of v3's /account page, its POST and POST /account/signout, captured over HTTP from a migrated " +
			"and seeded SQLite install, with two sessions of one viewer. Pinned: status, content-type, cache-control, " +
			"location, retry-after, set-cookie (normalised) and the body bytes. Normalised, and only these: every CSRF " +
			"token -> <csrf>, the session cookie's value -> <id>, expires -> <date>. `/account?done=pw|out` is the same " +
			"page with a toast; `setup` is what the capture changed first.",
		appPassword: password,
		newPassword,
		accounts: [
			{ username: "admin", role: "admin", status: "active" },
			{ username: "viewer", role: "viewer", status: "active" },
		],
		sources: Object.fromEntries(sources.map((file) => [file, sha256(readFileSync(join(root, file)))])),
		settings: JSON.parse(settingsJson),
		steps,
	};
	writeFileSync(out, `${JSON.stringify(fixture, null, "\t")}\n`);
	console.log(
		`wrote ${out}: ${steps.length} answers, ${Object.keys(fixture.sources).length} sources, PHP ${php}`,
	);
};

if (mode === "write") {
	write(args);
} else {
	console.error(
		"usage: gen-account-fixture.mjs write <dir> <out> <root> <php> <password> <newPassword> <settings-json> <sources…>",
	);
	process.exit(2);
}
