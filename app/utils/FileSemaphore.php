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
            @mkdir($this->directory, 0o2775, true);
        }

        for ($slot = 0; $slot < $this->slots; $slot++) {
            $handle = fopen("{$this->directory}/slot-$slot.lock", 'c');

            if ($handle === false) {
                continue;
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;

                return true;
            }

            fclose($handle);
        }

        return false;
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
