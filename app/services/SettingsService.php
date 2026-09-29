<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Models\Setting;
use App\Models\User;
use DomainException;

/**
 * App-wide settings, changed by admins on /admin/settings. Each setting has a
 * default here and its own validation; unset settings use the default.
 */
class SettingsService
{
    public const SSL_WARNING_DAYS = 'ssl_warning_days';

    public const ACCOUNT_WARNING_DAYS = 'account_warning_days';

    public const DISK_WARNING_PERCENT = 'disk_warning_percent';

    public const DISK_CRITICAL_PERCENT = 'disk_critical_percent';

    /**
     * @var array<string, int>
     */
    public const DEFAULTS = [
        self::SSL_WARNING_DAYS => 7,
        self::ACCOUNT_WARNING_DAYS => 7,
        self::DISK_WARNING_PERCENT => 85,
        self::DISK_CRITICAL_PERCENT => 95,
    ];

    /**
     * @var array<string, array{min: int, max: int}>
     */
    private const INTEGER_RANGES = [
        self::SSL_WARNING_DAYS => ['min' => 1, 'max' => 365],
        self::ACCOUNT_WARNING_DAYS => ['min' => 1, 'max' => 365],
        self::DISK_WARNING_PERCENT => ['min' => 1, 'max' => 100],
        self::DISK_CRITICAL_PERCENT => ['min' => 1, 'max' => 100],
    ];

    /**
     * Days before expiry at which a valid SSL certificate becomes a warning.
     */
    public function sslWarningDays(): int
    {
        return $this->integer(self::SSL_WARNING_DAYS);
    }

    /**
     * Days before an account's password is due at which it's flagged "due soon".
     */
    public function accountWarningDays(): int
    {
        return $this->integer(self::ACCOUNT_WARNING_DAYS);
    }

    /**
     * Disk (space or inode) use, in percent, at which the disk check warns.
     */
    public function diskWarningPercent(): int
    {
        return $this->integer(self::DISK_WARNING_PERCENT);
    }

    /**
     * Disk (space or inode) use, in percent, at which the disk check is critical.
     */
    public function diskCriticalPercent(): int
    {
        return $this->integer(self::DISK_CRITICAL_PERCENT);
    }

    public function integer(string $key): int
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown setting: $key");
        }

        $stored = Setting::query()->where('key', $key)->value('value');

        return is_numeric($stored) ? (int) $stored : self::DEFAULTS[$key];
    }

    /**
     * @param array<string, mixed> $input setting key => submitted value
     *
     * @throws DomainException with a user-facing message when a value is invalid
     */
    public function update(User $admin, array $input): void
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can change settings.');
        }

        $values = [];

        foreach (self::INTEGER_RANGES as $key => $range) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = filter_var($input[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $range['min'], 'max_range' => $range['max']]]);

            if ($value === false) {
                throw new DomainException("{$this->label($key)} must be a whole number from {$range['min']} to {$range['max']}.");
            }

            $values[$key] = $value;
        }

        $warning = $values[self::DISK_WARNING_PERCENT] ?? $this->diskWarningPercent();
        $critical = $values[self::DISK_CRITICAL_PERCENT] ?? $this->diskCriticalPercent();

        if ($warning >= $critical) {
            throw new DomainException('The disk warning level must be below the critical level.');
        }

        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
    }

    public function label(string $key): string
    {
        return match ($key) {
            self::SSL_WARNING_DAYS => 'SSL warning period',
            self::ACCOUNT_WARNING_DAYS => 'Account rotation warning period',
            self::DISK_WARNING_PERCENT => 'Disk warning level',
            self::DISK_CRITICAL_PERCENT => 'Disk critical level',
            default => $key,
        };
    }
}
