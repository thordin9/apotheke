// MD5 against the vectors that settle it (plan §5 S1.1): RFC 1321's own, plus four strings whose
// digests PHP computed. The function exists only for the `?v=` cache keys the PHP views derive with
// `md5()`, so "same as PHP" is the whole requirement — including for a multi-byte string, where PHP
// hashes the UTF-8 bytes and not the code units.

import { describe, expect, it } from "vitest";
import { md5, md5Bytes } from "../../src/html/md5";

describe("md5", () => {
	it("matches RFC 1321's test suite", () => {
		expect(md5("")).toBe("d41d8cd98f00b204e9800998ecf8427e");
		expect(md5("a")).toBe("0cc175b9c0f1b6a831c399e269772661");
		expect(md5("abc")).toBe("900150983cd24fb0d6963f7d28e17f72");
		expect(md5("message digest")).toBe("f96b697d7cb7938d525a2f31aaf161d0");
		expect(md5("abcdefghijklmnopqrstuvwxyz")).toBe("c3fcd3d76192e4007dfb496cca67e13b");
		expect(md5("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789")).toBe(
			"d174ab98d277d9f5a5611c2c9f419d9f",
		);
		expect(md5("12345678901234567890123456789012345678901234567890123456789012345678901234567890")).toBe(
			"57edf4a22be3c955ac49da2e2107b67a",
		);
	});

	it("matches PHP on the strings the views hash", () => {
		// `php -r 'echo md5($s);'` for each, PHP 8.4:
		expect(md5("#5b3b8e")).toBe("79dd6a1bcbb1a8e382f89bddabeb5a80");
		// A multi-byte string: PHP hashes its UTF-8 bytes.
		expect(md5("Apotheke — σημείο")).toBe("305cbcc90ed8b965e1d8b5a8395ecdbb");
	});

	it("hashes bytes as given, so the asset version can be computed from a buffer", () => {
		expect(md5Bytes(new TextEncoder().encode("abc"))).toBe("900150983cd24fb0d6963f7d28e17f72");
	});

	it("handles the padding boundaries", () => {
		// 55, 56 and 64 bytes: the lengths at which the length field spills into a second block.
		for (const length of [54, 55, 56, 63, 64, 65]) {
			const text = "x".repeat(length);
			expect(md5(text), String(length)).toMatch(/^[0-9a-f]{32}$/);
			expect(md5(text)).toHaveLength(32);
		}
	});
});
