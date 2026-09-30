<?php

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| This file is loaded before your tests run. Use it to bind a base test
| class, add custom expectations, or define helpers shared by your suite.
| Alchemy manages the phpunit.xml side for you — this file is all yours.
|
| uses(Tests\TestCase::class)->in('feature');
|
| expect()->extend('toBeWithinRange', function (int $min, int $max) {
|     return $this->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
| });
|
*/

use App\Services\PasswordService;
use App\Services\SecretCipher;
use Illuminate\Database\Capsule\Manager;
use Leaf\Schema;
use Psr\Clock\ClockInterface;

/*
| Service tests run against a fresh in-memory SQLite database built
| from the real schema files. They get a movable clock, a SecretCipher with
| a throwaway app key, and a PasswordService on the test clock.
*/
uses()->beforeEach(function () {
    // Loading Leaf\Model runs Database::connect() for the app database, which
    // would replace this test connection, so trigger it before building ours.
    class_exists(\Leaf\Model::class);

    $capsule = new Manager();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    Schema::setDbConnection($capsule);
    Schema::migrate('app/database/users.yml');
    Schema::migrate('app/database/login_attempts.yml');
    Schema::migrate('app/database/servers.yml');
    Schema::migrate('app/database/ssh_keypairs.yml');
    Schema::migrate('app/database/health_checks.yml');
    Schema::migrate('app/database/mariadb_log_entries.yml');
    Schema::migrate('app/database/apache_log_entries.yml');
    Schema::migrate('app/database/apache_traffic.yml');
    Schema::migrate('app/database/apache_access_entries.yml');
    Schema::migrate('app/database/fail2ban_protected.yml');
    Schema::migrate('app/database/blocklist_ips.yml');
    Schema::migrate('app/database/mariadb_queries.yml');
    Schema::migrate('app/database/query_accounts.yml');
    Schema::migrate('app/database/apache_vhosts.yml');
    Schema::migrate('app/database/ssl_checks.yml');
    Schema::migrate('app/database/settings.yml');
    Schema::migrate('app/database/accounts.yml');
    Schema::migrate('app/database/account_server.yml');
    Schema::migrate('app/database/password_reveals.yml');
    Schema::migrate('app/database/ssl_certificates.yml');
    Schema::migrate('app/database/ssl_bindings.yml');
    Schema::migrate('app/database/passkeys.yml');
    Schema::migrate('app/database/apache_admin_log.yml');

    // A clock tests can move: $this->clock->advance(30) jumps one TOTP period.
    $this->clock = new class () implements ClockInterface {
        public int $time = 1_800_000_000;

        public function now(): DateTimeImmutable
        {
            return (new DateTimeImmutable())->setTimestamp($this->time);
        }

        public function advance(int $seconds): void
        {
            $this->time += $seconds;
        }
    };

    $this->cipher = new SecretCipher('base64:' . base64_encode(random_bytes(32)));
    $this->passwords = new PasswordService();
})->in('services', 'checks');

