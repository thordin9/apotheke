// Citations (D168/D172): every `modules/`, `tests/Modules` and `app/Config` path below is read at
// `5d65939e^`, the last commit before the PHP app was retired.
//
// The session epoch (plan §4 rule 4, migration 0028): the value a signed-in session must hold to stay
// valid. `modules/Auth/SessionEpoch.php` is the oracle.
//
// The account's stored `session_epoch` wins. An account without one (a v3 database that predates the
// column, or a row 0028 filled in) uses the fingerprint `substr(sha256(password_hash . '|' . id), 0, 16)`
// — the same value 0028 writes, so an upgrade signs nobody out. A new password or "sign out everywhere"
// stores a new value, and every session holding the old one stops authenticating on its next request.

import { sha256Hex } from "./digest";

/** The columns the epoch depends on — nothing else, so a caller cannot pass a partly loaded row. */
export interface EpochUser {
	readonly id: number;
	readonly passwordHash: string;
	readonly sessionEpoch: string;
}

/** `SessionEpoch::of()`: the stored epoch, or the fingerprint that 0028 would have written. */
export const sessionEpoch = async (user: EpochUser): Promise<string> => {
	if (user.sessionEpoch !== "") {
		return user.sessionEpoch;
	}
	return (await sha256Hex(`${user.passwordHash}|${user.id}`)).slice(0, 16);
};

/** Whether a session's held epoch is still the account's — constant-time on the strings it compares. */
export const epochHolds = (held: string, current: string): boolean => {
	if (held === "" || current === "") {
		return false;
	}
	if (held.length !== current.length) {
		return false;
	}
	let diff = 0;
	for (let i = 0; i < held.length; i += 1) {
		diff |= held.charCodeAt(i) ^ current.charCodeAt(i);
	}
	return diff === 0;
};

/** `SessionEpoch::fresh()`: a new random epoch, 16 hex digits, for a password change or a sign-out everywhere. */
export const freshEpoch = (): string => {
	const bytes = new Uint8Array(8);
	crypto.getRandomValues(bytes);
	return [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
};
