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

`sys` (served at `sys.localhost`): a system and network admin tool that tracks statistics and performance of multiple servers and websites, continuously monitoring PHP stacks and MariaDB servers (including crashed tables). Accounts are created by admins; sign-in needs the username and any one method an admin turned on (password, authenticator code, passkey/security key). Security rules are in `CLAUDE.md`.

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

* 2026-09-29 — Apache module (menu, Test, Reports, Add, Settings): over SSH from Apache's own configuration (control program, DUMP_INCLUDES, log directives, variables resolved) and incremental log reads; mod_status used only when configured; error log history and 5-minute access totals (`ApacheService`, `ApacheConfigParser`, `ApacheLogParser`, `ApacheReportService`, `RemoteLogs`).
* 2026-09-29 — App settings page (navbar right) with the sign-in idle timeout (default 30 min); sessions moved to storage/framework/sessions with the app's own GC, since Ubuntu's sessionclean ended them after 24 idle minutes regardless (`AuthSessionService::configureSessionStorage()/idleTooLong()`).
* 2026-09-29 — Import MariaDB users ('name'@'%' only) as database accounts without passwords, unusable for logins until one is recorded; every account records its origin (manual, server settings, import) (`AccountService::importMysqlUsers()`, `Account::canLogIn()`, `accounts.origin*`).
* 2026-09-29 — MariaDB data without SSH: disk space (DISKS plugin), file I/O (performance_schema) and database sizes as optional checks, charted on the MariaDB report; scheduled SSL checks add new SAN hostnames from valid certificates (setting) (`DisksCheck`, `FileIoCheck`, `DatabaseSizeCheck`, `SslMonitorService::addNames()`).
* 2026-09-29 — MariaDB logs imported on a schedule (every 60 min by default, MariaDB setting), reading only new bytes per file (inode + offset) and still importing when only MariaDB is down; last outcome shown on the report (`ScheduledCheckService::importLogIfDue()`, `MariadbLogService::importNow()/readNew()`).
* 2026-09-29 — SSL checks scheduled on their own interval, once a day by default (SSL setting `ssl_check_hours`, 1–168): each run checks certificates not checked in that long; server health checks stay every 5 minutes (`ScheduledCheckService::sslDue()`).
* 2026-09-29 — Apache without its control program in PATH: the admin sets the main configuration file (`servers.apache_config_file`), read with its includes followed (`ApacheConfigFiles`).
* 2026-09-29 — Apache in Docker/Podman containers: found when the host has no working `-S`, remembered (`servers.apache_container`), commands run via `ContainerShell`, container output read with `logs --since`; Apache 2.2 read from `-V` + its main file (`ApacheConfigParser::compiled()`/`answered()`).
* 2026-09-29 — Apache logs can be set by hand per server (`apache_error_logs`/`apache_access_logs`); virtual hosts discovered from the configuration (`apache_vhosts`), with a Vhosts menu: List (SSL coverage, Monitor link) and Reports (per-vhost traffic/errors from its logs).
* 2026-09-29 — Database setup wizard (`/install/database`, install code) and `php leaf app:db-setup`: MariaDB/MySQL, PostgreSQL or SQLite; an admin account gets the database plus a restricted app user with a generated password; existing SQLite data copied; connection saved encrypted in storage/app/db/connection.json (`DatabaseConfig`, `DatabaseSetupService`).
* 2026-09-29 — Sign-in methods set by admins (App settings): password, authenticator, passkey/security key, each Off/Optional/Required; required = must set up, any one signs in. Profile page (/profile) for setup and changes; WebAuthn via lbuchs/webauthn parsing with the app's own ceremony checks (`LoginMethodService`, `PasskeyService`, `ProfileService`, `passkeys` table, `users.login_password`).
* 2026-09-29 — Managing this machine's Apache (/admin/apache/local): through a small standalone root helper (bin/sys-apache-helper, installed with sudo, one sudoers rule), not by running the app as root — the user's choice; admins from this machine only, fresh check per change, configtest-and-undo, audit log (`LocalApacheService`, `apache_admin_log`).
* 2026-09-29 — Rewrite rule editor (config sections and .htaccess, line-precise edits, helper v2 for .htaccess) and URL simulator (`ApacheConfigTree`, `ApacheSimulator`), verified against a real apache2 in the tests.
* 2026-09-29 — Virtual host editor (fields or the block's text, values as written) for this machine and monitored servers; remote changes rely on the SSH user's sudo rules (checked with `sudo -n -l`, configtest then undo) — the user's choice over installing anything there. Simulator applies server/vhost-level DirectoryIndex etc. SSL checks complete a missing intermediate from AIA like browsers (public http only) and note it; severity is an SSL setting (Info/Warning/Danger).
* 2026-09-29 — Simulator handles Order/Allow/Deny/Satisfy (section replaces, not merges — verified on real Apache); access lines editable per scope; collapsible sections on the Apache page.
* 2026-09-29 — (feature/consolidated) The Apache page's Global/Sites/Vhosts/Snippets/Modules sections merged into one Config view: the full configuration with includes opened in place, as Apache reads it (`ApacheConfigService`), editable in place, hide-comments checkbox.

---

## Known Decisions

* This is a Leaf MVC app — all important files are in the `app` directory.
* Leaf MVC best practice wins over other conventions — the user's explicit rule; the security rules in `CLAUDE.md` are the exception.
* No encryption at rest, no master key, no key files, no self-registration — the client's decision (2026-09-29); the earlier encrypted design is in git history up to `863d3e9`.
* Sign-in methods are an admin setting (password, authenticator, passkey/security key; Off/Optional/Required; required = must set up, any one method signs in) — the user's decision (2026-09-29), replacing code-only sign-in, which is still the default. Admins set a one-time password used only for setup.
* Sign-in gets a 30-failures-per-day cap per username on top of the 15-minute limits — a 6-digit code is the only secret, so slow guessing must stay improbable; lockouts are cleared by `app:reset-admin`.
* At least one admin; admins can't act on their own account — the client said one admin is fine.
* Custom sign-in instead of `leafs/auth` — `leafs/auth` is built around passwords and creates the session as soon as one matches.
* Authenticator secrets are encrypted with a key derived from `APP_KEY` — the user chose this so a copied database alone can't generate codes.
* Authenticator required by default (the user's earlier "no authenticator = no access"); admins can change it. Passkeys require user verification (PIN/biometric), since one alone signs in.
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
* Scheduled checks back off after connection failures (up to hourly) — this machine's sshd bans on failures (fail2ban) and MariaDB blocks hosts after max_connect_errors; a check every 5 minutes against a broken login would otherwise get the app banned.
* Log locations are read from MariaDB's own option files, never assumed — the user's explicit instruction; only when `my_print_defaults` is missing does the import fall back to the documented /etc/my.cnf and /etc/mysql/my.cnf.
* Apache is monitored from its configuration and log files over SSH, not via mod_status; mod_status only adds live worker figures when the configuration has it — the user's instruction ("don't assume mod_status or /server-status"; "if available, great, degrade gracefully").
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
* 2026-09-29 — Apache page: the sign-in check moved from a card at the top into a dialog opened by each change button.
* 2026-09-29 — URL simulator trace as a table: step, directive as written, file, line, explanation.
* 2026-09-29 — SSL: every SAN name becomes its own entry checked directly through DNS; /ssl rows fold out only when not OK, showing the failing checks; hostname lists removed from /ssl.
* 2026-09-29 — Reports: start/end date fields and drag-to-zoom on every chart (`HistoryReport::period()`, range "START-END").
* 2026-09-29 — Apache access logs: each request stored (apache_access_entries, kept apache_access_keep_days), each log read in its own LogFormat (AccessLogFormat, per-vhost nicknames).
* 2026-09-29 — fail2ban: banned clients marked in the access log (bans read with each Apache import); admins right-click a client to ban/unban over SSH with sudo -n fail2ban-client.
* 2026-09-29 — extras/fail2ban: web-abusers and web-abusers-4xx jails (permanent bans) with filters tested on real logs; the ban dialog preselects web-abusers.
* 2026-09-29 — Apache report access/error logs paged over AJAX (/apache/reports/entries), searched and sorted in SQL.
* 2026-09-29 — MariaDB report log paged over AJAX too; Apache access log filters: status, client, hide localhost.
* 2026-09-29 — app:apache-history imports rotated Apache logs (idempotent); run for localhost.
* 2026-09-29 — Chart legends hide/show lines with the scale refitting; access log banned/not-banned filter.
* 2026-09-29 — Access log right-click: show only a client's requests (exact IP match), search for a URL.
* 2026-09-29 — fail2ban protection: protected addresses in every jail's ignoreip (re-applied each read), can't be banned; banned ones can't be protected.
* 2026-09-29 — Access log: banned IPs red, protected green (no badges); protected/not protected filter.
* 2026-09-29 — Access log filters: status and banned/protected as checkboxes.
* 2026-09-29 — extras/apache/enable-unique-id.sh: request IDs at the end of Apache log lines (fail2ban-safe placement, checked on real Apache).
* 2026-09-29 — Request IDs stored for Apache requests and errors; Show request / N errors links between the two logs.
* 2026-09-29 — Access log filters are tri-state (any / only ✓ / not ✗): status, banned, protected, localhost.
* 2026-09-29 — Blocklists: blocklist.de and Spamhaus DROP downloaded daily, access-log clients checked locally; amber colour and Listed filter.
* 2026-09-29 — Section navigator (scrollspy) at the top right of every page with 3+ sections.
* 2026-09-29 — Sticky header; section navigator always just under it; scroll padding follows the header's height.
* 2026-09-29 — Phones: compact header (sys + ☰ Menu panel).
* 2026-09-29 — MariaDB query tool: one statement on many servers, collated; own login kept encrypted in the session (admins may use stored accounts).
* 2026-09-29 — Brand renamed SysAdmin, links to /servers: every server with colour-coded report buttons (SSH, MariaDB, Apache, Vhosts).
* 2026-09-29 — SSL report table: the latest check per certificate (per place served) only.
* 2026-09-29 — SSL checks record the host contacted and the address reached; the report table is one row per certificate and origin (load balancers).
* 2026-09-29 — Query tool logins: each user's private list of accounts (validated on every server chosen before saving, encrypted), chosen per server; the session login is gone.
* 2026-09-29 — Database users (admins): create/grant/revoke/password/drop MariaDB accounts across servers whose monitoring login has CREATE USER/ALL WITH GRANT OPTION; per-database presets, fresh sign-in check, logged in db_user_changes, optionally tracked in Accounts. Query tool: blank passwords allowed, 1698 (unix_socket) not counted as a wrong password, accounts listed under the Query form; admins can clear their own refused-login wait from the alert.
* 2026-09-30 — MariaDB browser (/mariadb/browse): server → database → table, CREATE statement and paged rows, read-only session, privileges from each level up; logs in with the user's own accounts (admins: stored). Database users changes now confirmed in a dialog with password or passkey only (acct_password; form fields db_username/db_password). On an account's page, set access / remove access / password changes are queued and executed together after one confirmation. Database fields autocomplete databases, then tables after "db."; grants can target db.table. Access is removed with a ✕ on each grant line (struck through when queued, green + to undo); queued grants show in green under the grants.
* 2026-09-30 — MariaDB browser hides mysql, performance_schema and sys (not listed, not opened); table definitions start folded. Database users: each executed change's result is a green/red alert at the top of the account page.
* 2026-09-30 — Access log rows with errors (by request ID) fold out their error log entries (up to 20) under the row; access log filters on one line at one height.
* 2026-09-30 — App settings shows the app's own database connection (read only, no password).
* 2026-09-30 — Apache report's Requests table: a paged row per day, clicking a day folds out its hours. X-Request-ID response header added to enable-unique-id.sh. Link-style buttons no longer fill in on hover.
* 2026-09-30 — Database users: a blank password on create makes a socket login (unix_socket / auth_socket), host localhost or % only.
