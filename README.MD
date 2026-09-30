# sys

A system and network admin tool for monitoring servers and websites, built on [Leaf MVC](https://leafphp.dev).

- **Servers:** disk, load and memory over SSH; MariaDB/MySQL health (connections, crashed tables, replication, buffer pool).
- **SSL:** certificates downloaded straight from each site and checked like a browser would (trusted issuer, matching name, expiry).
- **Accounts:** created by admins; every sign-in needs a password and an authenticator app; passwords expire every 30 days.

## Requirements

- PHP 8.3 or newer with the `sodium`, `openssl`, `pdo_sqlite`, `pdo_mysql`, `mbstring`, `xml`, `curl` and `zip` extensions
- [Composer](https://getcomposer.org) (PHP's package manager)
- The [Leaf CLI](https://leafphp.dev/docs/cli/) (optional, but handy: `leaf serve`, generators)

## 1. Install PHP and Composer

### Windows

1. Install PHP: download the latest **VS16/VS17 x64 Non Thread Safe** zip from <https://windows.php.net/download/>, unzip it to `C:\php`, and add `C:\php` to your `PATH` (Start → "Edit the system environment variables" → Environment Variables → `Path` → New).
2. In `C:\php`, copy `php.ini-development` to `php.ini`, then remove the `;` in front of these lines:

   ```ini
   extension_dir = "ext"
   extension=curl
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   extension=pdo_sqlite
   extension=sodium
   extension=zip
   ```

3. Install Composer with **Composer-Setup.exe** from <https://getcomposer.org/download/>. It finds your PHP and adds `composer` to your `PATH`.
4. Open a **new** terminal and check: `php -v` and `composer --version`.

### Linux (Debian / Ubuntu)

1. Install PHP and the extensions:

   ```bash
   sudo apt update
   sudo apt install php8.3-cli php8.3-sqlite3 php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip unzip
   ```

   (`sodium` and `openssl` come with `php8.3-cli`. On other distributions, install the equivalent packages.)

2. Install Composer with the official installer, which checks the download's signature:

   ```bash
   EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
   php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
   ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
   if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then echo 'ERROR: Invalid installer checksum' >&2; rm composer-setup.php; exit 1; fi
   php composer-setup.php --quiet && rm composer-setup.php
   sudo mv composer.phar /usr/local/bin/composer
   ```

3. Check: `php -v` and `composer --version`.

### macOS

1. Install [Homebrew](https://brew.sh) if you don't have it.
2. Install PHP (the extensions above are included) and Composer:

   ```bash
   brew install php composer
   ```

3. Check: `php -v` and `composer --version`.

## 2. Install the Leaf CLI

The same on every system:

```bash
composer global require leafs/cli
```

Composer puts the `leaf` command in its global `bin` folder. Find that folder with:

```bash
composer global config bin-dir --absolute
```

If `leaf --version` then says "command not found", add that folder to your `PATH`:

- **Linux** (usually `~/.config/composer/vendor/bin`), in `~/.bashrc`:

  ```bash
  export PATH="$PATH:$HOME/.config/composer/vendor/bin"
  ```

- **macOS** (usually `~/.composer/vendor/bin`), in `~/.zshrc`:

  ```bash
  export PATH="$PATH:$HOME/.composer/vendor/bin"
  ```

- **Windows** (usually `%APPDATA%\Composer\vendor\bin`): Composer-Setup normally adds it for you. If not, add it to `Path` the same way as PHP above.

Open a new terminal and check with `leaf --version`.

## 3. Set up the project

```bash
git clone https://github.com/whiskeyfur/sysadmin.git
cd sysadmin
composer install
```

### Create `.env` first

> **Don't skip this.** The app's settings, including its encryption key, live in a file called `.env` in the project folder. Git doesn't include it, so every new copy of the project needs one made from `.env.example`.

```bash
cp .env.example .env                 # Linux, macOS, Git Bash, PowerShell
copy .env.example .env               # Windows Command Prompt (cmd)
```

Check it's there before going on: `ls -a` (Linux/macOS) or `dir /a` (Windows) should list `.env`.

Then generate the encryption key and create the database:

```bash
php leaf key:generate                # writes APP_KEY into .env
php leaf db:migrate                  # creates the SQLite database in storage/app/db/
```

- If you forget, the app covers for you on the first web request: it creates `.env` from `.env.example` and generates `APP_KEY` if either is missing. For that, the web server's user must be able to write `.env` (and the project folder, if `.env` doesn't exist yet); otherwise the page tells you to run `php leaf key:generate`.
- Keep `APP_KEY` once it's set: it encrypts stored secrets (authenticator secrets, SSH keys, database passwords). Changing it makes them unreadable.
- Without a `.env`, `php leaf key:generate` still says "APP_KEY generated successfully" but saves nothing. Check with `grep APP_KEY .env` (Linux/macOS) or `findstr APP_KEY .env` (Windows); it should show `APP_KEY=base64:...`.
- In `.env`, set `APP_TIMEZONE` to your timezone (e.g. `America/Los_Angeles`) so times show in local time.

Run it (the same on Windows, Linux and macOS):

```bash
php leaf app:start              # PHP's built-in web server at APP_URL (default http://localhost:5500)
php leaf app:start --port=8080  # or pick the port (and --host=0.0.0.0 to allow other machines)
```

Stop it with Ctrl+C. Then open <http://localhost:5500> (or the address it prints). On a fresh install, sign in as **`admin`**: open "First sign-in?" and enter the one-time password **`changeme`**. You'll set up an authenticator app (e.g. Google Authenticator) straight away; from then on you sign in with your username and a code from the app (there are no user passwords). Do this right after installing: until then, anyone who can reach the site can claim that account.

Locked out (lost phone, no other admin)? On the server, from the project folder:

```bash
php leaf app:reset-admin        # or: php leaf app:reset-admin <username>
```

It resets the account to the one-time password `changeme` and removes its authenticator; sign in and set up a new one straight away.

To serve it with Apache instead, point the site's `DocumentRoot` at `public/` with `AllowOverride All`, and make `storage/` writable by the web server user (e.g. `chgrp -R www-data storage && chmod -R g+w storage`).

### In a subdirectory of another site

It can also live inside another site's folder, e.g. `/var/www/html/sysadmin/`, reached at `https://example.com/sysadmin/`. The app works out that it's under `/sysadmin` and adds it to every link, form and script address itself. It needs:

- **`AllowOverride All`** (or at least `FileInfo`) for the app's folder, and **mod_rewrite**. The app's top-level `.htaccess` sends every request into `public/`, so `.env`, `storage/`, `vendor/` and the rest can't be downloaded. **Check it after installing:** `curl -I https://example.com/sysadmin/.env` must give 404. If Apache ignores `.htaccess` there, it would serve `.env` (your app key and database settings) as a plain file.
- `storage/` writable by the web server user, as above.

Differences from its own site:

- **This machine's Apache pages are off.** The root helper's safeguard (never disabling the site that serves this app) can't work when the main site serves it.
- The session cookie is `sysadmin_session`, limited to the subdirectory, so it can't clash with the main site's.
- **It shares an origin with the main site**, so any script that runs on the main site (a compromised plugin, an XSS hole) can act as whoever is signed in here. For an admin tool that manages servers, a site of its own (a separate host name, like `sysadmin.example.com`) is safer. Passkeys also belong to the host name, so they're shared with anything else on that host.

If a proxy strips the prefix before Apache sees it, set the address the browser uses with `SetEnv APP_BASE_PATH /sysadmin`.

## Scheduled checks

Servers and certificates are checked every 5 minutes by `php leaf app:run-checks`, which runs whatever is due. Start it every minute:

- **Linux / macOS** (cron): run `crontab -e` and add this line, with your path. `umask 002` keeps the database writable for the web server's group.

  ```cron
  * * * * * umask 002; cd /var/www/sys && /usr/bin/php leaf app:run-checks >/dev/null 2>&1
  ```

- **Windows**: in Task Scheduler, create a task that runs `php leaf app:run-checks` in the project folder every minute (Trigger: daily, repeat every 1 minute indefinitely). Or keep it running in a terminal with `php leaf app:run-checks --loop`.

Runs never overlap. A server that can't be reached is retried less often (up to once an hour) so failed logins don't pile up on servers that ban them. Checks also run whenever someone clicks Check or Run checks now.

## Updating

From the project folder (Windows, Linux and macOS):

```bash
php leaf app:update             # git pull, composer install, db:migrate
```

- `git pull` is fast-forward only, so local changes are never merged or overwritten; if it fails, commit or stash them and try again. Use `--no-pull` to skip it.
- In a copy that isn't a git checkout (e.g. from a zip), it skips the pull: replace the files by hand, then run it to install dependencies and apply database changes.
- Restart the server afterwards if it's running.

## Development

```bash
composer run test               # tests (Pest)
composer run lint               # code style check; composer run fmt fixes it
composer run analyse            # static analysis (PHPStan)
```

`CLAUDE.md` describes the architecture, the security rules and the conventions in detail.

## Troubleshooting

- **`forge could not be created`** or **`SQLSTATE[HY000] [2054] ... auth_gssapi_client`** when running `php leaf db:migrate`: the command tried a local MySQL server instead of this app's SQLite database. Older versions of the app did that when `.env` was missing. Update, make sure `.env` exists (see [Create `.env` first](#create-env-first)), and check it contains `DB_CONNECTION=sqlite`.
- **`command not found: leaf`**: add Composer's global `bin` folder to your `PATH` (see [Install the Leaf CLI](#2-install-the-leaf-cli)), or use `php leaf ...` from the project folder instead.

