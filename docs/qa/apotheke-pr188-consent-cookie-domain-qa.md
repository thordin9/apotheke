# QA: apotheke PR #188, "fix(consent): configurable consent cookie domain"

**Verdict: PASS.** Reviewed head `5e02fb809089800ab4c18447411def8d72793f7c`, 2026-10-31 (EET):
- same repo, 0 behind `main`, 3 commits, 12 files (+145/−24);
- CI 20/20, both Tests jobs green.

It fixes #182's L-1421 and N-1460.

## Checklist

- [x] **No built-in domain.** `getapotheke.com` is gone from the server code, views and JS.
  - It remains only in docs and examples (the hosted value), in tests, and in the frozen #182 QA doc.
  - `COOKIE_DOMAIN` is empty by default (host-only). It is passed through in `.env.example` and docker-compose.
- [x] **Normalisation**, fuzzed with about 50 values (`domainFrom()`).
  - Lower-cased; one leading dot dropped; surrounding whitespace and NUL trimmed.
  - Rejected (host-only): IPv4 and IPv6 (bare or bracketed), single labels and bare TLDs, an all-digit last label, a port, a path, wildcards, empty labels, `_`, quotes, `%`, Unicode/IDN, a trailing dot, labels over 63, and any `;`, `,`, space or CR/LF inside (no attribute or header injection).
  - Punycode (`xn--…`) is accepted.
- [x] **Host matching**, on a real app with SQLite and our own PG17.
  - Domain is set only when `Host` equals the value or ends with `.` + value (case, trailing dot and port ignored).
  - Lookalikes (`evil…`, `….evil.tld`), other hosts and IPs stay host-only, for both the form and the notice's `data-domain`.
  - Only `Host` is read. With `trust_proxy` on, `X-Forwarded-Host` has no effect.
  - The host check rejects malformed `Host` values (421). Even past it, the Domain is always the configured value and never request text.
- [x] **Only the consent cookie gets a Domain.** Every `Set-Cookie` was checked across consent, sign-in page, sign-in, theme, API session and sign-out, over HTTPS behind a proxy, on both drivers.
  - `__Host-apothekesid` (Secure, HttpOnly, Lax) and `theme` never carry a Domain.
  - The recently-viewed cookie is built with an empty domain.
- [x] **`COOKIE_CONSENT=off` with `COOKIE_DOMAIN` set:** cookies from the first request, no notice, the session host-only. `/consent` still sets the Domain cookie.
- [x] **N-1460:** the 2 KB limit now counts encoded bytes, on both drivers.
  - At exactly 2048 bytes, ASCII or mixed with `é`, the path is kept; at 2049 or more it falls back to `/`.
  - 342 or 400 × `é`, and a 200 KB input, fall back to `/`.
  - The 24 round-3 redirect cases from #182 still pass: ASCII-only, relative, same-origin.
- [x] **Docs.** The deployment "Cookie consent" section, `.env.example`, compose, OpenAPI and HANDOFF are updated. The CHANGELOG `[Unreleased]` upgrade note says hosted installs must set `COOKIE_DOMAIN=getapotheke.com`. Released CHANGELOG sections and `.gitleaks.toml` are unchanged.
- [x] **Tests.** Consent, session, CSRF and auth tests pass on SQLite and PG17 with password auth: root 73 OK, modules 108 OK.
- [x] **Tooling.** Lint, deptrac, DI and `routes --check` are clean. gitleaks 8.30.1 on `main..pr188`: no leaks.
- [x] **Worker mode.** No static properties in the changed classes. The domain comes from the environment through the config object, and the host match is a pure function of the current request.
- [x] **Mutations: 14, of which 12 killed.**
  - The PR's 8 are all killed: suffix bypass, missing leading dot, Domain on the session cookie, length checked before encoding, single label, IP, port/path/wildcard, env not read.
  - My extras killed: no dot strip, no lower-casing, all-digit TLD, raw value used as the Domain.
  - Survived: the domain host read from `X-Forwarded-Host`, and the notice's `data-domain` without the host match (L-1481).

## Findings

- **L-1480:** There is no public-suffix check, and the docs don't say so. A value such as `co.uk` is accepted on hosts under it.
  - Browsers then drop the consent cookie, so users can't consent there.
  - Or, with a broader registrable domain, the consent is shared with sibling hosts the operator may not control.
  - This needs operator misconfiguration. Fix: document "a registrable domain you control, not a public suffix".
- **L-1481:** Two correct behaviours have no test:
  - the Domain uses `Host` only, never `X-Forwarded-Host`;
  - the notice's `data-domain` is host-matched.

  Both are verified live, but mutating either passes the suites.
- **N-1482:** A trailing dot, a Unicode/IDN value (punycode is needed) and over-long names (no 253 limit) are silently host-only or accepted, and the docs don't list them. The docs could also say the value is ignored without an error.
- **N-1483:** The `Config\Consent::$cookieDomain` docblock says "and any later cookie that is not __Host-". Only the consent cookie uses it today. Reword to avoid implying a wider scope.
- **N-1484:** The raw return path has no length cap before its percent-decoding passes. It is bounded by the POST size limit, so there is no practical effect.

## Security

- The consent cookie's Domain is operator configuration only. It is validated to a plain DNS name and applied only on matching hosts.
- Request headers and forwarded headers can't choose the Domain or inject cookie attributes.
- The session cookie keeps the `__Host-` prefix and stays host-only. No other cookie gets a Domain.
- The return path stays a same-origin relative path, ASCII-only, at most 2 KB as sent.
- No per-request state is held across requests. Nothing is H or M.

## Verdict

**PASS.** No H/M findings and no decision needed. L-1480 and L-1481, and N-1482 to N-1484, are follow-ups.
