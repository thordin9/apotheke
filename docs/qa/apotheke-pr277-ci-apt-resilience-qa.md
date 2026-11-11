# QA: apotheke #277, retry apt through mirror and DNS outages

- PR: https://github.com/thordin9/apotheke/pull/277 (DevOps). The DoH fallback is temporary and tracked in https://github.com/thordin9/apotheke/issues/278.
- Reviewed head: `deb14e79664a9d75edeeb79b20f3e27293d79580` (5 commits on main `8bdb2801`)
- Gate: light (CI-only diff read, shellcheck, CI; no suites)
- Reviewer: Apotheke QA 2

## Checklist

- [x] CI-only: `scripts/ci-apt.sh` (new, +165), `.github/workflows/ci.yml` (+11/−30), `scripts/e2e-container-setup.sh` (+8/−8), `docs/ci.md` (+30/−2). No app code. 0 behind main, mergeable clean, 21/21 checks green.
- [x] `install` runs `apt-get update --error-on=any` (180 s cap) and then the install (300 s cap). `run` caps the wrapped command at 300 s. There are 5 attempts with 15/30/60/120 s pauses, and the Azure mirror is dropped after a failure only while another mirror line remains. Step caps are 20 min. The e2e setup goes through the script for the prebuilt libs, `software-properties-common` and the PHP packages. Its `add-apt-repository -n` is followed by the script's own update, so the PPA index is still fetched.
- [x] DoH fallback: only hosts named in the apt sources or the mirror list that fail `getent ahostsv4` are pinned. Each provider is reached by a fixed IP through `curl --resolve` with normal TLS checks. Only `Status 0` type-1 A records in dotted-quad form are kept. The two-provider rule (intersection, or union when it is empty), the single-provider `::warning::` and the none-answered `::error::` match the docs. Every pin is logged and tagged in `/etc/hosts`.
- [x] Integrity is unchanged. There's no `--allow-unauthenticated`, `[trusted=yes]` or signature bypass, so a wrong pin can only fail a fetch. The pins last only for the job on ephemeral runners and containers, and nothing is baked into an image.
- [x] `shellcheck` is clean. Commit subjects are valid Conventional Commits (`ci:`). No CHANGELOG line, as expected for a CI-only change. `docs/ci.md` describes the script, the rules and the removal issue.

## Findings

- **L-1902:** a DoH pin is sticky for the whole job. Later attempts skip any host that already has a `# ci-apt DoH pin` line, so a pinned address that turns out to be unreachable (for example a geo-steered address from the union) fails every retry the same way, and it keeps overriding native DNS after DNS recovers. Suggested fix: after a failed attempt, delete the `# ci-apt DoH pin` lines (`sed -i '/# ci-apt DoH pin$/d'`) so the next attempt checks native DNS and DoH again.
- **N-1903:** Quad9's JSON endpoint on port 5053 never answers from runners (the author's observation). Because `wait` waits for the slowest lookup, every attempt that needs DoH spends about 45–75 s on Quad9's retries, and "two or three providers" in practice means Google and Cloudflare. Either drop Quad9 or move it to an endpoint that answers.
- **N-1904:** "an outage of about ten minutes" holds when attempts fail fast. In the worst case (all caps hit) 5 attempts take far longer than the 20-min step cap, which is then the real limit. Consider saying so in `docs/ci.md`.
- **N-1905:** the IPv4 pattern accepts octets above 255. This is harmless because an invalid `/etc/hosts` line is ignored, but `(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])` would make it exact.

0 High, 0 Medium, 1 Low, 3 Nit.

## Verdict

**PASS** at `deb14e79`. The findings are non-blocking follow-ups, and #278 should cover L-1902 and N-1903 if the fallback stays. Squash-merge with the PR title as the subject.
