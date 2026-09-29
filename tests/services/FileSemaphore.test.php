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

test('read-only lock files still work', function () {
    (new FileSemaphore($this->dir, 1))->tryAcquire();
    chmod($this->dir . '/slot-0.lock', 0o444);

    $first = new FileSemaphore($this->dir, 1);
    $second = new FileSemaphore($this->dir, 1);

    expect($first->tryAcquire())->toBeTrue()
        ->and($second->tryAcquire())->toBeFalse();
});

test('an unusable lock directory is an error, not a permanent busy', function () {
    mkdir($this->dir, 0o555);

    (new FileSemaphore($this->dir, 2))->tryAcquire();
})->throws(RuntimeException::class);
