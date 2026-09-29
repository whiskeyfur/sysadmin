<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * This is the base controller for your Leaf MVC Project.
 * You can initialize packages or define methods here to use
 * them across all your other controllers which extend this one.
 */
class Controller extends \Leaf\Controller
{
    /**
     * The address of the connecting client. Deliberately not Leaf's
     * request()->getIp(), which trusts the Client-IP and X-Forwarded-For
     * headers: any client can set those and dodge per-IP rate limits. If a
     * reverse proxy is ever put in front of Apache, resolve the client IP
     * from its header here, trusting only that proxy.
     */
    protected function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * Minutes to show in a rate-limit message, rounded up.
     */
    protected function retryMinutes(int $seconds): int
    {
        return max(1, (int) ceil($seconds / 60));
    }
}
