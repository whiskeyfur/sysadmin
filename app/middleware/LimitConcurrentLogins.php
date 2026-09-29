<?php

namespace App\Middleware;

use App\Utils\FileSemaphore;
use Leaf\Middleware;

/**
 * Caps how many password derivations run at once. Each Argon2id derivation
 * uses about 256 MiB, and Apache can run far more workers than the server
 * has memory for, so a burst of sign-in requests could exhaust RAM even
 * when every client stays under its rate limit.
 */
class LimitConcurrentLogins extends Middleware
{
    public const SLOTS = 4;

    /**
     * Kept here so the lock outlives call(): letting the object go out of
     * scope would close the file and release the slot at once. Controllers
     * call release() once the password work is done; otherwise PHP releases
     * it when the request ends.
     */
    private static ?FileSemaphore $held = null;

    public static function release(): void
    {
        self::$held?->release();
        self::$held = null;
    }

    public function call()
    {
        $semaphore = new FileSemaphore(StoragePath('framework/locks'), self::SLOTS);

        if (!$semaphore->tryAcquire()) {
            response()
                ->withHeader('Retry-After', '5')
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->exit(view('auth.login', [
                    'error' => 'The server is busy. Try again in a few seconds.',
                    'notice' => null,
                ]), 503);
        }

        self::$held = $semaphore;
    }
}
