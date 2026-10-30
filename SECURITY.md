# Security policy

Thank you for helping keep Apotheke and the people who run it safe.

## Reporting a vulnerability

Please report security issues **privately**, through GitHub's private vulnerability reporting:

1. Open <https://github.com/thordin9/apotheke/security/advisories/new>
   (or the repository's **Security** tab, then **Report a vulnerability**).
2. Describe the issue, the affected version (`VERSION` or the Control Panel's System page),
   and the steps to reproduce it. A proof of concept helps; please use test data only.

Please do **not** open a public issue, pull request or discussion for a security problem,
and do not include real credentials, tokens or personal data in a report.

## What to expect

These times are targets, not guarantees. Apotheke has one maintainer.

- We aim to acknowledge reports within 3 working days, confirm the issue and share a plan within 14 days, and release a fix within 90 days.
- Complex issues may take longer, and we will keep the reporter informed.
- We aim to publish a GitHub security advisory with the fix. Credit is given in the advisory unless you prefer otherwise.
- We may ask for more detail through the private advisory thread.

## Supported versions

| Version | Supported |
|---|---|
| 3.x | yes |
| 2.6.x | security fixes only, for 90 days after 3.0.0 (branch `old`) |
| older | no; please upgrade |

## Scope

In scope: the Apotheke application in this repository (the web app, the CLI,
the Docker image built from this repository) and the pages it serves.

Out of scope: self-hosted instances you do not own, denial-of-service by volume,
social engineering, and issues in third-party dependencies that are already public
(please still tell us if Apotheke is affected in a way that needs a change here).

## Safe harbour

We will not pursue legal action for good-faith research that follows this policy,
avoids privacy violations and service disruption, and gives us reasonable time to fix
the issue before it is disclosed.
