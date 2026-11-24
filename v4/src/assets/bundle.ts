// The served asset bundle: `public/assets/` as bytes, looked up by name (plan §5 S1.1).
//
// `bundle.generated.ts` is written from `public/assets/` by `scripts/gen-assets.mjs`; this file is
// the lookup and the URL. The URL is `AssetUrl::url()`: `/assets/<file>?v=<8 hex>`, with `0` for a
// file that is not in the bundle — the same answer PHP gives for a missing file.

import { BUNDLE, type BundledAsset } from "./bundle.generated";

const BY_NAME: ReadonlyMap<string, BundledAsset> = new Map(BUNDLE.map((asset) => [asset.name, asset]));

export type { BundledAsset };

/** One asset by file name (`app.css`), or undefined when the bundle does not carry it. */
export const asset = (name: string): BundledAsset | undefined => BY_NAME.get(name);

/** Every name in the bundle, in the order the generator lists them. */
export const assetNames = (): readonly string[] => BUNDLE.map((entry) => entry.name);

/** `AssetUrl::url()`: `/assets/app.css?v=f2fa0672`, or `?v=0` for a file the bundle does not carry. */
export const assetUrl = (name: string): string => `/assets/${name}?v=${asset(name)?.version ?? "0"}`;

/** The file's bytes. */
export const assetBytes = (name: string): Uint8Array => {
	const entry = asset(name);
	if (entry === undefined) {
		return new Uint8Array();
	}
	const binary = atob(entry.base64);
	const bytes = new Uint8Array(binary.length);
	for (let i = 0; i < binary.length; i += 1) {
		bytes[i] = binary.charCodeAt(i);
	}
	return bytes;
};
