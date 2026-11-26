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
//
// The body is also where `FormFields` takes its decision: a write that sends a field as a list the
// app's forms never send as one (`name[]=`) is a 422, not a value read as "Array". `formData`
// flattens `name[]=x` and `name=x` to the same key, so the bracket is recorded here, while the raw
// key still exists — that is the whole reason `bracketed()` is on `FormPost`.

/** The fields of a body: one value per name, and every value of a repeated one. */
export interface FormPost extends ReadonlyMap<string, string> {
	/** Every value of `name`, in the order sent; empty when the field was not posted. */
	all(name: string): readonly string[];
	/**
	 * The field names posted with bracket syntax — `name[]=`, `name[0]=` — which is the only thing
	 * that tells PHP to hand a controller an array. `formData` flattens both spellings to `name`, so
	 * the rule in `FormFields::invalid()` needs this recorded while the body is still in front of us.
	 */
	bracketed(): readonly string[];
}

/** A `Map` that also remembers the values it dropped, so a `perms[]` list survives. */
class Fields extends Map<string, string> {
	readonly #values: ReadonlyMap<string, readonly string[]>;
	readonly #bracketed: readonly string[];

	constructor(values: ReadonlyMap<string, readonly string[]>, bracketed: readonly string[] = []) {
		super();
		this.#values = values;
		this.#bracketed = bracketed;
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

	bracketed(): readonly string[] {
		return this.#bracketed;
	}
}

/** No fields: the body of a request without one, and the `Ctx` a test may build by hand. */
export const emptyFormPost = (): FormPost => new Fields(new Map());

/** The fields of a urlencoded or multipart body; first value wins for `get()`, `all()` keeps the rest. */
export const formFields = async (request: Request): Promise<FormPost> => {
	const collected = new Map<string, string[]>();
	const bracketed: string[] = [];
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
			const base = bracketBase(name);
			if (base !== name && !bracketed.includes(base)) {
				bracketed.push(base);
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
	return new Fields(collected, bracketed);
};

/** `name[0]` → `name`, `name[]` → `name`, `name` → `name`: PHP's array key without its subscript. */
const bracketBase = (name: string): string => {
	const at = name.indexOf("[");

	return at === -1 ? name : name.slice(0, at);
};

/** `FormFields::LISTS`: the fields the app's own forms send as lists, and the only ones a write takes. */
export const LIST_FIELDS: readonly string[] = ["ids", "perms", "col", "photos", "files"];

/** `FormFields::STATUS`: the validation status a write with a list in a one-value field answers. */
export const FORM_FIELDS_STATUS = 422;

/**
 * `FormFields::invalid()`: the first field sent as a list that does not take one, or null. `ids`,
 * `perms`, `col`, `photos` and `files` are the exceptions, because the forms send those as arrays and
 * their controllers read the values they take; anything else sent as `name[]=` or `name[0]=` is
 * refused rather than read as "Array" or its first value.
 */
export const invalidListField = (post: FormPost): string | null =>
	post.bracketed().find((name) => !LIST_FIELDS.includes(name)) ?? null;

/** `FormFields::message()`: the field's own name, or `field` when it is not a plain identifier. */
export const listFieldMessage = (field: string): string =>
	`Each field takes one value (${/^[A-Za-z0-9_]{1,40}$/.test(field) ? field : "field"}).`;
