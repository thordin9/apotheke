// XSalsa20-Poly1305, the primitive behind PHP's `sodium_crypto_secretbox()` (plan §4 rule 7).
//
// Why it is here instead of `libsodium-wrappers`: that package instantiates a WebAssembly module at
// runtime, and workerd refuses `new WebAssembly.Module(bytes)` ("Wasm code generation disallowed by
// embedder"), so the library throws on the first use. The plan's §3 row names it; this file is the
// working substitute, and it is verified against a fixture that real libsodium produced
// (test/fixtures/secretbox.json), not against itself.
//
// Layout, exactly NaCl's `crypto_secretbox`: tag(16) || ciphertext, where
//   subkey    = HSalsa20(key, nonce[0..16])
//   keystream = Salsa20(subkey, nonce[16..24], counter)
//   key32     = keystream[0..32]  (Poly1305 one-time key: r || s)
//   tag       = Poly1305(ciphertext, key32)
//   ciphertext = plaintext XOR keystream[32..]
//
// Poly1305 uses BigInt: settings values are short, and a readable implementation beats a fast one.

const SIGMA = [0x61707865, 0x3320646e, 0x79622d32, 0x6b206574] as const;

const rotl = (v: number, n: number): number => ((v << n) | (v >>> (32 - n))) >>> 0;

const wordsFromLe = (bytes: Uint8Array, offset: number, count: number): number[] => {
	const out: number[] = [];
	for (let i = 0; i < count; i += 1) {
		const at = offset + i * 4;
		out.push(
			((bytes[at] ?? 0) |
				((bytes[at + 1] ?? 0) << 8) |
				((bytes[at + 2] ?? 0) << 16) |
				((bytes[at + 3] ?? 0) << 24)) >>>
				0,
		);
	}
	return out;
};

const leFromWord = (word: number): number[] => [
	word & 0xff,
	(word >>> 8) & 0xff,
	(word >>> 16) & 0xff,
	(word >>> 24) & 0xff,
];

/** The 20-round Salsa20 permutation, without the feed-forward. */
const rounds = (input: readonly number[]): number[] => {
	const x = [...input];
	for (let i = 0; i < 20; i += 2) {
		// column round
		const c = (a: number, b: number, cc: number, d: number) => {
			x[b] = (x[b] ?? 0) ^ rotl(((x[a] ?? 0) + (x[d] ?? 0)) >>> 0, 7);
			x[cc] = (x[cc] ?? 0) ^ rotl(((x[b] ?? 0) + (x[a] ?? 0)) >>> 0, 9);
			x[d] = (x[d] ?? 0) ^ rotl(((x[cc] ?? 0) + (x[b] ?? 0)) >>> 0, 13);
			x[a] = (x[a] ?? 0) ^ rotl(((x[d] ?? 0) + (x[cc] ?? 0)) >>> 0, 18);
		};
		c(0, 4, 8, 12);
		c(5, 9, 13, 1);
		c(10, 14, 2, 6);
		c(15, 3, 7, 11);
		// row round
		c(0, 1, 2, 3);
		c(5, 6, 7, 4);
		c(10, 11, 8, 9);
		c(15, 12, 13, 14);
	}
	return x;
};

/** `crypto_core_hsalsa20`: 32-byte subkey from the key and the first half of the nonce. */
const hsalsa20 = (key: Uint8Array, nonce16: Uint8Array): Uint8Array => {
	const input = [
		...SIGMA.slice(0, 1),
		...wordsFromLe(key, 0, 4),
		...SIGMA.slice(1, 2),
		...wordsFromLe(nonce16, 0, 4),
		...SIGMA.slice(2, 3),
		...wordsFromLe(key, 16, 4),
		SIGMA[3],
	];
	const x = rounds(input);
	const out: number[] = [];
	for (const i of [0, 5, 10, 15, 6, 7, 8, 9]) {
		out.push(...leFromWord(x[i] ?? 0));
	}
	return Uint8Array.from(out);
};

/** The Salsa20 keystream for an 8-byte nonce and a 64-bit counter. */
const xsalsa20Stream = (key: Uint8Array, nonce8: Uint8Array, length: number): Uint8Array => {
	const out = new Uint8Array(length);
	let counter = 0n;
	for (let offset = 0; offset < length; offset += 64) {
		const c = Number(counter & 0xffffffffn);
		const ch = Number((counter >> 32n) & 0xffffffffn);
		const input = [
			SIGMA[0],
			...wordsFromLe(key, 0, 4),
			SIGMA[1],
			...wordsFromLe(nonce8, 0, 2),
			c,
			ch,
			SIGMA[2],
			...wordsFromLe(key, 16, 4),
			SIGMA[3],
		];
		const x = rounds(input);
		const block = new Uint8Array(64);
		for (let i = 0; i < 16; i += 1) {
			block.set(leFromWord(((x[i] ?? 0) + (input[i] ?? 0)) >>> 0), i * 4);
		}
		out.set(block.subarray(0, Math.min(64, length - offset)), offset);
		counter += 1n;
	}
	return out;
};

const poly1305 = (message: Uint8Array, key32: Uint8Array): Uint8Array => {
	const leToBig = (bytes: Uint8Array): bigint => {
		let value = 0n;
		for (let i = bytes.length - 1; i >= 0; i -= 1) {
			value = (value << 8n) | BigInt(bytes[i] ?? 0);
		}
		return value;
	};
	const raw = leToBig(key32.subarray(0, 16));
	// r &= 0x0ffffffc0ffffffc0ffffffc0fffffff, the clamp NaCl applies.
	const r = raw & 0x0ffffffc0ffffffc0ffffffc0fffffffn;
	const s = leToBig(key32.subarray(16, 32));
	const p = (1n << 130n) - 5n;
	let acc = 0n;

	for (let offset = 0; offset < message.length; offset += 16) {
		const chunk = message.subarray(offset, Math.min(offset + 16, message.length));
		const block = new Uint8Array(chunk.length + 1);
		block.set(chunk, 0);
		block[chunk.length] = 1;
		acc = ((acc + leToBig(block)) * r) % p;
	}
	const tag = (acc + s) & ((1n << 128n) - 1n);
	const out = new Uint8Array(16);
	for (let i = 0; i < 16; i += 1) {
		out[i] = Number((tag >> BigInt(8 * i)) & 0xffn);
	}
	return out;
};

const keystream = (key: Uint8Array, nonce: Uint8Array, length: number): Uint8Array => {
	if (key.length !== 32 || nonce.length !== 24) {
		throw new Error("secretbox needs a 32-byte key and a 24-byte nonce");
	}
	const subkey = hsalsa20(key, nonce.subarray(0, 16));
	return xsalsa20Stream(subkey, nonce.subarray(16, 24), length);
};

const MAC_BYTES = 16;
const NONCE_BYTES = 24;
export const SECRETBOX_NONCE_BYTES = NONCE_BYTES; // exported for tests and the adapter
export const SECRETBOX_MAC_BYTES = MAC_BYTES;

/** `crypto_secretbox`: tag || ciphertext. */
export const secretboxSeal = (plain: Uint8Array, nonce: Uint8Array, key: Uint8Array): Uint8Array => {
	const stream = keystream(key, nonce, plain.length + 32);
	const cipher = new Uint8Array(plain.length);
	for (let i = 0; i < plain.length; i += 1) {
		cipher[i] = (plain[i] ?? 0) ^ (stream[i + 32] ?? 0);
	}
	const tag = poly1305(cipher, stream.subarray(0, 32));
	const out = new Uint8Array(MAC_BYTES + cipher.length);
	out.set(tag, 0);
	out.set(cipher, MAC_BYTES);
	return out;
};

/** `crypto_secretbox_open`: the plaintext, or null when the tag does not verify. */
export const secretboxOpen = (sealed: Uint8Array, nonce: Uint8Array, key: Uint8Array): Uint8Array | null => {
	if (sealed.length < MAC_BYTES) {
		return null;
	}
	const tag = sealed.subarray(0, MAC_BYTES);
	const cipher = sealed.subarray(MAC_BYTES);
	const stream = keystream(key, nonce, cipher.length + 32);
	const expected = poly1305(cipher, stream.subarray(0, 32));
	let diff = 0;
	for (let i = 0; i < MAC_BYTES; i += 1) {
		diff |= (tag[i] ?? 0) ^ (expected[i] ?? 0);
	}
	if (diff !== 0) {
		return null;
	}
	const plain = new Uint8Array(cipher.length);
	for (let i = 0; i < cipher.length; i += 1) {
		plain[i] = (cipher[i] ?? 0) ^ (stream[i + 32] ?? 0);
	}
	return plain;
};
