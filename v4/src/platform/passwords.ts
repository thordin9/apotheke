// Password hashes (plan §4 rule 8): existing PHP bcrypt hashes must verify unchanged, and new
// hashes stay `$2y$` bcrypt so PHP still verifies them — no one-way door while both runtimes exist.
//
// bcryptjs emits `$2b$`; the bytes are the same algorithm and cost, and `$2y$` is the form PHP
// stores and prints. Only the prefix is rewritten, never the digest, and bcryptjs verifies a `$2y$`
// hash it produced (a test pins both directions against a PHP-produced hash in the fixture).

import bcrypt from "bcryptjs";

/** PHP 8.4's `PASSWORD_BCRYPT` default cost, which `password_hash(..., PASSWORD_DEFAULT)` uses. */
export const BCRYPT_COST = 12;

const PHP_MARKER = "$2y$";
const JS_MARKER = "$2b$";

export const hashPassword = async (plain: string): Promise<string> =>
	(await bcrypt.hash(plain, BCRYPT_COST)).replace(/^\$2[aby]\$/, PHP_MARKER);

export const verifyPassword = async (plain: string, hash: string): Promise<boolean> =>
	bcrypt.compare(plain, hash);

/** Exposed for the fixture test: what bcryptjs would emit before the marker is rewritten. */
export const bcryptJsMarker = JS_MARKER;
