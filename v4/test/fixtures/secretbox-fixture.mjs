#!/usr/bin/env node
// Fixture for the `Secrets` adapter (plan §4 rule 7): one sealed value, produced with a fixed
// nonce so the bytes are stable.
//
//   node test/fixtures/secretbox-fixture.mjs > test/fixtures/secretbox.json
//
// Honest scope: this pins the *envelope and the primitive* — `enc:v1:` + base64(nonce24 ||
// MAC16 || ciphertext) over XSalsa20-Poly1305, which is the same libsodium function PHP's
// `sodium_crypto_secretbox()` wraps, so a value written here is the value PHP would write. It is
// NOT a PHP-produced value: the PHP build on the machine that generated this fixture has no
// ext/sodium, so the cross-runtime spike ("libsodium-wrappers decrypting a PHP-produced enc:v1:
// value", plan §5 phase 0) stays open until it runs where PHP has sodium. The test says so too.

import sodium from "libsodium-wrappers";

await sodium.ready;

/** 32 bytes, written out so the key is not a secret and not a hash of one. */
const key = Uint8Array.from({ length: 32 }, (_, i) => i + 1);
/** 24 bytes, the same shape as PHP's random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES). */
const nonce = Uint8Array.from({ length: 24 }, (_, i) => i);

const plain = "placeholder-secret-for-the-v4-fixture";
const box = sodium.crypto_secretbox_easy(plain, nonce, key);
const raw = new Uint8Array(nonce.length + box.length);
raw.set(nonce, 0);
raw.set(box, nonce.length);

const fixture = {
	generated_by: "v4/test/fixtures/secretbox-fixture.mjs",
	libsodium_wrappers_version: "0.8.4",
	envelope: "enc:v1:",
	key_base64: sodium.to_base64(key, sodium.base64_variants.ORIGINAL),
	nonce_base64: sodium.to_base64(nonce, sodium.base64_variants.ORIGINAL),
	plain,
	sealed: `enc:v1:${sodium.to_base64(raw, sodium.base64_variants.ORIGINAL)}`,
	nonce_bytes: nonce.length,
	mac_bytes: sodium.crypto_secretbox_MACBYTES,
};

process.stdout.write(`${JSON.stringify(fixture, null, "\t")}\n`);
