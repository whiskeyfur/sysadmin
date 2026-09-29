<?php

use App\Utils\FileSemaphore;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/sys-semaphore-' . bin2hex(random_bytes(4));
});

afterEach(function () {
    array_map('unlink', glob($this->dir . '/*') ?: []);
    @rmdir($this->dir);
});

test('at most the given number of holders at once', function () {
    $first = new FileSemaphore($this->dir, 2);
    $second = new FileSemaphore($this->dir, 2);
    $third = new FileSemaphore($this->dir, 2);

    expect($first->tryAcquire())->toBeTrue()
        ->and($second->tryAcquire())->toBeTrue()
        ->and($third->tryAcquire())->toBeFalse();

    $first->release();

    expect($third->tryAcquire())->toBeTrue();
});
