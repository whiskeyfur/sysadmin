<?php

use App\Console\AppStartCommand;

test('the server address comes from the options, then APP_URL, then the defaults', function (?string $host, ?string $port, ?string $appUrl, array $expected) {
    expect(AppStartCommand::address($host, $port, $appUrl))->toBe($expected);
})->with([
    'defaults' => [null, null, null, ['localhost', 5500]],
    'from APP_URL' => [null, null, 'http://sys.localhost:5015/', ['sys.localhost', 5015]],
    'APP_URL without a port' => [null, null, 'http://127.0.0.1/', ['127.0.0.1', 5500]],
    'options win' => ['0.0.0.0', '8080', 'http://localhost:5500/', ['0.0.0.0', 8080]],
    'only the port' => [null, '5501', 'http://localhost:5500/', ['localhost', 5501]],
]);

test('misspelled hosts are caught before starting', function () {
    expect(AppStartCommand::resolves('localhost'))->toBeTrue()
        ->and(AppStartCommand::resolves('127.0.0.1'))->toBeTrue()
        ->and(AppStartCommand::resolves('0.0.0.0'))->toBeTrue()
        ->and(AppStartCommand::resolves('[::1]'))->toBeTrue()
        ->and(AppStartCommand::resolves('locahost'))->toBeFalse();
});
