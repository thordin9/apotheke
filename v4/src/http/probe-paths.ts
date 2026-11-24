/**
 * The only two paths answered before the router and the filter chain (plan §5 S0.5, §11 rule 7).
 *
 * They live in their own module because the *entry* module's exports are the Worker's handler
 * surface: a plain constant exported from `src/index.ts` is rejected by workerd at boot
 * ("Incorrect type for map entry … the provided value is not of type 'function or ExportedHandler'").
 * `vitest` did not catch that; the `workerd smoke` job did.
 *
 * Adding a third path is a decision, not an edit: `test/http/probes.spec.ts` pins this list, sends
 * every route in the table with a rejected Host, and reads the entrypoint's source so no comparison
 * against `pathname` can name anything but these two constants.
 */
export const HEALTHZ_PATH = "/healthz";
export const READYZ_PATH = "/readyz";
export const PROBE_PATHS = [HEALTHZ_PATH, READYZ_PATH] as const;
