# QA: apotheke #104, CI4 users and roles pages (step 7 B, part 1)

- **PR:** [#104](https://github.com/thordin9/apotheke/pull/104) (`ci4/auth-admin` into `feature/ci4`). Security gate for `/settings/security` only (CLI forms later).
- **Head:** `aa81dc52ef48ed5c1494f9ed510a33450dafa1fd`. **Base:** `feature/ci4` `d07431e`. Commits: `c90a5ca`, `3c660a0`, `aa81dc5`.
- **Verdict: PASS at `aa81dc52ef48ed5c1494f9ed510a33450dafa1fd`.** 0 High, 0 Medium; 1 Low (legacy too, PM backlog); 2 Nits.

## Checklist

| Check | Result |
|---|---|
| CI | 22/22, as reported by Coder2. The GitHub API isn't reachable from here. |
| Admin target / grant | Every action on an admin account (role, password, rename, delete, approve) and granting admin need an admin (`AdminGuard`). A manager with `users` gets 403 for all of those, including a pending admin. Editing the admin role answers 409. Matches legacy. |
| `setRole` order | `get` → `roleValid` → `target` → `grant` → last-admin check. An invalid role on an admin answers 422 before the 403, as legacy. |
| Last active admin | Only `status = 'active'` counts. A pending admin does not. Demoting or deleting the last active admin answers 409. |
| Password / sign-in switch | Turning sign-in off and changing your own password need the current password (403 otherwise). Turning sign-in on needs an admin account's password, or creates the first admin inside `Writes::transaction`. Matches legacy. |
| Sessions | A password change renews the session epoch (other sessions → `/login`); the session that changed keeps `signed_in_at` (`keepSignIn`). A role change leaves the epoch alone. |
| IDs and keys | Unknown / huge ids → 404. `/settings/users/0` creates, as legacy. Role keys are `[a-z0-9-]+` (other paths 404). |
| Roles | Last-manager check on update and delete. Guest never gets `users`/`settings`/`backup`. Moving accounts to guest answers 422 (legacy does too, via `account_roles()`). Built-in roles cannot be deleted. |
| Deviations | NUL in a new password → 422 (legacy 500). Deleting the role new registrations get resets `auth_register_role` to `viewer`. |
| CSRF | All 14 POST routes refuse missing, wrong and other-session tokens with 419; nothing changes. |
| Privilege escalation | Covered above. Mass-assignment fields (`id`, `status`, `password_hash`) are ignored. |
| Worker phase | Nothing blocks the FrankenPHP worker / Cloudflare Container phase. Services are readonly and built per request; `$visitor` is per controller. |
| Tests, PHP 8.4 | CI4 module suite: SQLite 410 (1 skipped), PostgreSQL 17 519 OK. Full `APOTHEKE_APP=ci4`: PG17 678 OK (6 skipped); SQLite 678 (39 skipped, 1 incomplete). Legacy PHP 8.3: PG17 678 OK (56 skipped); SQLite 678 (82 skipped, 1 incomplete). Parity test included. |
| Playwright (CI4) | `roles.spec.ts` OK. `users.spec.ts` fails on an unported qty/history step (N-112); the users/roles steps before that pass. Neither file is in the CI4 e2e list in CI. |
| Mutations | 17 of 20 caught (admin target/grant, last admin, guest role, last manager, move-to-guest, admin role edit, register-role reset, current password, `keepSignIn`, self-delete). Three survivors are equivalent: admin always counts as a manager (`Permissions::clean` gives admin every permission); the first-admin `transaction` wraps a single insert; treating a pending admin as “last admin” on demote only (stricter, not tested — N-111). |
| Gates | `.gitleaks.toml` `b5bc9f98…` unchanged. gitleaks finds nothing. `check-commits` ok (3). deptrac and the DI rule: 0 errors. `check-links`: 0 broken. `lint.sh` OK. CHANGELOG from `## [2.` to EOF equals `main` `bbd53c4`. `docs/qa`: 62 identical. No `src`/`public`/migration changes. |

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| L-110 | Low, legacy too | PM backlog | Last-admin check is not atomic. On PostgreSQL, two admins demoting each other in parallel can both pass the count and leave zero active admins (CI4 often; legacy sometimes). Fix in `main` and CI4 together: one `UPDATE … WHERE` that keeps a remaining admin, or `SELECT … FOR UPDATE` in a transaction. |
| N-111 | Nit, tests | Open | No test demotes a pending admin while one active admin remains. The mutant that drops the `status === 'active'` guard on `setRole` survives (over-restrictive only). Add that case. |
| N-112 | Nit, e2e | Open | `users.spec.ts` is not in the CI4 Playwright list and still needs item qty/history (11b) for the “· Stathis” line. Add it to `CI4_UNPORTED` or to the CI4 list when 11b lands. `roles.spec.ts` passed here. |

## Security

- **Authorization:** only admins change admin accounts or grant admin. The last active admin cannot be removed. Managers with `users` cannot escalate.
- **CSRF:** every state-changing route on this page checks the session token.
- **Sessions:** password changes sign out other devices; role changes do not (as legacy).
- **Backlog (PM):** L-110 (last-admin race, `main` and CI4).
- **Worker phase:** nothing blocks the FrankenPHP worker / Cloudflare Container phase.

**Verdict: PASS at `aa81dc52ef48ed5c1494f9ed510a33450dafa1fd`** (0H / 0M / 1L backlog / 2N).
