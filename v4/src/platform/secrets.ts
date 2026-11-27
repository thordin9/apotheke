// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
// The `Secrets` adapter (plan §4 rule 7, §5 S0.3): at-rest encryption for recoverable secrets.
//
// The envelope is the PHP app's, byte for byte (modules/Core/Security/SecretBox.php):
//
//     enc:v1: + base64( nonce[24] || crypto_secretbox(plain, nonce, key) )
//
// `crypto_secretbox` is XSalsa20-Poly1305 and returns MAC || ciphertext, so `secretbox.ts` produces
// and opens exactly the bytes PHP's `sodium_crypto_secretbox` does. A value without the prefix is
// legacy plaintext and is returned as-is.
//
// Behaviour that is PHP's, deliberately: with no usable key, seal() returns the plaintext (the app
// keeps working and logs once), and open() returns "" for a value that will not open — a bad key
// must never turn into an exception on a read path.

import { SECRETBOX_MAC_BYTES, SECRETBOX_NONCE_BYTES, secretboxOpen, secretboxSeal } from "./secretbox";

export const ENVELOPE = "enc:v1:";
const KEY_BYTES = 32;

const fromBase64 = (text: string): Uint8Array | null => {
	try {
		const binary = atob(text);
		const bytes = new Uint8Array(binary.length);
		for (let i = 0; i < binary.length; i += 1) {
			bytes[i] = binary.charCodeAt(i);
		}
		return bytes;
	} catch {
		return null;
	}
};

const toBase64 = (bytes: Uint8Array): string => {
	let binary = "";
	for (const byte of bytes) {
		binary += String.fromCharCode(byte);
	}
	return btoa(binary);
};

/** 32 raw key bytes from `APP_KEY` (base64, optional `base64:` prefix), or null if malformed. */
export const decodeKey = (raw: string | undefined): Uint8Array | null => {
	if (raw === undefined) {
		return null;
	}
	let text = raw.trim();
	if (text === "") {
		return null;
	}
	if (text.startsWith("base64:")) {
		text = text.slice(7).trim();
	}
	const bytes = fromBase64(text);
	return bytes !== null && bytes.length === KEY_BYTES ? bytes : null;
};

export const isSealed = (stored: string): boolean => stored.startsWith(ENVELOPE);

export class Secrets {
	private constructor(
		private readonly key: Uint8Array | null,
		/** Test seam: a fixed nonce makes a sealed value reproducible across runtimes. */
		private readonly nonce: () => Uint8Array,
	) {}

	static create(appKey: string | undefined, nonce?: () => Uint8Array): Secrets {
		return new Secrets(
			decodeKey(appKey),
			nonce ?? (() => crypto.getRandomValues(new Uint8Array(SECRETBOX_NONCE_BYTES))),
		);
	}

	/** Whether a key is configured and usable; without one, secrets stay plaintext (PHP does the same). */
	get usable(): boolean {
		return this.key !== null;
	}

	/** `''` stays `''`; no key keeps the plaintext; otherwise the `enc:v1:` envelope. */
	seal(plain: string): string {
		if (plain === "" || this.key === null) {
			return plain;
		}
		const nonce = this.nonce();
		const box = secretboxSeal(new TextEncoder().encode(plain), nonce, this.key);
		const raw = new Uint8Array(nonce.length + box.length);
		raw.set(nonce, 0);
		raw.set(box, nonce.length);

		return ENVELOPE + toBase64(raw);
	}

	/** Plaintext, the value itself when it was never sealed, or `''` when it will not open. */
	open(stored: string): string {
		if (stored === "" || !isSealed(stored)) {
			return stored;
		}
		if (this.key === null) {
			return "";
		}
		const raw = fromBase64(stored.slice(ENVELOPE.length));
		if (raw === null || raw.length < SECRETBOX_NONCE_BYTES + SECRETBOX_MAC_BYTES) {
			return "";
		}
		const plain = secretboxOpen(
			raw.subarray(SECRETBOX_NONCE_BYTES),
			raw.subarray(0, SECRETBOX_NONCE_BYTES),
			this.key,
		);

		return plain === null ? "" : new TextDecoder().decode(plain);
	}
}
