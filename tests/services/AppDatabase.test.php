<?php

use App\Models\Setting;
use App\Services\AppDatabaseService;

test('the app\'s own database is described without its password', function () {
    $details = (new AppDatabaseService(Setting::query()->getConnection()))->describe();

    expect($details['Type'])->toBe('SQLite')
        ->and($details['SQLite version'])->toMatch('/^3\./')
        ->and($details['Tables'])->toMatch('/^\d+/')
        ->and((int) $details['Tables'])->toBeGreaterThan(10)
        ->and(implode(' ', array_keys($details)))->not->toContain('Password');
});
