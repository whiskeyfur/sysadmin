<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Models\Setting;
use App\Models\User;
use DomainException;

/**
 * App-wide settings, changed by admins on the settings page of each
 * monitoring area (/admin/settings/{ssl,ssh,mariadb}). Each setting has a
 * default here and its own validation; unset settings use the default.
 */
class SettingsService
{
    public const SSL_WARNING_DAYS = 'ssl_warning_days';

    public const SSL_IMPORT_NAMES = 'ssl_import_names';

    public const ACCOUNT_WARNING_DAYS = 'account_warning_days';

    public const DISK_WARNING_PERCENT = 'disk_warning_percent';

    public const DISK_CRITICAL_PERCENT = 'disk_critical_percent';

    public const MYSQL_CONNECTIONS_WARNING_PERCENT = 'mysql_connections_warning_percent';

    public const MYSQL_CONNECTIONS_CRITICAL_PERCENT = 'mysql_connections_critical_percent';

    public const MYSQL_LAG_WARNING_SECONDS = 'mysql_lag_warning_seconds';

    public const MYSQL_LAG_CRITICAL_SECONDS = 'mysql_lag_critical_seconds';

    public const MYSQL_BUFFER_POOL_WARNING_PERCENT = 'mysql_buffer_pool_warning_percent';

    public const MYSQL_RESTART_WARNING_MINUTES = 'mysql_restart_warning_minutes';

    public const MYSQL_LOG_IMPORT_MINUTES = 'mysql_log_import_minutes';

    /**
     * @var array<string, int>
     */
    public const DEFAULTS = [
        self::SSL_WARNING_DAYS => 7,
        self::SSL_IMPORT_NAMES => 1,
        self::ACCOUNT_WARNING_DAYS => 7,
        self::DISK_WARNING_PERCENT => 85,
        self::DISK_CRITICAL_PERCENT => 95,
        self::MYSQL_CONNECTIONS_WARNING_PERCENT => 80,
        self::MYSQL_CONNECTIONS_CRITICAL_PERCENT => 95,
        self::MYSQL_LAG_WARNING_SECONDS => 60,
        self::MYSQL_LAG_CRITICAL_SECONDS => 600,
        self::MYSQL_BUFFER_POOL_WARNING_PERCENT => 95,
        self::MYSQL_RESTART_WARNING_MINUTES => 60,
        self::MYSQL_LOG_IMPORT_MINUTES => 60,
    ];

    /**
     * Which settings each area's settings page shows.
     *
     * @var array<string, list<string>>
     */
    public const SECTIONS = [
        'ssl' => [self::SSL_WARNING_DAYS, self::SSL_IMPORT_NAMES],
        'ssh' => [self::DISK_WARNING_PERCENT, self::DISK_CRITICAL_PERCENT, self::ACCOUNT_WARNING_DAYS],
        'mariadb' => [
            self::MYSQL_CONNECTIONS_WARNING_PERCENT,
            self::MYSQL_CONNECTIONS_CRITICAL_PERCENT,
            self::MYSQL_LAG_WARNING_SECONDS,
            self::MYSQL_LAG_CRITICAL_SECONDS,
            self::MYSQL_BUFFER_POOL_WARNING_PERCENT,
            self::MYSQL_RESTART_WARNING_MINUTES,
            self::MYSQL_LOG_IMPORT_MINUTES,
        ],
    ];

    /**
     * @var array<string, array{min: int, max: int}>
     */
    private const INTEGER_RANGES = [
        self::SSL_WARNING_DAYS => ['min' => 1, 'max' => 365],
        self::SSL_IMPORT_NAMES => ['min' => 0, 'max' => 1],
        self::ACCOUNT_WARNING_DAYS => ['min' => 1, 'max' => 365],
        self::DISK_WARNING_PERCENT => ['min' => 1, 'max' => 100],
        self::DISK_CRITICAL_PERCENT => ['min' => 1, 'max' => 100],
        self::MYSQL_CONNECTIONS_WARNING_PERCENT => ['min' => 1, 'max' => 100],
        self::MYSQL_CONNECTIONS_CRITICAL_PERCENT => ['min' => 1, 'max' => 100],
        self::MYSQL_LAG_WARNING_SECONDS => ['min' => 1, 'max' => 86400],
        self::MYSQL_LAG_CRITICAL_SECONDS => ['min' => 1, 'max' => 86400],
        self::MYSQL_BUFFER_POOL_WARNING_PERCENT => ['min' => 1, 'max' => 100],
        self::MYSQL_RESTART_WARNING_MINUTES => ['min' => 1, 'max' => 10080],
        self::MYSQL_LOG_IMPORT_MINUTES => ['min' => 0, 'max' => 1440],
    ];

    /**
     * Warning level => critical level: the warning must be the lower one.
     *
     * @var array<string, string>
     */
    private const WARNING_BELOW_CRITICAL = [
        self::DISK_WARNING_PERCENT => self::DISK_CRITICAL_PERCENT,
        self::MYSQL_CONNECTIONS_WARNING_PERCENT => self::MYSQL_CONNECTIONS_CRITICAL_PERCENT,
        self::MYSQL_LAG_WARNING_SECONDS => self::MYSQL_LAG_CRITICAL_SECONDS,
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
     * @param array<string, mixed> $input setting key => submitted value; keys left out keep their value
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

        foreach (self::WARNING_BELOW_CRITICAL as $warningKey => $criticalKey) {
            $warning = $values[$warningKey] ?? $this->integer($warningKey);
            $critical = $values[$criticalKey] ?? $this->integer($criticalKey);

            if ($warning >= $critical) {
                throw new DomainException("The {$this->label($warningKey)} must be below the {$this->label($criticalKey)}.");
            }
        }

        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
    }

    public function label(string $key): string
    {
        return match ($key) {
            self::SSL_WARNING_DAYS => 'SSL warning period',
            self::SSL_IMPORT_NAMES => 'Adding hostnames from certificates',
            self::ACCOUNT_WARNING_DAYS => 'Account rotation warning period',
            self::DISK_WARNING_PERCENT => 'disk warning level',
            self::DISK_CRITICAL_PERCENT => 'disk critical level',
            self::MYSQL_CONNECTIONS_WARNING_PERCENT => 'connections warning level',
            self::MYSQL_CONNECTIONS_CRITICAL_PERCENT => 'connections critical level',
            self::MYSQL_LAG_WARNING_SECONDS => 'replication lag warning',
            self::MYSQL_LAG_CRITICAL_SECONDS => 'replication lag critical level',
            self::MYSQL_BUFFER_POOL_WARNING_PERCENT => 'Buffer pool warning level',
            self::MYSQL_RESTART_WARNING_MINUTES => 'Recent restart window',
            self::MYSQL_LOG_IMPORT_MINUTES => 'Log import interval',
            default => $key,
        };
    }
}
