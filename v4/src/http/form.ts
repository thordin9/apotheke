// The form fields a POST carries (plan §5 S1.2/S1.3).
//
// PHP's `getPost()` hands a filter the parsed body; a Worker filter is synchronous, so the body is read
// once before the chain (`routeRequest`) and put on `Ctx.post`, exactly as `Ctx.actor` is resolved
// there for the same reason. `/login`, `/logout` and `/claim` still read their own body in the
// handler — their routes skip `sessioncsrf`, so nothing has read it for them.
//
// An unreadable body is an empty one, which is what `getPost()` is: every check that reads a field
// then refuses, the safe direction.

/** The fields of a urlencoded or multipart body; the first value wins, as PHP's array does. */
export const formFields = async (request: Request): Promise<Map<string, string>> => {
	const out = new Map<string, string>();
	const type = (request.headers.get("content-type") ?? "").toLowerCase();
	if (!type.includes("application/x-www-form-urlencoded") && !type.includes("multipart/form-data")) {
		return out;
	}
	try {
		const form = await request.formData();
		for (const [name, value] of form.entries()) {
			if (typeof value === "string" && !out.has(name)) {
				out.set(name, value);
			}
		}
	} catch {
		// A malformed body is no fields.
	}
	return out;
};
