// `fold()` and `natKey()` — the PHP app's case/accent folding and natural-sort key
// (modules/Core/Libraries/Text.php, modules/Migrations/LegacyFunctions.php). Ported because
// migration 0002 writes committed `sort_key` values with them and the import path will need them;
// they are pure functions over a string, so they belong in one file with no database dependency.

const FOLD_MAP: Readonly<Record<string, string>> = {
	ά: "α",
	έ: "ε",
	ή: "η",
	ί: "ι",
	ό: "ο",
	ύ: "υ",
	ώ: "ω",
	ϊ: "ι",
	ΐ: "ι",
	ϋ: "υ",
	ΰ: "υ",
	ς: "σ",
	à: "a",
	á: "a",
	â: "a",
	ã: "a",
	ä: "a",
	å: "a",
	ç: "c",
	è: "e",
	é: "e",
	ê: "e",
	ë: "e",
	ì: "i",
	í: "i",
	î: "i",
	ï: "i",
	ñ: "n",
	ò: "o",
	ó: "o",
	ô: "o",
	õ: "o",
	ö: "o",
	ø: "o",
	ù: "u",
	ú: "u",
	û: "u",
	ü: "u",
	ý: "y",
	ÿ: "y",
	ß: "ss",
};

/** Lowercase, then strip the accents the app treats as the same letter. */
export const fold = (value: string): string => {
	let lowered = value.toLowerCase();
	for (const [from, to] of Object.entries(FOLD_MAP)) {
		lowered = lowered.split(from).join(to);
	}

	return lowered;
};

/**
 * The natural-sort key: fold, then pad every digit run to 12 characters so "Box 2" sorts before
 * "Box 10". `mb_substr(..., 0, 400)` in PHP, `slice` here: both keep 400 characters of the same
 * folded alphabet.
 */
export const natKey = (value: string): string =>
	fold(value.trim())
		.replace(/\d+/g, (digits) => (digits.replace(/^0+/, "") || "0").padStart(12, "0"))
		.slice(0, 400);
