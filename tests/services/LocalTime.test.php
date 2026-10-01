<?php

use App\Utils\LocalTime;
use Carbon\Carbon;

test('UTC times are shown in APP_TIMEZONE', function () {
    // _env() caches the environment on its first call, which an earlier test
    // in the same worker may have made; make that happen here every time.
    _env('APP_TIMEZONE');
    putenv('APP_TIMEZONE=America/Los_Angeles');
    $_ENV['APP_TIMEZONE'] = 'America/Los_Angeles';

    expect(LocalTime::format(Carbon::parse('2026-09-29 17:23:00', 'UTC')))->toBe('2026-09-29 10:23')
        ->and(LocalTime::format(null))->toBe('');
});
