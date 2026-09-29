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

`sys` (served at `sys.localhost`): a system and network admin tool that tracks statistics and performance of multiple servers and websites, continuously monitoring PHP stacks and MariaDB servers (including crashed tables). Accounts are created by admins; sign-in needs the username and an authenticator code (no user passwords). Security rules are in `CLAUDE.md`.

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

* 2026-09-29 — Add item in each module's menu: SSL › Add (`/admin/ssl/new`, the certificate form moved off the SSL list), SSH › Add and MariaDB › Add (`/admin/ssh/new`, `/admin/mariadb/new`: that module's server form) (`SslController::create()`, `ServerConfigController::createSsh()/createMariadb()`).
* 2026-09-29 — Servers managed per module: SSH/MariaDB pages add (new or existing server), edit and remove only their module; MariaDB logins pick database accounts; deleting a server drops its certificate links; menu highlights only the current item (`ServerService::saveModule()`/`removeModule()`, `ServerConfigController`, `servers/form.blade.php`).
* 2026-09-29 — Accounts split into SSH and database accounts (own menus; mixed legacy accounts split in two); Reports placeholders first in each menu; all tables sortable and filterable; SSL list wider with an Expires column; SSH/MariaDB/overview pages wide and top-aligned (`AccountService::assignServices()`, `ReportController`, `layouts/app.blade.php`, `SslCertificate::expiresAt()`).
* 2026-09-29 — SSL list: hostnames fold out in a row under each certificate; `config/app.php` published so `VIEWS_CACHE_PATH` gives dev servers their own Blade cache (a shared one broke the live site with touch() errors) (`ssl/index.blade.php`, `config/app.php`).
* 2026-09-29 — Left navbar items are dropdowns: SSL [Test, Settings], SSH [Test, Accounts, Settings], MariaDB [Test, Settings]; settings split per area (`/admin/settings/{section}`), with new MariaDB threshold settings (`SettingsService::SECTIONS`, `SettingsController`, `HealthCheckService::defaultChecks()`, `layouts/app.blade.php`).

---

## Known Decisions

* This is a Leaf MVC app — all important files are in the `app` directory.
* Leaf MVC best practice wins over other conventions — the user's explicit rule; the security rules in `CLAUDE.md` are the exception.
* No encryption at rest, no master key, no key files, no self-registration — the client's decision (2026-09-29); the earlier encrypted design is in git history up to `863d3e9`.
* Users have no passwords: sign-in is username + authenticator code; admins set a one-time password used only to enrol the authenticator — the client's requirement (it replaced chosen passwords with 30-day expiry).
* Code-only sign-in gets a 30-failures-per-day cap per username on top of the 15-minute limits — a 6-digit code is the only secret, so slow guessing must stay improbable; lockouts are cleared by `app:reset-admin`.
* At least one admin; admins can't act on their own account — the client said one admin is fine.
* Custom sign-in instead of `leafs/auth` — `leafs/auth` is built around passwords and creates the session as soon as one matches.
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
* Host keys can be verified through the account at setup, as an explicit admin choice next to the manual fingerprint check — the user asked for it; the page warns it's weaker (an impostor in the middle sees the password and can fake the answer).
* Non-admins can test servers and run checks, with a per-server cooldown — the user wants everyone able to test; the cooldown keeps repeated clicks from tripping fail2ban on monitored servers.
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
