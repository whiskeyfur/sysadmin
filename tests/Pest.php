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

use App\Services\CryptoService;
use Illuminate\Database\Capsule\Manager;
use Leaf\Schema;
use Psr\Clock\ClockInterface;

/*
| Service tests run against a fresh in-memory SQLite database built
| from the real schema files, and use the cheapest Argon2id cost so the
| suite stays fast. Production cost is set by CryptoService's defaults.
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
    Schema::migrate('app/database/vaults.yml');

    $this->crypto = new CryptoService(1, 8192 * 8);

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
})->in('services');

