// The preview sends the same policy as edge/ops. scripts/csp.test.ts reads that file and fails if the two drift.
export const OPS_CSP =
  "default-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; " +
  "base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'";
