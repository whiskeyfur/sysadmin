<?php

namespace App\Services;

use App\Models\Passkey;
use App\Models\User;
use DomainException;

/**
 * The ways to sign in, and which an admin turned on (App settings): a
 * password the user chose, an authenticator app code, a passkey or
 * hardware security key. Each is off, optional or required.
 *
 * Required: every user must set it up (setup and, for existing users,
 * the next sign-in lead to My account until they have). Optional: users
 * may set it up. Signing in takes any one method the user has that's on.
 */
class LoginMethodService
{
    public const PASSWORD = 'password';

    public const AUTHENTICATOR = 'authenticator';

    public const PASSKEY = 'passkey';

    public const OFF = 0;

    public const OPTIONAL = 1;

    public const REQUIRED = 2;

    /**
     * Each method's setting.
     *
     * @var array<string, string>
     */
    public const SETTINGS = [
        self::PASSWORD => SettingsService::LOGIN_PASSWORD,
        self::AUTHENTICATOR => SettingsService::LOGIN_AUTHENTICATOR,
        self::PASSKEY => SettingsService::LOGIN_PASSKEY,
    ];

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::PASSWORD => 'Password',
        self::AUTHENTICATOR => 'Authenticator app',
        self::PASSKEY => 'Passkey or security key',
    ];

    /**
     * @var array<int, string>
     */
    public const LEVELS = [self::OFF => 'Off', self::OPTIONAL => 'Optional', self::REQUIRED => 'Required'];

    public function __construct(private readonly SettingsService $settings = new SettingsService())
    {
    }

    /**
     * @return array<string, int> method => OFF, OPTIONAL or REQUIRED
     */
    public function levels(): array
    {
        return array_map(fn (string $key) => $this->settings->integer($key), self::SETTINGS);
    }

    public function isEnabled(string $method): bool
    {
        return ($this->levels()[$method] ?? self::OFF) !== self::OFF;
    }

    /**
     * @return list<string>
     */
    public function enabled(?array $levels = null): array
    {
        return array_keys(array_filter($levels ?? $this->levels(), fn (int $level) => $level !== self::OFF));
    }

    /**
     * @return list<string>
     */
    public function required(?array $levels = null): array
    {
        return array_keys(array_filter($levels ?? $this->levels(), fn (int $level) => $level === self::REQUIRED));
    }

    /**
     * The methods the user has set up, whether on or not.
     *
     * @return list<string>
     */
    public function enrolled(User $user): array
    {
        return array_values(array_filter([
            $user->hasPassword() ? self::PASSWORD : null,
            $user->hasAuthenticator() ? self::AUTHENTICATOR : null,
            Passkey::query()->where('user_id', $user->id)->exists() ? self::PASSKEY : null,
        ]));
    }

    /**
     * The methods the user can sign in with now: set up and on.
     *
     * @param array<string, int>|null $levels
     * @return list<string>
     */
    public function usable(User $user, ?array $levels = null): array
    {
        return array_values(array_intersect($this->enrolled($user), $this->enabled($levels)));
    }

    /**
     * Required methods the user hasn't set up yet.
     *
     * @return list<string>
     */
    public function missingRequired(User $user): array
    {
        return array_values(array_diff($this->required(), $this->enrolled($user)));
    }

    /**
     * Every required method set up, and at least one usable method.
     */
    public function setUpEnough(User $user): bool
    {
        return $this->missingRequired($user) === [] && $this->usable($user) !== [];
    }

    /**
     * Whether removing the user's $method would break the rules: it's
     * required, or it's their last usable method.
     */
    public function canRemove(User $user, string $method): bool
    {
        return !in_array($method, $this->required(), true) && array_diff($this->usable($user), [$method]) !== [];
    }

    /**
     * Check new levels before saving them: at least one method on, and
     * every admin who finished setup still able to sign in.
     *
     * @param array<string, int> $levels
     *
     * @throws DomainException
     */
    public function checkLevels(array $levels): void
    {
        if ($this->enabled($levels) === []) {
            throw new DomainException('Turn on at least one way to sign in.');
        }

        $stranded = User::query()->where('role', User::ROLE_ADMIN)->where('must_change_password', false)->get()
            ->filter(fn (User $admin) => $this->usable($admin, $levels) === [])
            ->pluck('username')->all();

        if ($stranded !== []) {
            throw new DomainException('That would leave ' . implode(', ', $stranded) . ' unable to sign in: they have none of the methods left on. Have them set one up first (on their Profile), or keep a method they use.');
        }
    }
}
