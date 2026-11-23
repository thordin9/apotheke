// Apotheke v4 — Worker entry point.
// Phase 0 scope: boot and /healthz. Routes arrive in S0.5, the platform adapters in S0.3.
export interface Env {
  DB_DRIVER?: "sqlite" | "rqlite";
}

const VERSION = "4.0.0-dev";

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json; charset=utf-8" },
  });
}

function problem(status: number, title: string, type: string): Response {
  return new Response(JSON.stringify({ type, title, status }), {
    status,
    headers: { "content-type": "application/problem+json; charset=utf-8" },
  });
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const { pathname } = new URL(request.url);
    if (pathname === "/healthz") {
      return json({ ok: true, version: VERSION, driver: env.DB_DRIVER ?? "sqlite" });
    }
    return problem(404, "Not Found", "https://apotheke.app/problems/not-found");
  },
};
