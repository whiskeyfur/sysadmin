<?php

use App\Utils\LocalTime;
use Carbon\Carbon;

test('UTC times are shown in APP_TIMEZONE', function () {
    putenv('APP_TIMEZONE=America/Los_Angeles');
    $_ENV['APP_TIMEZONE'] = 'America/Los_Angeles';

    expect(LocalTime::format(Carbon::parse('2026-09-29 17:23:00', 'UTC')))->toBe('2026-09-29 10:23')
        ->and(LocalTime::format(null))->toBe('');
});
