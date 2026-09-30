<?php

test('asset URLs carry the file\'s modification time, so a changed script is fetched again', function () {
    expect(App\Utils\Asset::url('/assets/js/paged-table.js'))->toBe('/assets/js/paged-table.js?v=' . filemtime('public/assets/js/paged-table.js'))
        ->and(App\Utils\Asset::url('/assets/js/missing.js'))->toBe('/assets/js/missing.js');
});
