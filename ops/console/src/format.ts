// How the overview shows the stats freshness stamp. Null until the cron writes one.
export function statsStamp(asOf: string | null): { datetime: string; text: string } | null {
  if (!asOf) return null;
  const date = new Date(asOf);
  if (Number.isNaN(date.getTime())) return null;
  const text = new Intl.DateTimeFormat('en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'UTC',
  }).format(date);
  return { datetime: asOf, text };
}
