// The platform adapters (plan §5 S0.3): config, clock, passwords, secrets, blobs. Each one is a seam
// the app is written against, so each one is tested against the PHP behaviour it replaces rather
// than against itself.

import { env } from "cloudflare:workers";
import { describe, expect, it } from "vitest";
import { R2Blobs } from "../src/platform/blobs";
import { clockFrom, fixedClock, formatUtc, systemClock } from "../src/platform/clock";
import { CONFIG_DEFAULTS, loadConfig } from "../src/platform/config";
import { BCRYPT_COST, hashPassword, verifyPassword } from "../src/platform/passwords";
import { decodeKey, ENVELOPE, isSealed, Secrets } from "../src/platform/secrets";
import secretbox from "./fixtures/secretbox.json";

describe("config loader", () => {
	it("uses the PHP defaults when nothing is set", () => {
		expect(loadConfig({})).toEqual(CONFIG_DEFAULTS);
	});

	it("ignores an empty variable, as config_load() does", () => {
		expect(loadConfig({ PAGE_SIZE: "", CURRENCY: "  ", APP_PASSWORD: "" })).toEqual(CONFIG_DEFAULTS);
	});

	it("normalises the values a settings default reads", () => {
		const config = loadConfig({
			PUBLIC_URL: "https://apotheke.example/",
			CURRENCY: "  EURONEXTRA ",
			PAGE_SIZE: "12abc",
			MAX_UPLOAD_MB: "0",
			EXPIRY_SOON_DAYS: "9999",
			APOTHEKE_HOSTED: "true",
		});
		expect(config.publicUrl).toBe("https://apotheke.example");
		// mb_substr(trim(...), 0, 8): eight of "EURONEXTRA"'s nine code points survive.
		expect(config.currency).toBe("EURONEXT");
		expect(config.pageSize).toBe(12);
		expect(config.uploads.maxMb).toBe(1);
		expect(config.expirySoonDays).toBe(3650);
		expect(config.hosted).toBe(true);
	});
});

describe("clock", () => {
	it("stores PHP's gmdate form", () => {
		expect(formatUtc(new Date("2026-11-23T12:00:00Z"))).toBe("2026-11-23 12:00:00");
	});

	it("is pinned by APOTHEKE_CLOCK and falls back on a bad value", () => {
		const fixed = clockFrom("2026-11-23T12:00:00Z");
		expect(fixed.stamp()).toBe("2026-11-23 12:00:00");
		expect(clockFrom("not-a-date")).toBe(systemClock);
		expect(clockFrom("")).toBe(systemClock);
		expect(fixedClock(new Date("2020-01-02T03:04:05Z")).stamp()).toBe("2020-01-02 03:04:05");
	});
});

describe("password hashes", () => {
	it("stays $2y$ bcrypt at PHP 8.4's default cost", async () => {
		const hash = await hashPassword("correct horse battery staple 2026");
		expect(hash).toMatch(new RegExp(`^\\$2y\\$${BCRYPT_COST}\\$`));
		expect(await verifyPassword("correct horse battery staple 2026", hash)).toBe(true);
		expect(await verifyPassword("wrong", hash)).toBe(false);
	});

	it("verifies a hash PHP wrote", async () => {
		// password_hash('correct horse battery staple 2026', PASSWORD_DEFAULT) on PHP 8.4.23.
		const php = "$2y$12$w70.szK6jAplI59bYkxX4uql1wlX6gqxwLzC.JoITqzJCAJdL299y";
		expect(await verifyPassword("correct horse battery staple 2026", php)).toBe(true);
	});
});

describe("secrets", () => {
	const nonce = () => Buffer.from(secretbox.nonce_base64, "base64");

	it("reads an enc:v1: value and writes the same bytes back", async () => {
		const secrets = await Secrets.create(secretbox.key_base64, nonce);
		expect(secrets.usable).toBe(true);
		expect(isSealed(secretbox.sealed)).toBe(true);
		expect(secrets.open(secretbox.sealed)).toBe(secretbox.plain);
		expect(secrets.seal(secretbox.plain)).toBe(secretbox.sealed);
	});

	it("round-trips with a real nonce", async () => {
		const secrets = await Secrets.create(secretbox.key_base64);
		const sealed = secrets.seal("another secret");
		expect(sealed.startsWith(ENVELOPE)).toBe(true);
		expect(secrets.open(sealed)).toBe("another secret");
		expect(secrets.seal("another secret")).not.toBe(sealed);
	});

	it("returns the value itself when it was never sealed, and '' when it will not open", async () => {
		const secrets = await Secrets.create(secretbox.key_base64);
		expect(secrets.open("plain-legacy-value")).toBe("plain-legacy-value");
		expect(secrets.seal("")).toBe("");
		expect(secrets.open(`${ENVELOPE}garbage`)).toBe("");
		const other = await Secrets.create(Buffer.alloc(32, 7).toString("base64"));
		expect(other.open(secretbox.sealed)).toBe("");
	});

	it("keeps the plaintext when no key is configured, as PHP does", async () => {
		const secrets = await Secrets.create(undefined);
		expect(secrets.usable).toBe(false);
		expect(secrets.seal("placeholder-secret")).toBe("placeholder-secret");
		expect(secrets.open(secretbox.sealed)).toBe("");
	});

	it("accepts only 32 decoded bytes, with or without the base64: prefix", () => {
		expect(decodeKey(secretbox.key_base64)?.length).toBe(32);
		expect(decodeKey(` base64:${secretbox.key_base64}\n`)?.length).toBe(32);
		expect(decodeKey(Buffer.alloc(16).toString("base64"))).toBeNull();
		expect(decodeKey("not-a-key")).toBeNull();
		expect(decodeKey("")).toBeNull();
		expect(decodeKey(undefined)).toBeNull();
	});
});

describe("blobs", () => {
	it("keeps the PHP key layout and round-trips a file", async () => {
		const blobs = new R2Blobs(env.BLOBS, "adapter-test");
		await blobs.put("media/full/abc.jpg", new Uint8Array([1, 2, 3]), { contentType: "image/jpeg" });

		const got = await blobs.get("media/full/abc.jpg");
		expect(new Uint8Array(got ?? new ArrayBuffer(0))).toEqual(new Uint8Array([1, 2, 3]));
		expect(await blobs.list()).toEqual(["media/full/abc.jpg"]);

		// The prefix is the PHP app's: a bucket built by either runtime reads with the other.
		const keys = await env.BLOBS.list({ prefix: "installations/adapter-test/" });
		expect(keys.objects.map((o) => o.key)).toEqual(["installations/adapter-test/media/full/abc.jpg"]);

		await blobs.delete("media/full/abc.jpg");
		expect(await blobs.get("media/full/abc.jpg")).toBeNull();
	});
});
