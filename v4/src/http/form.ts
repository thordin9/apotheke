// The form fields a POST carries (plan §5 S1.2/S1.3).
//
// PHP's `getPost()` hands a filter the parsed body; a Worker filter is synchronous, so the body is read
// once before the chain (`routeRequest`) and put on `Ctx.post`, exactly as `Ctx.actor` is resolved
// there for the same reason. `/login`, `/logout` and `/claim` still read their own body in the
// handler — their routes skip `sessioncsrf`, so nothing has read it for them.
//
// A field posted as an array keeps every value: `perms[]=view&perms[]=edit` is what the roles editor
// sends, and PHP's `getPost('perms')` answers `['view', 'edit']` for it, so `all()` returns the list.
// `get()` still returns one value, the first, as this module has since S1.2.
//
// An unreadable body is an empty one, which is what `getPost()` is: every check that reads a field
// then refuses, the safe direction.

/** The fields of a body: one value per name, and every value of a repeated one. */
export interface FormPost extends ReadonlyMap<string, string> {
	/** Every value of `name`, in the order sent; empty when the field was not posted. */
	all(name: string): readonly string[];
}

/** A `Map` that also remembers the values it dropped, so a `perms[]` list survives. */
class Fields extends Map<string, string> {
	readonly #values: ReadonlyMap<string, readonly string[]>;

	constructor(values: ReadonlyMap<string, readonly string[]>) {
		super();
		this.#values = values;
		for (const [name, list] of values) {
			const first = list[0];
			if (first !== undefined) {
				this.set(name, first);
			}
		}
	}

	all(name: string): readonly string[] {
		return this.#values.get(name) ?? [];
	}
}

/** No fields: the body of a request without one, and the `Ctx` a test may build by hand. */
export const emptyFormPost = (): FormPost => new Fields(new Map());

/** The fields of a urlencoded or multipart body; first value wins for `get()`, `all()` keeps the rest. */
export const formFields = async (request: Request): Promise<FormPost> => {
	const collected = new Map<string, string[]>();
	const type = (request.headers.get("content-type") ?? "").toLowerCase();
	if (!type.includes("application/x-www-form-urlencoded") && !type.includes("multipart/form-data")) {
		return new Fields(collected);
	}
	try {
		const form = await request.formData();
		for (const [name, value] of form.entries()) {
			if (typeof value !== "string") {
				continue;
			}
			const list = collected.get(name);
			if (list === undefined) {
				collected.set(name, [value]);
			} else {
				list.push(value);
			}
		}
	} catch {
		// A malformed body is no fields.
	}
	return new Fields(collected);
};
