// The `RouterConfig` fields a test does not care about (plan §5 S1.1): the shell's values and the
// two `/login` decisions. The golden test does not use them — it goes through the Worker, where the
// settings row supplies them — so a test that renders nothing can keep the defaults here.

import type { RouterConfig } from "../../src/http/router";

export const TEST_PAGE: RouterConfig["page"] = {
	appName: "Apotheke",
	tagline: "the storehouse for your stuff",
	theme: "auto",
	accent: "#5b3b8e",
	csrf: "",
	requestUri: "/",
	consent: false,
};

export const TEST_LOGIN: RouterConfig["login"] = { registrationOpen: false, guestBrowsing: false };
