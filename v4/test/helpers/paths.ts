// A concrete request path for a route pattern, so the route table can be exercised as requests:
// `item/([0-9]+)` → `item/1`, `media/(full|thumb)/([^/]+)` → `media/full/x`.
//
// Used by `test/http/router.spec.ts` (every row of the table) and `test/http/probes.spec.ts` (every
// row must be refused by `hostcheck`, which is how the two-path probe bypass stays exactly two).

/** A concrete path for a route pattern: `item/([0-9]+)` → `item/1`, `media/(full|thumb)/([^/]+)` → … */
export const samplePath = (pattern: string): string => {
	let out = "";
	let i = 0;
	while (i < pattern.length) {
		if (pattern[i] !== "(") {
			out += pattern[i];
			i += 1;
			continue;
		}
		let depth = 0;
		let j = i;
		let inner = "";
		for (; j < pattern.length; j += 1) {
			if (pattern[j] === "(") {
				depth += 1;
				if (depth === 1) {
					continue;
				}
			}
			if (pattern[j] === ")") {
				depth -= 1;
				if (depth === 0) {
					break;
				}
			}
			inner += pattern[j];
		}
		const alternatives = inner.split("|");
		const first = alternatives[0] ?? "";
		out += alternatives.length > 1 ? samplePath(first) : inner.includes("0-9") ? "1" : "x";
		i = j + 1;
	}
	return out;
};
