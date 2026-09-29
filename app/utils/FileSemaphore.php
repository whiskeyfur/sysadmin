<?php

namespace App\Utils;

/**
 * A counting semaphore made of lock files: at most $slots holders at once,
 * across all web server processes. The lock is released by release(), or
 * automatically when the request ends and PHP closes the file.
 */
class FileSemaphore
{
    /**
     * @var resource|null
     */
    private $handle = null;

    public function __construct(
        private readonly string $directory,
        private readonly int $slots,
    ) {
    }

    public function tryAcquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o2775, true);
            chmod($this->directory, 0o2775);
        }

        $opened = 0;

        for ($slot = 0; $slot < $this->slots; $slot++) {
            $handle = $this->open("{$this->directory}/slot-$slot.lock");

            if ($handle === null) {
                continue;
            }

            $opened++;

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;

                return true;
            }

            fclose($handle);
        }

        if ($opened === 0) {
            throw new \RuntimeException("No lock file in {$this->directory} can be opened; check its permissions.");
        }

        return false;
    }

    /**
     * Lock files may belong to another user (Apache's www-data vs. the CLI),
     * so open read-only when not writable: flock() works on either.
     *
     * @return resource|null
     */
    private function open(string $path)
    {
        if (file_exists($path)) {
            $handle = fopen($path, is_writable($path) ? 'c' : 'r');

            return $handle === false ? null : $handle;
        }

        if (!is_writable($this->directory)) {
            return null;
        }

        $handle = fopen($path, 'c');

        if ($handle === false) {
            return null;
        }

        chmod($path, 0o664);

        return $handle;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
