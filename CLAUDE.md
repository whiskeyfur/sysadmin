# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Project

A PHP website served at `sys.localhost`: a system and network admin tool for multiple servers and websites. Its focus is tracking statistics and performance, plus continuous monitoring and testing of various parameters. Targets it examines include PHP stacks and MariaDB servers. MariaDB health monitoring includes detecting crashed tables, and connects through PDO (`pdo_mysql`, required in `composer.json`).

Apache serves it on port 5015 (`/etc/apache2/sites-enabled/002-sys.conf`, `ServerName sys.localhost`, DocumentRoot `public/`), so changes are live immediately. **The live site holds real accounts.** Never run end-to-end tests, failed logins or setup flows against it or its database. Use a throwaway database on a separate dev server instead (the env var overrides `.env`):

```bash
touch /tmp/e2e.sqlite && DB_DATABASE=/tmp/e2e.sqlite php leaf db:migrate
DB_DATABASE=/tmp/e2e.sqlite php -S localhost:5599 -t public public/index.php
```

To test SSH and MySQL code for real, run a throwaway `sshd` and MariaDB as your own user on high ports (never touch the system ones): `ssh-keygen` a host key into a scratch dir and start `/usr/sbin/sshd -D -e -f <config>` with `ListenAddress 127.0.0.1`, `Port 2299`, `HostKey`/`AuthorizedKeysFile`/`PidFile` in the scratch dir, `UsePAM no`, `StrictModes no`; and `mariadb-install-db --no-defaults --datadir=<dir>` then `mariadbd --no-defaults --datadir=<dir> --socket=<dir>/mysql.sock --port=3399 --bind-address=127.0.0.1`, creating a user over the socket with `mariadb -S <sock> -u $(whoami)`.

Apache runs as `www-data`: `storage/` must stay group `www-data`, group-writable, with setgid on its directories, or the database and Blade cache writes fail with a 500.

## Framework: Leaf MVC

The app is built on **Leaf MVC v5** (leafphp.dev). **When Leaf MVC best practice conflicts with any other convention in this file, Leaf wins**, except for the security rules below.

- Code lives in `app/` under `App\`, in Leaf's type-based folders (`app/controllers`, `app/models`, `app/routes`, `app/views`, `app/services`, `app/middleware`, ...). The PSR-4 mappings are already in `composer.json`.
- Controllers stay thin. Business logic (auth, user management, monitoring checks, MariaDB probes) goes in service classes in `app/services` (`App\Services\`).
- Models extend `App\Models\Model` (Eloquent via `Leaf\Model`). Database schemas are YAML files in `app/database/` and are the source of truth; apply them with `php leaf db:migrate`. The SQLite file is `storage/app/db/database.sqlite` (Leaf's default, git-ignored).
- Exceptions live in `app/exceptions` (`App\Exceptions\`, a mapping added to `composer.json`); security failures extend `SecurityException`.
- Route partials are `app/routes/_*.php` and are loaded automatically. Views use Blade (`app/views/*.blade.php`, layout `layouts/app`); styling is plain CSS in the layout.
- CSRF protection is on for every POST (`leafs/csrf`); every form needs `@csrf`. `config/auth.php` exists only because Leaf MVC reads `auth.session` to decide whether to enable CSRF and errors if it's missing.
- Route middleware (`app/middleware`) passes data to controllers with `response()->next([...])`; controllers read it with `$this->request->next('auth')`. `Authenticate` (and its subclasses `AuthenticateAllowingExpiredPassword` and `RequireAdmin`) provide an `AuthContext` (user, password-expired flag).
- Leaf pitfalls hit so far:
  - `request()->get($key)` HTML-escapes by default; pass `false` for passwords and other raw input. `request()->validate()` returns raw values.
  - Leaf validation rules are single-line regexes (`min:1` fails on multi-line input). Check such fields manually.
  - `Response` treats a `Content-Disposition: attachment` header as a file path to stream from disk. For in-memory downloads, send that header with `Leaf\Http\Headers::set()`.
  - `request()->getIp()` trusts client-supplied `Client-IP`/`X-Forwarded-For` headers. Use `Controller::clientIp()` (`REMOTE_ADDR`) for anything security-related.
  - Route-level `middleware` **replaces** a group's middleware instead of adding to it, and a middleware list only accepts callables or named middleware (class-name strings in a list are silently dropped). Routes needing two middlewares use a list of closures (see `app/routes/_admin.php`).
  - Leaf turns PHP warnings into exceptions (500s), so check `is_writable()` and similar before calls that may warn.
  - `Leaf\Controller` has public `render()` and `auth()` methods; don't name controller helpers that.
- Write everything as object-oriented as possible, within Leaf's conventions. Don't add global functions, global variables or procedural logic beyond what Leaf's own entry points (`public/index.php`, `leaf`) and route files require.
- SSH uses **phpseclib 3** (the user's preference). It has no port forwarding, so MySQL is reached over direct TCP only; don't add SSH tunnels without asking.
- Target the server's current PHP (8.3.6 at the time of writing; check with `php -v`). The `sodium`, `pdo_sqlite` and `pdo_mysql` extensions are required.

### Commands

- `composer install` installs dependencies.
- `leaf serve` (or `composer dev`) runs the dev server.
- `php leaf list` shows project commands. Use the generators rather than hand-writing boilerplate: `php leaf g:controller`, `g:model`, `g:schema`, `g:middleware`, `g:route`, `g:template`.
- `php leaf db:migrate` applies schema files; `db:seed`, `db:rollback` and `db:reset` also exist.
- `leaf context` prints a compact map of the app.
- Tests, lint and analysis use Leaf's Alchemy (config in `alchemy.yml`; Pest, PHP CS Fixer PSR-12, PHPStan level 5 on `app/`):
  - `composer run test` runs the suite (in parallel). Alchemy writes the PHPUnit config on the fly, so don't call `vendor/bin/pest` directly. To narrow a run, pass a filter regex with no spaces: `vendor/bin/alchemy test --flags=--filter=AuthService` (one file) or `--flags=--filter=expire` (tests whose names match).
  - `composer run lint` checks style, `composer run fmt` fixes it, `composer run analyse` runs PHPStan.
  - Test files are `tests/**/*.test.php`. `tests/Pest.php` gives every test in `tests/services` a fresh in-memory SQLite database migrated from the real schema files, plus `$this->clock` (a PSR-20 clock tests can move with `advance($seconds)`), `$this->cipher` (a `SecretCipher` with a throwaway app key) and `$this->passwords` (a `PasswordService` on the test clock). It loads `Leaf\Model` before building the test connection because loading that class connects to the app database and would replace it.

## Accounts and sign-in

The client decided against encryption at rest: app data is stored in plain SQLite. Git history up to commit `2aa2f1f` has the earlier encrypted design (master key, key files, registration) if it's ever needed again.

### Rules

1. **No authenticator, no access.** Signing in needs the username, the password and a current code from an authenticator app (TOTP, Google Authenticator defaults). A new or reset account can only reach the setup page (new password + authenticator), never the app. Setup must refuse accounts that already have an authenticator and aren't being forced to change their password; otherwise a password alone could replace the authenticator. A session is only created after the code is checked, which is why the app doesn't use `leafs/auth` (it puts the user in the session as soon as the password matches).
2. **Admins create all accounts.** There is no self-registration. An admin creates the account and gets a random temporary password (shown once) to hand out outside the website. At first sign-in the user must choose their own password and enrol an authenticator.
3. **Passwords expire every 30 days** (`PasswordService::MAX_AGE_DAYS`), counted from `password_changed_at`. A signed-in user with an expired password can only reach `/password` (the `Authenticate` middleware redirects everything else). New passwords need at least 12 characters and must differ from the current one.
4. **At least one admin.** Demoting or deleting the last admin is refused, and admins can't act on their own account in the admin tools.
5. **Passwords** are Argon2id hashes via Leaf's password helper (`Leaf\Helpers\Password::ARGON2`). **Authenticator secrets** are the one encrypted column: `SecretCipher` encrypts them with a key derived from `APP_KEY`, bound to `totp-secret:<user id>`, so a copy of the database alone can't generate codes. Changing `APP_KEY` makes every enrolled authenticator unreadable; users would need an admin password reset.
6. **Session revocation.** The session holds only the user id and `session_version`. Bumping `users.session_version` signs that user out everywhere; password changes and admin resets do this. A password change then restarts the current session.

### Sign-in flow (`AuthService`)

1. Rate limit check (before any password hashing).
2. Look up the username and verify the password. Unknown usernames still run a hash check so they take as long as a wrong password.
3. `must_change_password` or no authenticator → the setup page.
4. Check the code against the decrypted TOTP secret; record its time step in `totp_last_step` so it can't be reused.
5. `AuthSessionService::start()` creates the session. If the password has expired, the middleware then sends the user to `/password`.

Wrong username, wrong password and wrong code all return the same `InvalidCredentials` status and message.

**Default admin.** On a fresh install (no users), the first request to `/login` creates `admin` with the temporary password `changeme` (`AuthService::DEFAULT_ADMIN_*`), which only reaches the setup page. Until setup is done, anyone who reaches the site can claim that account, so finish setup right after installing.

### Rate limiting

Every route that hashes or checks a password (POST `/login`, `/setup`, `/password`, `/admin/users`, `/admin/users/{id}/reset-password`) is protected twice:

- **Attempt limits** (`LoginThrottleService`, sliding 15-minute window): 20 failures per IP and 10 per username (any IP, case-insensitive). A limited request gets HTTP 429 with `Retry-After`, without doing any password work, and looks the same whether or not the username exists. A successful sign-in clears that username's failures but not the IP's. Anyone can lock a username out for 15 minutes by failing 10 times; that's the accepted trade-off. Attempts are stored as SHA-256 buckets in `login_attempts`, never the typed username or IP.
- **Concurrency cap** (`LimitConcurrentLogins` middleware, `FileSemaphore` lock files in `storage/framework/locks`; lock files owned by another user are opened read-only, which `flock()` allows): at most 4 password hashes at once across all Apache workers (Argon2id uses 64 MiB each). Extra requests get HTTP 503 with `Retry-After: 5`. Controllers call `LimitConcurrentLogins::release()` as soon as the service returns.

### Services (`app/services`)

- `AuthService`: sign-in, first-login setup and password changes, returning a `LoginResult` with a `LoginStatus`; creates the default admin.
- `AuthSessionService`: the signed-in session and the session cookie flags (`Secure`, `HttpOnly`, `SameSite=Strict`, strict mode), set from `public/index.php` because Leaf's CSRF module starts the session during boot.
- `PasswordService`: hashing, policy, expiry, temporary passwords.
- `TotpService`: authenticator codes (SHA-1, 6 digits, 30 s), ±1 period for clock drift, replay protection, and a server-rendered QR code so the secret never goes to a third-party QR service.
- `SecretCipher`: `APP_KEY`-based XChaCha20-Poly1305 for authenticator secrets.
- `UserAdminService`: create accounts, reset passwords (new temporary password, authenticator removed, sessions ended), promote/demote, delete. Every method re-checks that the acting user is an admin.
- `LoginThrottleService`: the attempt limits above.

## Servers

Monitored servers are configured at `/servers` (everyone sees the list; admins manage them under `/admin/servers`). `ServerService` validates input and owns the `servers` table.

- **SSH login uses the app's own key.** `SshKeyService` generates one Ed25519 keypair on first use (`ssh_keypairs` table). The private key is encrypted with `SecretCipher` (`ssh-private-key:<id>`) and never shown or downloadable; admins copy the public key (shown on `/servers`) into each server user's `~/.ssh/authorized_keys`.
- **Host keys are verified before logging in** (`SshService`). A server's first test stops before login and shows the presented key's `SHA256:` fingerprint (same format as `ssh-keygen -lf`); the admin checks it on the server and trusts it. Trusting re-fetches the key and requires it to still match the fingerprint the admin saw. After that, a different host key stops the connection before any credentials are sent (`HostKeyMismatchException`). Keys are compared by blob (`HostKey::sameKeyAs`), and the trusted key's algorithm is pinned when connecting. Changing a server's hostname or SSH port forgets its trusted key.
- **MySQL** (`MysqlService`) is PDO over direct TCP with a 5-second timeout. The password is encrypted with `SecretCipher` (`mysql-password:<server id>`), write-only in the UI (blank on edit keeps it), and cleared when MySQL is turned off for a server.
- **MySQL TLS** (`servers.mysql_tls`): `verify` (default for new servers: chain and hostname checked against the pasted CA in `mysql_tls_ca`, or the system CA bundle), `encrypt` (TLS without checking the certificate) or `off`. Behaviour of PHP's mysqlnd, verified against a real MariaDB:
  - TLS is only turned on when `SSL_CA` is set; `SSL_VERIFY_SERVER_CERT` alone does nothing. So `encrypt` passes the system bundle with verification off.
  - With verification on, the hostname is checked against the certificate (IP SANs work for IP hosts).
  - Asking for TLS from a server without it fails ("server has gone away") instead of downgrading. `MysqlService` also checks `Ssl_cipher` after connecting and refuses an unencrypted session.
  - Every TLS failure surfaces as the same vague error, so `MysqlService::explain()` adds the likely causes.
  - PDO needs the CA as a file: the pasted CA (public) is written to a temp file only while connecting, then deleted. `CaCertificateService` validates and normalises pasted PEM.
  - A DSN host of `localhost` makes PDO use the local Unix socket, not TCP.
- **Connection test** (`ServerTestService`, admin only): SSH runs `uname -srm`, MySQL runs `SELECT VERSION()`; the outcome is stored in `last_tested_at` / `last_test_ok` / `last_test_message`.
