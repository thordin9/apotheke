import { SELF } from "cloudflare:test";
import { describe, expect, it } from "vitest";

describe("healthz", () => {
  it("answers 200 with the dev version", async () => {
    const res = await SELF.fetch("https://apotheke.test/healthz");
    expect(res.status).toBe(200);
    expect(await res.json()).toMatchObject({ ok: true, version: "4.0.0-dev" });
  });

  it("answers an unknown path with problem+json", async () => {
    const res = await SELF.fetch("https://apotheke.test/nope");
    expect(res.status).toBe(404);
    expect(res.headers.get("content-type")).toContain("application/problem+json");
  });
});
