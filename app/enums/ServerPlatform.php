<?php

namespace App\Enums;

/**
 * A server's operating system family, read from the banner its SSH server
 * sends before any login (e.g. "SSH-2.0-OpenSSH_for_Windows_9.5" or
 * "SSH-2.0-OpenSSH_9.6p1 Ubuntu-3ubuntu13"). Decides which commands and
 * paths the app shows and uses.
 */
enum ServerPlatform: string
{
    case Windows = 'windows';
    // Linux, macOS, BSD, NAS systems: POSIX sh and /etc/ssh.
    case Unix = 'unix';
    case Unknown = 'unknown';

    public static function fromIdentification(?string $identification): self
    {
        if ($identification === null || trim($identification) === '') {
            return self::Unknown;
        }

        return stripos($identification, 'windows') !== false ? self::Windows : self::Unix;
    }

    public function label(): string
    {
        return match ($this) {
            self::Windows => 'Windows',
            self::Unix => 'Linux/Unix',
            self::Unknown => 'unknown',
        };
    }
}
