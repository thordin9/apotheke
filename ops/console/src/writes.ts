/** Missing or any value other than true stays off. `!== false` would turn a missing flag on. */
export function writesAreEnabled(config: { writes_enabled?: boolean }): boolean {
  return config.writes_enabled === true;
}
