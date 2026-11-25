// Minimal PNG pixel I/O: decode to RGBA8, encode RGBA8, and locate the bounding box of the pixels
// that differ between two images. No dependencies, because the instrument this serves runs in the
// Playwright container where the only installed package is @playwright/test itself.
//
// Why a decoder at all: D51 says a red is exactly what a negative control wants, so the control's
// evidence cannot be an exit status. The comparator says *whether* a pixel differs; this says
// *where*, which is the only way to tell the planted pixel from a browser-version mismatch (that
// one spreads). It reads the PNGs Playwright wrote, and it never decides pass/fail on its own.

import zlib from 'node:zlib';

const SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

// CRC-32 (IEEE), the table built once at module load.
const CRC_TABLE = (() => {
	const table = new Int32Array(256);
	for (let n = 0; n < 256; n++) {
		let c = n;
		for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
		table[n] = c;
	}
	return table;
})();

function crc32(buf) {
	let c = -1;
	for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
	return (c ^ -1) >>> 0;
}

function chunk(type, data) {
	const out = Buffer.alloc(8 + data.length + 4);
	out.writeUInt32BE(data.length, 0);
	out.write(type, 4, 'ascii');
	data.copy(out, 8);
	out.writeUInt32BE(crc32(Buffer.concat([Buffer.from(type, 'ascii'), data])), 8 + data.length);
	return out;
}

/** @returns {{width:number,height:number,rgba:Uint8Array}} */
export function decodePng(buf) {
	if (!buf.subarray(0, 8).equals(SIGNATURE)) throw new Error('not a PNG (signature mismatch)');
	let offset = 8;
	let width = 0, height = 0, bitDepth = 0, colorType = 0, interlace = 0;
	const idat = [];
	while (offset < buf.length) {
		const length = buf.readUInt32BE(offset);
		const type = buf.toString('ascii', offset + 4, offset + 8);
		const data = buf.subarray(offset + 8, offset + 8 + length);
		if (type === 'IHDR') {
			width = data.readUInt32BE(0);
			height = data.readUInt32BE(4);
			bitDepth = data[8];
			colorType = data[9];
			interlace = data[12];
		} else if (type === 'IDAT') idat.push(data);
		else if (type === 'IEND') break;
		offset += 12 + length;
	}
	if (bitDepth !== 8) throw new Error(`PNG bit depth ${bitDepth} is not supported (8 only)`);
	if (interlace !== 0) throw new Error('interlaced PNG is not supported');
	const channels = { 0: 1, 2: 3, 4: 2, 6: 4 }[colorType];
	if (!channels) throw new Error(`PNG colour type ${colorType} is not supported`);
	const raw = zlib.inflateSync(Buffer.concat(idat));
	const stride = width * channels;
	if (raw.length < height * (stride + 1)) throw new Error(`PNG data is short: ${raw.length} bytes for ${width}x${height}`);
	const rgba = new Uint8Array(width * height * 4);
	const prior = Buffer.alloc(stride);
	const line = Buffer.alloc(stride);
	for (let y = 0; y < height; y++) {
		const start = y * (stride + 1);
		const filter = raw[start];
		raw.copy(line, 0, start + 1, start + 1 + stride);
		for (let x = 0; x < stride; x++) {
			const a = x >= channels ? line[x - channels] : 0;
			const b = prior[x];
			const c = x >= channels ? prior[x - channels] : 0;
			let predict;
			switch (filter) {
				case 0: predict = 0; break;
				case 1: predict = a; break;
				case 2: predict = b; break;
				case 3: predict = (a + b) >> 1; break;
				case 4: {
					const p = a + b - c;
					const pa = Math.abs(p - a), pb = Math.abs(p - b), pc = Math.abs(p - c);
					predict = pa <= pb && pa <= pc ? a : pb <= pc ? b : c;
					break;
				}
				default: throw new Error(`unknown PNG filter ${filter} on row ${y}`);
			}
			line[x] = (line[x] + predict) & 0xff;
		}
		line.copy(prior);
		for (let x = 0; x < width; x++) {
			const s = x * channels;
			const d = (y * width + x) * 4;
			if (channels === 1) { rgba[d] = rgba[d + 1] = rgba[d + 2] = line[s]; rgba[d + 3] = 255; }
			else if (channels === 3) { rgba[d] = line[s]; rgba[d + 1] = line[s + 1]; rgba[d + 2] = line[s + 2]; rgba[d + 3] = 255; }
			else if (channels === 2) { rgba[d] = rgba[d + 1] = rgba[d + 2] = line[s]; rgba[d + 3] = line[s + 1]; }
			else { rgba[d] = line[s]; rgba[d + 1] = line[s + 1]; rgba[d + 2] = line[s + 2]; rgba[d + 3] = line[s + 3]; }
		}
	}
	return { width, height, rgba };
}

/** Encode RGBA8 as PNG, filter 0 on every row. Deterministic for a given image. */
export function encodePng({ width, height, rgba }) {
	const stride = width * 4;
	const raw = Buffer.alloc(height * (stride + 1));
	for (let y = 0; y < height; y++) {
		raw[y * (stride + 1)] = 0;
		Buffer.from(rgba.buffer, rgba.byteOffset + y * stride, stride).copy(raw, y * (stride + 1) + 1);
	}
	const ihdr = Buffer.alloc(13);
	ihdr.writeUInt32BE(width, 0);
	ihdr.writeUInt32BE(height, 4);
	ihdr[8] = 8; ihdr[9] = 6; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
	return Buffer.concat([SIGNATURE, chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

/** Copy an image, then set one pixel. Returns a new image; the input is not modified. */
export function withPixel(img, x, y, [r, g, b, a = 255]) {
	const rgba = new Uint8Array(img.rgba);
	const d = (y * img.width + x) * 4;
	rgba[d] = r; rgba[d + 1] = g; rgba[d + 2] = b; rgba[d + 3] = a;
	return { width: img.width, height: img.height, rgba };
}

export function pixelAt(img, x, y) {
	const d = (y * img.width + x) * 4;
	return [img.rgba[d], img.rgba[d + 1], img.rgba[d + 2], img.rgba[d + 3]];
}

/**
 * Bounding box and count of the pixels that differ byte-for-byte between two images.
 * Sizes must agree: a size change is not a diff to locate, it is a different screenshot.
 */
export function locateDiff(expected, actual) {
	if (expected.width !== actual.width || expected.height !== actual.height) {
		throw new Error(`size differs: expected ${expected.width}x${expected.height}, actual ${actual.width}x${actual.height}`);
	}
	let count = 0, x0 = Infinity, y0 = Infinity, x1 = -1, y1 = -1;
	for (let y = 0; y < expected.height; y++) {
		for (let x = 0; x < expected.width; x++) {
			const d = (y * expected.width + x) * 4;
			if (expected.rgba[d] !== actual.rgba[d] || expected.rgba[d + 1] !== actual.rgba[d + 1]
				|| expected.rgba[d + 2] !== actual.rgba[d + 2] || expected.rgba[d + 3] !== actual.rgba[d + 3]) {
				count++;
				if (x < x0) x0 = x;
				if (y < y0) y0 = y;
				if (x > x1) x1 = x;
				if (y > y1) y1 = y;
			}
		}
	}
	return count === 0
		? { count: 0, box: null, text: 'pixels identical' }
		: { count, box: { x: x0, y: y0, width: x1 - x0 + 1, height: y1 - y0 + 1 }, text: `${count} pixel(s) differ, bounding box ${x1 - x0 + 1}x${y1 - y0 + 1} at (${x0},${y0})` };
}
