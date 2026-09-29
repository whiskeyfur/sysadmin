# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

A PHP website served at `sys.localhost`: a system and network admin tool for multiple servers and websites. Its focus is tracking statistics and performance, plus continuous monitoring and testing of various parameters. Targets it examines include PHP stacks and MariaDB servers. MariaDB health monitoring includes detecting crashed tables, and connects through PDO (`pdo_mysql`, required in `composer.json`).

Run `composer install` to set up dependencies and the autoloader (`vendor/autoload.php`). Add lint and test commands here once that tooling is chosen.

## Code style

Write everything as object-oriented as possible. All logic lives in classes (like `UserModel`). Entry-point scripts only bootstrap and hand off to objects. Don't add global functions, global variables or procedural logic.

- **PHP version:** target the server's current PHP (8.3.6 at the time of writing; check with `php -v`), and use its language features freely. The `sodium`, `pdo_sqlite`, `pdo_mysql` and `mysqli` extensions are installed.
- **Autoloading:** Composer PSR-4. All classes live under `src/`, mapped to the root namespace `Sys\`. After adding or moving a namespace mapping, run `composer dump-autoload`.
- **Namespaces follow functional areas**, with directories matching them. For example, `Sys\Auth\UserModel` lives in `src/Auth/UserModel.php`, and crypto, monitoring and MariaDB checks each get their own area. Put a class in the area it serves, not in a namespace by type (no catch-all `Models\` or `Helpers\`).

## Data-at-rest encryption model (core design constraint)

Application data is stored in a **SQLite database that is encrypted at rest**. No password means no user data, and no user data means the database can't be decrypted. The server alone must never be able to read the data.

### Unlock flow

1. The client submits their username and password.
2. The user row is found by its plaintext username. The password, with that row's salt and KDF parameters, derives the user's wrapping key.
3. The wrapping key decrypts the user's encrypted **user-data field**, which contains the **master key**.
4. The master key decrypts the data at rest (see the key hierarchy below).

```php
$user = new UserModel("username", "password");
$masterKey = $user->getMasterKeyFromUserDataField();
```

### Required rules

Every piece of code that touches keys, users or sessions must follow all of these rules:

1. **The master key is random bytes, shared as a key file outside the website.** The app generates it with `random_bytes(32)` and offers it as a downloadable **key file**. Users pass that file to co-workers by other means (not through the site), and each co-worker uploads it to set the master key on their own account. The master key is never a human-chosen password and is never typed.
2. **Password → wrapping key uses Argon2id.** Use `sodium_crypto_pwhash` (`SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13`) with a random salt per user. Never use fast hashes (SHA-*, MD5) or `password_hash` output as a key.
3. **All encryption is authenticated.** Use libsodium AEAD (`sodium_crypto_secretbox` or `sodium_crypto_aead_xchacha20poly1305_ietf_*`) with a fresh random nonce each time. A wrong password or tampered ciphertext must fail with an explicit error, never return garbage.
4. **Only lookup and KDF metadata are plaintext.** The user row stores the username, salt, KDF parameters (opslimit/memlimit) and nonce in plaintext. Everything else about the user goes inside the encrypted user-data field.
5. **Each user sets their master key by uploading the key file.** The server never wraps a master key for someone else, because it can't do that without their password. At registration, or whenever a user replaces their key, they upload the key file along with their password, and it is wrapped under their own password-derived key. Before saving, the uploaded key must be checked by unwrapping the stored data key with it; if that authenticated decryption fails, reject the upload. Bootstrap is the one exception: the first user has nothing to check against, so the app generates the master key and the data key and has them download the key file.
6. **Removing a user triggers key rotation.** A removed user has already seen the master key, so deleting their row is not enough. Use a two-tier hierarchy: the master key wraps a separate **data key**, and the data key encrypts the SQLite data. To rotate, a logged-in user generates a new master key, the data key is re-wrapped under it, and they download the new key file and share it outside the website. Every other user's stored master key is now stale: at login their copy fails to unwrap the data key, and they must upload the new key file before they can use the site. If the removed user may have extracted the data key itself, rotate the data key and re-encrypt the data too.
7. **The key never reaches disk between requests.** `$_SESSION` is stored on disk, so it must never hold a plaintext key. At login, generate a random per-session key and use it to encrypt the master key. Store that ciphertext in the server session and send the session key to the client only in a `Secure`, `HttpOnly`, `SameSite=Strict` cookie. Each request combines the two in memory. Logging out destroys the session and expires the cookie.
8. **Plaintext secrets are wiped after use.** `UserModel` must not keep the password as a property. Derive the wrapping key, then `sodium_memzero` the password, the wrapping key and any other plaintext key material as soon as it is no longer needed.

### Other consequences

- Changing a password re-wraps only that user's copy of the master key under the new password-derived key. The data is not touched.
- **Only admins can reset a password.** Users can't reset their own forgotten password, even with the key file. A logged-in admin sets a temporary password for the user, and the current master key (from the admin's unlocked session, checked against the data key) is wrapped under it. The user must change the temporary password at their next login. Non-admins must never be able to reach this path, and a user must never be able to make themselves an admin.
- **There must always be at least two admins.** The first user becomes an admin automatically, and only existing admins can make other users admins. Any action that would bring the admin count below two (demoting, deleting or disabling an admin) must be refused. Until a second admin exists, the system is in setup and shows a persistent warning to create one.
- If every user loses their password and every copy of the key file is gone, the data is permanently unrecoverable, by design.
- The key file unlocks all data for anyone who also has a login, so it is a secret. The server must never keep a copy of it: uploaded key files are processed in memory and never written to disk, and the download is generated in memory and never cached. PHP's normal file upload (`$_FILES`) writes the file to `upload_tmp_dir` on disk, so don't use it for key files. Instead, read the file in the browser and submit its contents as a POST field.
- Never persist any plaintext key (master, data, wrapping or session key) to disk, logs, caches, environment variables, config or long-lived key-holding processes without explicit approval.
