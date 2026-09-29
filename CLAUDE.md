# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Project

A PHP website served at `sys.localhost`: a system and network admin tool for multiple servers and websites. Its focus is tracking statistics and performance, plus continuous monitoring and testing of various parameters. Targets it examines include PHP stacks and MariaDB servers. MariaDB health monitoring includes detecting crashed tables, and connects through PDO (`pdo_mysql`, required in `composer.json`).

Apache serves it on port 5015 (`/etc/apache2/sites-enabled/002-sys.conf`, `ServerName sys.localhost`, DocumentRoot `public/`), so changes are live immediately. Apache runs as `www-data`: `storage/` must stay group `www-data`, group-writable, with setgid on its directories, or the database and Blade cache writes fail with a 500.

## Framework: Leaf MVC

The app is built on **Leaf MVC v5** (leafphp.dev). **When Leaf MVC best practice conflicts with any other convention in this file, Leaf wins**, except for the encryption rules below, which are hard security requirements.

- Code lives in `app/` under `App\`, in Leaf's type-based folders (`app/controllers`, `app/models`, `app/routes`, `app/views`, `app/services`, `app/middleware`, ...). The PSR-4 mappings are already in `composer.json`.
- Controllers stay thin. Business logic (crypto, key handling, monitoring checks, MariaDB probes) goes in service classes in `app/services` (`App\Services\`).
- Models extend `App\Models\Model` (Eloquent via `Leaf\Model`). Database schemas are YAML files in `app/database/` and are the source of truth; apply them with `php leaf db:migrate`. The SQLite file is `storage/app/db/database.sqlite` (Leaf's default, git-ignored).
- Exceptions live in `app/exceptions` (`App\Exceptions\`, a mapping added to `composer.json`); key-service failures extend `KeyException`.
- Route partials are `app/routes/_*.php` and are loaded automatically. Views use Blade (`app/views/*.blade.php`, layout `layouts/app`); styling is plain CSS in the layout.
- CSRF protection is on for every POST (`leafs/csrf`); every form needs `@csrf`. `config/auth.php` exists only because Leaf MVC reads `auth.session` to decide whether to enable CSRF and errors if it's missing.
- Route middleware (`app/middleware`) passes data to controllers with `response()->next([...])`; controllers read it with `$this->request->next('auth')`. `Authenticate` and `RequireAdmin` provide an `AuthContext` (user, master key, role).
- Leaf pitfalls hit so far:
  - `request()->get($key)` HTML-escapes by default; pass `false` for passwords and other raw input. `request()->validate()` returns raw values.
  - Leaf validation rules are single-line regexes (`min:1` fails on multi-line input such as the key file JSON). Check such fields manually.
  - `Response` treats a `Content-Disposition: attachment` header as a file path to stream from disk. For in-memory downloads, send that header with `Leaf\Http\Headers::set()` (see `KeyFileController`).
- Write everything as object-oriented as possible, within Leaf's conventions. Don't add global functions, global variables or procedural logic beyond what Leaf's own entry points (`public/index.php`, `leaf`) require.
- Target the server's current PHP (8.3.6 at the time of writing; check with `php -v`). The `sodium`, `pdo_sqlite` and `pdo_mysql` extensions are required.

### Commands

- `composer install` installs dependencies.
- `leaf serve` (or `composer dev`) runs the dev server.
- `php leaf list` shows project commands. Use the generators rather than hand-writing boilerplate: `php leaf g:controller`, `g:model`, `g:schema`, `g:middleware`, `g:route`, `g:template`.
- `php leaf db:migrate` applies schema files; `db:seed`, `db:rollback` and `db:reset` also exist.
- `leaf context` prints a compact map of the app.
- Tests, lint and analysis use Leaf's Alchemy (config in `alchemy.yml`; Pest, PHP CS Fixer PSR-12, PHPStan level 5 on `app/`):
  - `composer run test` runs the suite (in parallel). Alchemy writes the PHPUnit config on the fly, so don't call `vendor/bin/pest` directly. To narrow a run, pass a filter regex with no spaces: `vendor/bin/alchemy test --flags=--filter=VaultService` (one file) or `--flags=--filter=rotation` (tests whose names match).
  - `composer run lint` checks style, `composer run fmt` fixes it, `composer run analyse` runs PHPStan.
  - Test files are `tests/**/*.test.php`. `tests/Pest.php` gives every test in `tests/services` a fresh in-memory SQLite database migrated from the real schema files, plus `$this->crypto`, a `CryptoService` at the cheapest Argon2id cost, and `$this->clock`, a PSR-20 clock tests can move with `advance($seconds)` (pass it to `TotpService`). It loads `Leaf\Model` before building the test connection because loading that class connects to the app database and would replace it.

## Data-at-rest encryption model (core design constraint)

Application data is stored in a **SQLite database that is encrypted at rest**. `pdo_sqlite` can't encrypt the database file, so encryption happens at the application level: sensitive columns hold libsodium ciphertext encrypted with the data key, and only lookup fields are stored in plaintext. No password means no user data, and no user data means the database can't be decrypted. The server alone must never be able to read the data.

### Sign-in flow (`AuthService`)

1. The client submits their username, password and authenticator code.
2. The user row is found by its plaintext username. The password, with that row's salt and KDF parameters, derives the user's wrapping key. Unknown usernames still run one key derivation so they take as long as a wrong password.
3. The wrapping key decrypts the user's encrypted **user-data field**, which contains the **master key** and the **TOTP secret**.
4. No authenticator yet, or a forced password change → the setup page (new password + enrol authenticator), never the app.
5. The code is checked against the TOTP secret, and its time step is recorded in `totp_last_step` so it can't be reused.
6. The master key must unlock the vault; if not, it was rotated and the user must upload the current key file.
7. A `pending` role means an admin hasn't approved the account yet.
8. On success, `AuthSessionService::start()` seals the master key into the session and cookie (rule 7).

Wrong username, wrong password and wrong code all return the same `InvalidCredentials` status and message. Each step that needs the password again (setup, key replacement) asks for it again rather than keeping it between requests.

```php
$user = User::where('username', $username)->firstOrFail();
$masterKey = (new UserKeyService())->getMasterKeyFromUserDataField($user, $password);
```

### Key services (`app/services`)

- `CryptoService`: libsodium primitives. Argon2id key derivation and XChaCha20-Poly1305 encryption stored as `base64(nonce . ciphertext)`. Every ciphertext is bound to a context string (for example `user-data:<username>`), so a value copied to another row or column fails to decrypt. `wipe()` zeroes secrets.
- `VaultService`: the single `vaults` row holding the data key wrapped by the master key. Initialize, unwrap/verify with a master key, and rotate the master key.
- `UserKeyService`: everything per user. First-admin bootstrap, registration with a key file's master key, `unlock()`/`getMasterKeyFromUserDataField()`, password change, admin reset, master key rotation and replacing a stale master key.
- `KeyFileService`: the key file format (JSON with `format`, `version`, base64 `key`). Strings only, never files.
- `SessionKeyService`: seals the master key for a login session (rule 7).
- `TotpService`: authenticator codes (SHA-1, 6 digits, 30 s, the Google Authenticator defaults), ±1 period for clock drift, replay protection, and a server-rendered QR code so the secret never goes to a third-party QR service.
- `AuthService`: the sign-in, setup, key replacement and registration flows, returning a `LoginResult` with a `LoginStatus`. Also creates the default admin on a fresh install.
- `AuthSessionService`: the signed-in session (rule 7 wiring) and the session cookie flags, set from `public/index.php` because Leaf's CSRF module starts the session during boot.
- `UserAdminService`: listing users, approving/rejecting pending registrations, and counting admins for the two-admin rule.

Following Leaf practice, `App\Models\User` is a plain Eloquent model that holds the stored (encrypted) columns, and the unwrapping logic lives in a service. Don't use `php leaf scaffold:auth` or `leafs/auth` as-is: login in this app *is* the successful unwrap of the user-data field, not a check against a stored password hash.

### Required rules

Every piece of code that touches keys, users or sessions must follow all of these rules:

1. **The master key is random bytes, shared as a key file outside the website.** The app generates it with `random_bytes(32)` and offers it as a downloadable **key file**. Users pass that file to co-workers by other means (not through the site), and each co-worker uploads it to set the master key on their own account. The master key is never a human-chosen password and is never typed.
2. **Password → wrapping key uses Argon2id.** Use `sodium_crypto_pwhash` (`SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13`) with a random salt per user. Never use fast hashes (SHA-*, MD5) or `password_hash` output as a key.
3. **All encryption is authenticated.** Use libsodium AEAD (`sodium_crypto_secretbox` or `sodium_crypto_aead_xchacha20poly1305_ietf_*`) with a fresh random nonce each time. A wrong password or tampered ciphertext must fail with an explicit error, never return garbage.
4. **Only lookup, KDF and replay metadata are plaintext.** The user row stores the username, salt, KDF parameters (opslimit/memlimit) and `totp_last_step` in plaintext; nonces are prefixed to each ciphertext. Everything else about the user, including the TOTP secret, goes inside the encrypted user-data field, with one exception: the role (`pending`/`user`/`admin`) is in the `role` column encrypted with the **data key**, because other admins must be able to read it (for approvals, the two-admin rule and admin checks) without the user's password.
5. **Each user sets their master key by uploading the key file.** The server never wraps a master key for someone else, because it can't do that without their password. At registration, or whenever a user replaces their key, they upload the key file along with their password, and it is wrapped under their own password-derived key. Before saving, the uploaded key must be checked by unwrapping the stored data key with it; if that authenticated decryption fails, reject the upload. Bootstrap is the one exception: the first user has nothing to check against, so the app generates the master key and the data key and has them download the key file.
6. **Removing a user triggers key rotation.** A removed user has already seen the master key, so deleting their row is not enough. Use a two-tier hierarchy: the master key wraps a separate **data key**, and the data key encrypts the SQLite data. Only admins can rotate. To rotate, a logged-in admin generates a new master key, the data key is re-wrapped under it, and they download the new key file and share it outside the website. Every other user's stored master key is now stale: at login their copy fails to unwrap the data key, and they must upload the new key file before they can use the site. If the removed user may have extracted the data key itself, rotate the data key and re-encrypt the data too.
7. **The key never reaches disk between requests.** `$_SESSION` is stored on disk, so it must never hold a plaintext key. At login, generate a random per-session key and use it to encrypt the master key. Store that ciphertext in the server session and send the session key to the client only in a `Secure`, `HttpOnly`, `SameSite=Strict` cookie. Each request combines the two in memory. Logging out destroys the session and expires the cookie.
8. **Plaintext secrets are wiped after use.** Neither the `User` model nor any service may keep the password as a property. Derive the wrapping key, then wipe (`CryptoService::wipe()` or `UnlockedUser::wipe()`) the password, the wrapping key and any other plaintext key material as soon as it is no longer needed. This is best effort: PHP shares and copies strings, so a buffer is only zeroed when nothing else references it. Controllers can't meaningfully wipe request input (Leaf keeps its own copy of the parsed body), so they must simply never copy passwords or keys anywhere longer-lived: session, cookies other than the rule 7 cookie, logs, properties or views.
9. **No authenticator, no access.** Signing in needs the username, the password and a current code from an authenticator app (TOTP). An account without an enrolled authenticator can only reach the setup page. The setup path must refuse accounts that already have an authenticator and aren't being forced to change their password; otherwise a password alone could replace the authenticator.

### Other consequences

- Changing a password re-wraps only that user's copy of the master key under the new password-derived key. The data is not touched.
- **Default admin.** On a fresh install (no vault), the first request to `/login` creates the admin `admin` with password `changeme` (`AuthService::DEFAULT_ADMIN_*`). That password only reaches the setup page, which requires a new password and an authenticator. Until then, anyone who reaches the site with the default password can claim the account, so finish setup right after installing.
- **Registrations need admin approval.** Registering takes the key file, a password and a working authenticator, and creates a `pending` account that can't sign in. An admin approves it as a user or an admin, or rejects it (deletes the row). A rejected user still saw the key file, so rotate the master key if that matters.
- **Only admins can reset a password.** Users can't reset their own forgotten password, even with the key file. A logged-in admin sets a temporary password for the user, and the current master key (from the admin's unlocked session, checked against the data key) is wrapped under it. The admin can't read the old user-data field, so the user's authenticator is removed too: at next login they must set a new password and enrol an authenticator again. Non-admins must never be able to reach this path, and a user must never be able to make themselves an admin.
- **There must always be at least two admins.** The first user becomes an admin automatically, and only existing admins can make other users admins. Any action that would bring the admin count below two (demoting, deleting or disabling an admin) must be refused. Until a second admin exists, the system is in setup and shows a persistent warning to create one.
- If every user loses their password and every copy of the key file is gone, the data is permanently unrecoverable, by design.
- The key file unlocks all data for anyone who also has a login, so it is a secret. The server must never keep a copy of it: uploaded key files are processed in memory and never written to disk, and the download is generated in memory and never cached. PHP's normal file upload (`$_FILES`) writes the file to `upload_tmp_dir` on disk, so don't use it for key files. Instead, read the file in the browser and submit its contents as a POST field.
- Never persist any plaintext key (master, data, wrapping or session key) to disk, logs, caches, environment variables, config or long-lived key-holding processes without explicit approval.
