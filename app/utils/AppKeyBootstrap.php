<?php

namespace App\Utils;

use RuntimeException;

/**
 * Makes sure APP_KEY exists before Leaf boots (its CSRF module needs it, and
 * SecretCipher encrypts stored secrets with it). Runs from public/index.php
 * on every request; after the first, it's one small file read.
 *
 * If .env is missing it is created from .env.example; if APP_KEY is empty or
 * missing, a key is generated in Leaf's format and written into .env, other
 * lines untouched. The check-and-write happens under a lock so concurrent
 * first requests agree on one key. If the key can't be saved, this fails
 * instead of running with a key that would be lost on the next request.
 */
class AppKeyBootstrap
{
    public function __construct(private readonly string $appPath)
    {
    }

    /**
     * Run from the web entry point: ensure the key, or stop with a 500 that
     * says what to do.
     */
    public static function ensureOrFail(string $appPath): void
    {
        try {
            (new self($appPath))->ensure();
        } catch (RuntimeException $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "sys can't start: {$e->getMessage()}\n";
            exit(1);
        }
    }

    /**
     * @return string the APP_KEY in effect
     *
     * @throws RuntimeException if .env can't be created or written
     */
    public function ensure(): string
    {
        $key = $this->findOrCreate();
        $this->export($key);

        return $key;
    }

    /**
     * Put the key in the process environment. `php leaf app:start` loads .env
     * into its own environment and the built-in server inherits it, possibly
     * with an empty APP_KEY; Leaf's .env loader never overrides a variable
     * that already exists, so without this it would keep the empty value.
     */
    private function export(string $key): void
    {
        putenv("APP_KEY=$key");
        $_ENV['APP_KEY'] = $key;
        $_SERVER['APP_KEY'] = $key;
    }

    private function findOrCreate(): string
    {
        // A key set in the real environment (e.g. by the web server) wins, as with Dotenv.
        $fromEnvironment = getenv('APP_KEY');

        if (is_string($fromEnvironment) && $fromEnvironment !== '') {
            return $fromEnvironment;
        }

        $envFile = "{$this->appPath}/.env";
        $existing = is_readable($envFile) ? $this->keyIn((string) file_get_contents($envFile)) : null;

        if ($existing !== null) {
            return $existing;
        }

        return $this->withLock(function () use ($envFile) {
            // Another request may have written the key while we waited for the lock.
            if (!file_exists($envFile)) {
                $this->createFromExample($envFile);
            }

            $contents = (string) file_get_contents($envFile);
            $existing = $this->keyIn($contents);

            if ($existing !== null) {
                return $existing;
            }

            $key = 'base64:' . base64_encode(random_bytes(32));
            $updated = preg_match('/^APP_KEY=.*$/m', $contents) === 1
                ? (string) preg_replace('/^APP_KEY=.*?(\r?)$/m', 'APP_KEY=' . $key . '$1', $contents, 1)
                : rtrim($contents, "\r\n") . ($contents === '' ? '' : "\n") . "APP_KEY=$key\n";

            if (!is_writable($envFile) || file_put_contents($envFile, $updated, LOCK_EX) === false) {
                throw new RuntimeException("APP_KEY is not set and $envFile isn't writable by this user. Run `php leaf key:generate` in the project folder.");
            }

            return $key;
        });
    }

    /**
     * The non-empty APP_KEY value in .env contents, or null.
     */
    private function keyIn(string $contents): ?string
    {
        if (preg_match('/^APP_KEY=(.*?)\r?$/m', $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1], " \t\"'");

        return $value === '' ? null : $value;
    }

    private function createFromExample(string $envFile): void
    {
        $example = "{$this->appPath}/.env.example";

        if (!is_readable($example)) {
            throw new RuntimeException('.env is missing and there is no .env.example to create it from.');
        }

        if (!is_writable($this->appPath) || !copy($example, $envFile)) {
            throw new RuntimeException(".env is missing and can't be created in {$this->appPath}. Copy .env.example to .env, then run `php leaf key:generate`.");
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $lockDir = "{$this->appPath}/storage/framework";
        $lockFile = is_dir($lockDir) && is_writable($lockDir) ? "$lockDir/app-key.lock" : sys_get_temp_dir() . '/sys-app-key-' . md5($this->appPath) . '.lock';
        $handle = fopen($lockFile, 'c');

        if ($handle === false) {
            return $callback();
        }

        try {
            flock($handle, LOCK_EX);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
