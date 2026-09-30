<?php

use App\Services\DatabaseBrowserService;

test('database-level grants on a pattern cover the databases it matches', function (string $pattern, string $schema, bool $covers) {
    expect(DatabaseBrowserService::schemaMatches($pattern, $schema))->toBe($covers);
})->with([
    ['shop', 'shop', true],
    ['shop', 'shops', false],
    ['shop\_%', 'shop_test', true],
    ['shop\_%', 'shopxtest', false],
    ['shop_%', 'shopxtest', true],
    ['%', 'anything', true],
    ['a%b', 'a-long-b', true],
    ['50\%', '50%', true],
    ['50\%', '500', false],
    ['odd`name', 'odd`name', true],
    ['Shop', 'shop', true],
    ['sh.p', 'shop', false],
]);
