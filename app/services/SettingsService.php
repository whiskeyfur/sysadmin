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

    /**
     * @var array<string, int>
     */
    public const DEFAULTS = [
        self::SSL_WARNING_DAYS => 7,
    ];

    /**
     * @var array<string, array{min: int, max: int}>
     */
    private const INTEGER_RANGES = [
        self::SSL_WARNING_DAYS => ['min' => 1, 'max' => 365],
    ];

    /**
     * Days before expiry at which a valid SSL certificate becomes a warning.
     */
    public function sslWarningDays(): int
    {
        return $this->integer(self::SSL_WARNING_DAYS);
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

        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
    }

    public function label(string $key): string
    {
        return match ($key) {
            self::SSL_WARNING_DAYS => 'SSL warning period',
            default => $key,
        };
    }
}
