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

`sys` (served at `sys.localhost`): a system and network admin tool that tracks statistics and performance of multiple servers and websites, continuously monitoring PHP stacks and MariaDB servers (including crashed tables). Accounts are created by admins; sign-in needs a password and an authenticator app. Security rules are in `CLAUDE.md`.

---

## Current Goal

_agent: the account and sign-in rework is done; ask the user for the next goal and replace this line._

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

* 2026-09-29 — SSL monitoring (certificates downloaded from each site, several per server) and optional SSH; servers have one or more of SSH/MariaDB/SSL; navbar split into monitoring (left) and configuration (right) (`SslCheckService`, `SslMonitorService`, `ServerConfigController`, `SslController`).
* 2026-09-29 — SSH health checks: disk (space + inodes), load per core, memory/swap, all in one SSH session per run (`DiskCheck`, `LoadCheck`, `MemoryCheck`, `HealthCheckService::sshScript()`).
* 2026-09-29 — MariaDB health checks, run on demand: server status, connections, crashed tables (metadata + CHECK FAST QUICK), replication, buffer pool; 30-day history (`HealthCheckService`, `app/services/Checks/*`, `servers/show.blade.php`).
* 2026-09-29 — SSH setup flow: trust host key, try the app key, optionally install it with a one-time password, fall back to a stored password only for servers refusing keys; password login is opt-in per server (`SshSetupService`, `servers/setup.blade.php`).
* 2026-09-29 — MySQL TLS per server: verify (default, pasted CA or system CAs), encrypt-only, or off (`MysqlService`, `CaCertificateService`, `servers.yml`).

---

## Known Decisions

* This is a Leaf MVC app — all important files are in the `app` directory.
* Leaf MVC best practice wins over other conventions — the user's explicit rule; the security rules in `CLAUDE.md` are the exception.
* No encryption at rest, no master key, no key files, no self-registration — the client's decision (2026-09-29); the earlier encrypted design is in git history up to `863d3e9`.
* Admins create accounts with a one-time temporary password; first sign-in forces a new password and authenticator enrolment — the client's requirement.
* Passwords expire every 30 days — the client's requirement.
* At least one admin; admins can't act on their own account — the client said one admin is fine.
* Custom sign-in instead of `leafs/auth` — `leafs/auth` creates the session as soon as the password matches, before the authenticator code is checked.
* Authenticator secrets are encrypted with a key derived from `APP_KEY` — the user chose this so a copied database alone can't generate codes.
* An authenticator (TOTP, Google Authenticator defaults) is required for every sign-in — the user asked for "no authenticator = no access".
* Per-username lockouts (10 failures / 15 min) are accepted even though they let anyone lock an account out briefly.
* Client IP comes from `REMOTE_ADDR`, not Leaf's `getIp()` — Leaf trusts spoofable forwarding headers and there is no proxy in front of Apache.
* SSH uses phpseclib (user's preference) with one app-generated Ed25519 key; admins add its public key to servers — the private key never leaves the app.
* SSL: a valid certificate is only ever a warning (within the admin-set warning period, default 7 days); critical means invalid — the user's rule.
* Navbar: monitoring on the left (Servers, SSL), configuration and account on the right — the user's layout.
* A server has one or more of SSH, MariaDB and SSL; SSL covers several hostnames per server — the user's choice.
* Health checks run only on demand for now — the user's choice; the 5-minute interval and 30-day retention are stored/applied for a later scheduler.
* Crashed-table detection is the quick kind (information_schema + CHECK TABLE FAST QUICK on MyISAM/Aria) — the user's choice; its verified blind spots are documented in CLAUDE.md.
* Password login is opt-in per server and off by default — this machine's fail2ban bans on the first password attempt; the user said "no passwords, period" for it.
* SSH passwords are kept (encrypted) only for servers that refuse key login after the key is installed — the user chose this fallback.
* New servers default to verified MySQL TLS; existing ones were migrated to `off` so nothing broke — encrypt-only is offered with a warning for servers without a usable CA.
* MySQL is direct TCP only — phpseclib can't forward ports and the user chose not to add OpenSSH tunnels for now.
* SSH private key and MySQL passwords are encrypted with the app key, like authenticator secrets — the user's choice.
* Admins manage servers; regular users only see the list — the user's choice.
* App data lives in SQLite; MariaDB targets are reached through `pdo_mysql` — matches the PDO-based SQLite side.

---

## Future Ideas

* _agent: record ideas the user mentions but isn't building yet._
