<?php

use App\Utils\AppKeyBootstrap;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/sys-appkey-' . bin2hex(random_bytes(4));
    mkdir("{$this->dir}/storage/framework", 0o777, true);
    file_put_contents("{$this->dir}/.env.example", "APP_NAME=sys\nAPP_KEY=\nDB_CONNECTION=sqlite\n");
    putenv('APP_KEY');
    $this->env = fn () => (string) @file_get_contents("{$this->dir}/.env");
});

afterEach(function () {
    // ensure() exports the key into this process; don't leak it into other tests.
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
    @chmod("{$this->dir}/.env", 0o644);
    exec('rm -rf ' . escapeshellarg($this->dir));
});

test('a missing .env is created from .env.example with a new key', function () {
    $key = (new AppKeyBootstrap($this->dir))->ensure();

    expect($key)->toMatch('#^base64:[A-Za-z0-9+/]{43}=$#')
        ->and(($this->env)())->toBe("APP_NAME=sys\nAPP_KEY=$key\nDB_CONNECTION=sqlite\n")
        ->and(file_get_contents("{$this->dir}/.env.example"))->toContain("APP_KEY=\n");
});

test('an empty key is filled in, keeping other lines and Windows line endings', function () {
    file_put_contents("{$this->dir}/.env", "APP_NAME=sys\r\nAPP_KEY=\r\nAPP_TIMEZONE=UTC\r\n");
    $key = (new AppKeyBootstrap($this->dir))->ensure();

    expect(($this->env)())->toBe("APP_NAME=sys\r\nAPP_KEY=$key\r\nAPP_TIMEZONE=UTC\r\n");
});

test('an existing key is kept, and repeated calls agree', function () {
    file_put_contents("{$this->dir}/.env", "APP_KEY=base64:existing\n");
    $boot = new AppKeyBootstrap($this->dir);

    expect($boot->ensure())->toBe('base64:existing')
        ->and($boot->ensure())->toBe('base64:existing')
        ->and(($this->env)())->toBe("APP_KEY=base64:existing\n");
});

test('a .env without an APP_KEY line gets one appended', function () {
    file_put_contents("{$this->dir}/.env", 'APP_NAME=sys');
    $key = (new AppKeyBootstrap($this->dir))->ensure();

    expect(($this->env)())->toBe("APP_NAME=sys\nAPP_KEY=$key\n");
});

test('a key from the real environment wins and nothing is written', function () {
    putenv('APP_KEY=base64:fromserver');

    try {
        expect((new AppKeyBootstrap($this->dir))->ensure())->toBe('base64:fromserver')
            ->and(file_exists("{$this->dir}/.env"))->toBeFalse();
    } finally {
        putenv('APP_KEY');
    }
});

test('an unwritable .env fails instead of using a key that would be lost', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('root can write read-only files');
    }

    file_put_contents("{$this->dir}/.env", "APP_KEY=\n");
    chmod("{$this->dir}/.env", 0o444);

    (new AppKeyBootstrap($this->dir))->ensure();
})->throws(RuntimeException::class, 'php leaf key:generate');

test('concurrent first requests agree on one key', function () {
    $script = sprintf('require %s; echo (new App\Utils\AppKeyBootstrap(%s))->ensure();', var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true), var_export($this->dir, true));
    $processes = [];

    foreach (range(1, 8) as $i) {
        $processes[] = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes[$i]);
    }

    $keys = [];

    foreach ($processes as $i => $process) {
        $keys[] = stream_get_contents($pipes[$i + 1][1]);
        proc_close($process);
    }

    expect(array_unique($keys))->toHaveCount(1)
        ->and(($this->env)())->toContain('APP_KEY=' . $keys[0] . "\n")
        ->and(substr_count(($this->env)(), 'APP_KEY='))->toBe(1);
});

test('an empty APP_KEY inherited from the environment is replaced in the process too', function () {
    putenv('APP_KEY=');
    $_ENV['APP_KEY'] = '';
    file_put_contents("{$this->dir}/.env", "APP_KEY=base64:saved\n");

    try {
        expect((new AppKeyBootstrap($this->dir))->ensure())->toBe('base64:saved')
            ->and(getenv('APP_KEY'))->toBe('base64:saved')
            ->and($_ENV['APP_KEY'])->toBe('base64:saved');
    } finally {
        putenv('APP_KEY');
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
    }
});
