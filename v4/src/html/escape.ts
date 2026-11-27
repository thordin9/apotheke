// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
// Escaping for the HTML layer (plan §5 S1.1), a port of `Page::e()` in
// `modules/Layout/Page.php`:
//
//     htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
//
// `ENT_QUOTES` escapes both quote styles, and `ENT_SUBSTITUTE` replaces an invalid UTF-8 sequence
// with U+FFFD instead of returning an empty string. Anything the PHP views print through `Page::e()`
// goes through this function and nothing else: the golden fixture is what keeps the two the same.

const ENTITIES: Readonly<Record<string, string>> = {
	"&": "&amp;",
	"<": "&lt;",
	">": "&gt;",
	'"': "&quot;",
	"'": "&#039;",
};

export const e = (value: string | number | boolean | null | undefined): string => {
	const text = value === null || value === undefined ? "" : String(value);
	// `ENT_SUBSTITUTE`: a lone surrogate is not a character, and PHP writes U+FFFD for it. JS
	// strings can hold one, so it is normalised before escaping.
	let safe = "";
	for (const ch of text) {
		const code = ch.codePointAt(0) ?? 0;
		safe += code >= 0xd800 && code <= 0xdfff ? "\uFFFD" : ch;
	}
	return safe.replace(/[&<>"']/g, (ch) => ENTITIES[ch] ?? ch);
};

/**
 * PHP's `ucfirst()`: an ASCII `a`–`z` first character becomes upper case, everything else —
 * including a first byte of a multi-byte character — is left exactly as it is (C locale).
 */
export const ucfirst = (value: string): string => {
	const first = value[0];
	if (first === undefined || first < "a" || first > "z") {
		return value;
	}
	return first.toUpperCase() + value.slice(1);
};
