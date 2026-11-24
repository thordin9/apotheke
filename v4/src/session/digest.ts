// SHA-256 as the session layer needs it: lower-case hex. WebCrypto is the only digest the Worker
// has, and it is async, so every function that needs a digest is async too — no home-grown SHA in
// this tree, because a second implementation is a second thing that can disagree with PHP.

export const sha256Hex = async (text: string): Promise<string> => {
	const bytes = new TextEncoder().encode(text);
	const digest = await crypto.subtle.digest("SHA-256", bytes);
	return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, "0")).join("");
};
