<!-- leaf.context v1 -->

# Project Context

This file is the shared memory for this project. Every AI assistant working here — Claude, Codex, Cursor, or anything else — reads it before making changes and writes useful knowledge back when finished. Treat it as the source of truth for goals, decisions, and conventions.

---

## Working With This File

Read this file before performing any task, together with the project's code and the generated Leaf context. Do not ignore the decisions documented here unless the user explicitly overrides them. When uncertain, prefer preserving existing patterns over introducing new ones.

Format rules, so every agent's edits stay compatible:

1. The first line of this file is its format marker. Never remove or edit it.
2. Sections are `##` headings. Keep them in their current order. Preserve sections you don't recognize — another agent may own them. You may add project-specific sections at the end.
3. A line wrapped in underscores is a placeholder. If it contains `agent:`, it is an instruction to you: act on it, then replace the line with real content (or delete it if it no longer applies).
4. Keep entries to a single line each where possible, so edits from different agents merge cleanly.
5. Never store secrets, keys, or tokens here. Refer to `.env` keys by name only.
6. Do not duplicate mechanical information (routes, models, structure, modules). That lives in the code and in `leaf context`. This file holds only what the code cannot say: goals, decisions, and the reasoning behind them.

When your work is complete:

1. Add a line to Recent Changes: `* YYYY-MM-DD — what changed (key files)`. Newest first, five entries maximum; fold older entries into Known Decisions or delete them.
2. If the Current Goal is done, note it in Recent Changes and replace the goal. If you don't know the next goal, ask the user. Exactly one current goal at a time.
3. Record lasting choices in Known Decisions as `* Decision — reasoning.` A decision without its why gets relitigated by the next agent.
4. Keep this file concise. Summarize instead of appending indefinitely, remove outdated information, and prefer references to files over copying their contents.

---

## Project Summary

`sys` (served at `sys.localhost`): a system and network admin tool that tracks statistics and performance of multiple servers and websites, continuously monitoring PHP stacks and MariaDB servers (including crashed tables). All app data is encrypted at rest and can only be unlocked with a user's password; the full encryption rules are in `CLAUDE.md`.

---

## Current Goal

_agent: the login/registration goal is done; ask the user for the next goal and replace this line._

---

## Architecture

### General

* Simplicity is preferred over cleverness.
* Explicit code is preferred over magic.
* Features should remain easy for humans and AI to understand.

### Services

* Service classes are preferred for external APIs and business logic.
* Controllers should remain thin.
* Avoid placing complex logic inside controllers.

### Validation

* Request validation should happen as early as possible.

### Database

* Database schemas are defined in `app/database`.
* Schema files are the source of truth for relationships.

### Frontend

* Blade templates (`app/views`).
* Tailwind is the primary styling system.

---

## External Providers

_agent: when the user adopts a provider, replace this line with a yaml block mapping one provider per concern, for example `billing: stripe`, `email: resend-smtp`, `storage: local`._

---

## Coding Conventions

* Keep controllers focused.
* Keep methods small and readable.
* Prefer service classes over duplicated logic.
* Reuse existing abstractions before introducing new ones.
* Follow existing naming patterns.

---

## Recent Changes

* 2026-09-29 — Admin user management: promote/demote, password reset, delete with key rotation, manual rotation, session revocation (`UserAdminService`, `AdminUserController`, `app/views/admin/*`).
* 2026-09-29 — Login rate limiting: per-IP/per-username/registration limits and a cap of 4 concurrent password derivations (`LoginThrottleService`, `LimitConcurrentLogins`, `login_attempts.yml`).
* 2026-09-29 — Login, setup, registration with admin approval, required TOTP authenticator, default admin, key file download (`app/controllers/*`, `app/services/Auth*`, `TotpService`, `UserAdminService`, `app/views/*`).
* 2026-09-29 — Built the users/vaults schemas and key services with tests (`app/database/*.yml`, `app/services/*`, `tests/services/*`); added Alchemy for test/lint/analyse.
* 2026-09-29 — Installed the Leaf MVC v5 skeleton and added the project's PHP extension requirements (`composer.json`, `.gitignore`, `CLAUDE.md`).

---

## Known Decisions

* This is a Leaf MVC app — all important files are in the `app` directory.
* Leaf MVC best practice wins over other conventions — the user's explicit rule; the encryption rules in `CLAUDE.md` are the exception.
* App data lives in SQLite with application-level libsodium encryption — `pdo_sqlite` cannot encrypt the file, and data must be unreadable without a user's password.
* Login is unwrapping the user's encrypted user-data field, not a password-hash check — so `scaffold:auth`/`leafs/auth` aren't used as-is.
* The user's role is encrypted with the data key, not inside the password-wrapped user-data field — other admins must read it without that user's password.
* An authenticator (TOTP, Google Authenticator defaults) is required for every login; its secret lives inside the password-encrypted user-data field — the user asked for "no authenticator = no access", and this keeps the secret unreadable without the password.
* New registrations are `pending` until an admin approves them — the user's requirement; stored in the encrypted role so it can't be flipped without the data key.
* Default admin `admin`/`changeme` on a fresh install, forced to set a new password and authenticator — the user asked for default credentials at start.
* Per-username lockouts (10 failures / 15 min) are accepted even though they let anyone lock an account out briefly — TOTP already blocks guessing, so the limits mainly stop Argon2id memory exhaustion.
* Client IP comes from `REMOTE_ADDR`, not Leaf's `getIp()` — Leaf trusts spoofable forwarding headers and there is no proxy in front of Apache.
* Deleting a user always rotates the master key; rejecting a pending registration doesn't — rule 6 in `CLAUDE.md`; pending users never signed in, and admins can rotate manually.
* Admins can't act on their own account in the admin tools — avoids self-lockout and keeps the two-admin rule simple.
* Only admins can rotate the master key — rotation locks every other user out until they upload the new key file.
* Two-tier keys (master key wraps a data key) — rotating the master key after removing a user only re-wraps one row instead of re-encrypting all data.
* MariaDB targets are reached through `pdo_mysql` — matches the PDO-based SQLite side.

---

## Future Ideas

* _agent: record ideas the user mentions but isn't building yet._
