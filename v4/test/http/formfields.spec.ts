// `FormFields` and `FormFieldsFilter` (plan §5 S1.7): a page or form write takes one value per field.
// Only the fields the app's own forms send as lists — `ids[]`, `perms[]`, `col[...]`, `photos[]`,
// `files[]` — may be lists, and their controllers read the values they take; any other field sent as
// a list (`name[]=`, `id[]=`) is refused with v3's 422 rather than read as "Array" or as 1.
//
// The v3 test is `tests/Modules/Core/FormFieldsTest.php`, retired at 5d65939e: its two methods (the
// rule, and the filter that applies it to writes only) are both here, with every case it asserted.

import { describe, expect, it } from "vitest";
import { FILTERS } from "../../src/http/filters";
import { FORM_FIELDS_STATUS, formFields, invalidListField, listFieldMessage } from "../../src/http/form";
import { createContext, type RouterConfig, routeRequest } from "../../src/http/router";
import { TEST_LOGIN, TEST_PAGE } from "../helpers/page";

const CONFIG: RouterConfig = {
	publicUrl: "",
	https: false,
	hosted: false,
	maxUploadMb: 20,
	page: TEST_PAGE,
	login: TEST_LOGIN,
	signInEnabled: true,
};

/** The fields of one urlencoded body, exactly as a request carries them. */
const post = async (body: string) =>
	formFields(
		new Request("http://localhost/items/save", {
			method: "POST",
			headers: { "content-type": "application/x-www-form-urlencoded" },
			body,
		}),
	);

/** One write through the filter, with the body parsed the way the router parses it. */
const through = async (body: string, init: Record<string, string> = {}) => {
	const request = new Request("http://localhost/items/save", {
		method: "POST",
		headers: { "content-type": "application/x-www-form-urlencoded", ...init },
		body,
	});
	const ctx = createContext(request, CONFIG);
	ctx.post = await formFields(request.clone());

	return FILTERS.formfields?.before?.(ctx) ?? null;
};

describe("FormFields", () => {
	it("lets every field that is not a list through, and every list field that is one", async () => {
		expect(invalidListField(await post(""))).toBeNull();
		expect(invalidListField(await post("id=4&name=Lamp&notes="))).toBeNull();
		expect(
			invalidListField(await post("ids[]=1&ids[]=2&perms[]=edit&col[0]=name&col[3]=&action=move")),
		).toBeNull();
		// A list field's controller reads the values it takes, whatever shape a client sends them in.
		expect(invalidListField(await post("ids[0][]=1"))).toBeNull();
	});

	it("names the first field sent as a list that does not take one", async () => {
		expect(invalidListField(await post("id[]=2"))).toBe("id");
		expect(invalidListField(await post("id=1&name[x]=y"))).toBe("name");
		expect(invalidListField(await post("notes[]="))).toBe("notes");
		expect(invalidListField(await post("0[]=x"))).toBe("0");
	});

	it("says each field takes one value, naming it when it is a plain identifier", () => {
		expect(listFieldMessage("name")).toBe("Each field takes one value (name).");
		expect(listFieldMessage("<b>")).toBe("Each field takes one value (field).");
		// The bound is v3's: forty characters of `[A-Za-z0-9_]`, and the field's own name below it.
		expect(listFieldMessage("a".repeat(40))).toBe(`Each field takes one value (${"a".repeat(40)}).`);
		expect(listFieldMessage("a".repeat(41))).toBe("Each field takes one value (field).");
	});
});

describe("the formfields filter", () => {
	it("refuses a write that sends a field as a list, with v3's 422 and its sentence", async () => {
		const response = await through("id[]=7&name=x");

		expect(response).not.toBeNull();
		expect(response?.status).toBe(FORM_FIELDS_STATUS);
		expect(FORM_FIELDS_STATUS).toBe(422);
		const page = await response?.text();
		expect(page?.match(/Each field takes one value \(id\)\./g)).toHaveLength(1);
	});

	it("answers a client that wants JSON with {ok:false,error}, not a page", async () => {
		const response = await through("id[]=7", { accept: "application/json" });

		expect(await response?.text()).toBe('{"ok":false,"error":"Each field takes one value (id)."}');
		expect(response?.status).toBe(422);
		expect(response?.headers.get("content-type")).toBe("application/json; charset=utf-8");
	});

	// The end-to-end half: the router has to parse the body for this filter too, not only for the
	// token. `/consent` is where that shows — it is excepted from `sessioncsrf` *and* from the consent
	// gate, so before this filter was real the router never read its body at all, and the only thing
	// standing between such a write and the handler was nothing.
	it("refuses through the chain, on a route whose body nothing else parses", async () => {
		const routed = await routeRequest(
			new Request("http://localhost/consent", {
				method: "POST",
				headers: { "content-type": "application/x-www-form-urlencoded", origin: "http://localhost" },
				body: "name[]=x&allow=1",
			}),
			CONFIG,
		);

		expect(routed.trace).toContain("formfields");
		expect(routed.response.status).toBe(422);
		expect(await routed.response.text()).toContain("Each field takes one value (name).");
	});

	it("refuses writes only: a GET filter takes lists, and so does a clean write", async () => {
		const read = new Request("http://localhost/items?name[]=x");
		const ctx = createContext(read, CONFIG);
		ctx.post = await formFields(read.clone());
		expect(FILTERS.formfields?.before?.(ctx) ?? null).toBeNull();

		expect(await through("name=x&ids[]=1")).toBeNull();
	});
});
