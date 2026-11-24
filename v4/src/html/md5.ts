// MD5, only for the two cache keys the PHP views derive from `md5()`: the accent stylesheet's
// `?v=` (`substr(md5($accent), 0, 8)`) and a brand asset's version. It is not used for anything
// security-related — a cache key needs to be stable and to match PHP's, nothing more.
//
// Written out rather than pulled in as a dependency: the algorithm is fixed, it is 40 lines, and a
// package for it would be a supply-chain edge for a cache key. `test/html/md5.spec.ts` pins it to
// RFC 1321's vectors and to values PHP computed.

const SHIFTS: readonly number[] = [
	7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9,
	14, 20, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15,
	21, 6, 10, 15, 21,
];

/** `floor(abs(sin(i + 1)) * 2^32)`, the table RFC 1321 spells out; computed, not transcribed. */
const K: readonly number[] = Array.from({ length: 64 }, (_, i) =>
	Math.floor(Math.abs(Math.sin(i + 1)) * 2 ** 32),
);

const rotl = (x: number, c: number): number => ((x << c) | (x >>> (32 - c))) >>> 0;

/** UTF-8 bytes of a string, as PHP's `md5()` hashes (it hashes the bytes, not the code units). */
const utf8 = (text: string): Uint8Array => new TextEncoder().encode(text);

export const md5 = (text: string): string => md5Bytes(utf8(text));

export const md5Bytes = (input: Uint8Array): string => {
	const length = input.length;
	// Padding: 0x80, zeros, then the bit length as a little-endian 64-bit integer.
	const padded = new Uint8Array((((length + 8) >> 6) + 1) << 6);
	padded.set(input);
	padded[length] = 0x80;
	const bits = length * 8;
	const view = new DataView(padded.buffer);
	view.setUint32(padded.length - 8, bits >>> 0, true);
	view.setUint32(padded.length - 4, Math.floor(bits / 2 ** 32), true);

	let a0 = 0x67452301;
	let b0 = 0xefcdab89;
	let c0 = 0x98badcfe;
	let d0 = 0x10325476;

	const words = new Uint32Array(16);
	for (let offset = 0; offset < padded.length; offset += 64) {
		for (let i = 0; i < 16; i += 1) {
			words[i] = view.getUint32(offset + i * 4, true);
		}
		let a = a0;
		let b = b0;
		let c = c0;
		let d = d0;
		for (let i = 0; i < 64; i += 1) {
			let f: number;
			let g: number;
			if (i < 16) {
				f = (b & c) | (~b & d);
				g = i;
			} else if (i < 32) {
				f = (d & b) | (~d & c);
				g = (5 * i + 1) % 16;
			} else if (i < 48) {
				f = b ^ c ^ d;
				g = (3 * i + 5) % 16;
			} else {
				f = c ^ (b | ~d);
				g = (7 * i) % 16;
			}
			const sum = (a + f + (K[i] ?? 0) + (words[g] ?? 0)) >>> 0;
			const shift = SHIFTS[i] ?? 0;
			a = d;
			d = c;
			c = b;
			b = (b + rotl(sum, shift)) >>> 0;
		}
		a0 = (a0 + a) >>> 0;
		b0 = (b0 + b) >>> 0;
		c0 = (c0 + c) >>> 0;
		d0 = (d0 + d) >>> 0;
	}

	const hex = (word: number): string => {
		let out = "";
		for (let i = 0; i < 4; i += 1) {
			out += ((word >>> (i * 8)) & 0xff).toString(16).padStart(2, "0");
		}
		return out;
	};
	return hex(a0) + hex(b0) + hex(c0) + hex(d0);
};
