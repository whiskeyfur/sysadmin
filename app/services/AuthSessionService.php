<?php

namespace App\Services;

use App\Utils\BasePath;
use App\DTOs\AuthContext;
use App\Models\User;
use Leaf\Anchor\CSRF;
use Leaf\Http\Session;

/**
 * The signed-in session. Holds only the user id and their session version;
 * bumping users.session_version signs that user out everywhere.
 *
 * A session ends after the admin-set idle timeout (session_timeout_minutes):
 * each request records when it was last active.
 *
 * Also holds: when the user last proved it's them (sign-in or
 * re-confirming; changing how they sign in needs it to be recent), one-use
 * passkey challenges, and an authenticator secret being set up.
 */
class AuthSessionService
{
    /**
     * How long a sign-in or re-confirmation counts as recent (for changing sign-in methods).
     */
    public const CONFIRMED_SECONDS = 600;

    /**
     * How long a passkey challenge is good for.
     */
    public const CHALLENGE_SECONDS = 300;

    /**
     * The longest idle timeout an admin can set (SettingsService range).
     */
    public const MAX_TIMEOUT_MINUTES = 1440;

    /**
     * Set by current() when it ended a session for inactivity.
     */
    public bool $timedOut = false;

    private const SESSION_KEY = 'auth';

    private const CHALLENGE_KEY = 'webauthn';

    private const TOTP_KEY = 'totp_pending';

    private const CONFIRMED_KEY = 'passkey_confirmed';

    /**
     * Harden the PHP session cookie. Called from public/index.php because
     * Leaf's CSRF module starts the session while the app is booting.
     */
    public static function configureSessionCookie(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'Strict');

        // In a subdirectory of another site: a cookie of its own, only sent to this app's URLs, so the main
        // site's PHP session (PHPSESSID at /) and this one never overwrite each other.
        if (BasePath::get() !== '') {
            ini_set('session.name', 'sysadmin_session');
            ini_set('session.cookie_path', BasePath::get() . '/');
        }

        self::configureSessionStorage();
    }

    /**
     * Keep sessions in the app's own directory with its own clean-up, sized
     * for the longest idle timeout: the system's (e.g. Ubuntu's sessionclean
     * for /var/lib/php/sessions) deletes them after php.ini's
     * gc_maxlifetime, 24 minutes by default, whatever the app's timeout.
     * The app then ends sessions at its own timeout (current()).
     * SESSIONS_PATH overrides the directory (e.g. a dev server running as
     * another user). Falls back to PHP's defaults if it can't be used.
     */
    public static function configureSessionStorage(): void
    {
        $path = getenv('SESSIONS_PATH') ?: dirname(__DIR__, 2) . '/storage/framework/sessions';

        if (!is_dir($path) && is_writable(dirname($path))) {
            mkdir($path, 0o2770, true);
        }

        if (!is_dir($path) || !is_writable($path)) {
            return;
        }

        ini_set('session.save_path', $path);
        ini_set('session.gc_maxlifetime', (string) (self::MAX_TIMEOUT_MINUTES * 60));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }

    public function start(User $user): void
    {
        Session::regenerate(true);
        Session::set(self::SESSION_KEY, [
            'user_id' => $user->id,
            'version' => (int) $user->session_version,
            'active_at' => time(),
            'confirmed_at' => time(),
        ]);
        CSRF::regenerate();
    }

    /**
     * The user proved it's them again (re-confirming).
     */
    public function markConfirmed(): void
    {
        $auth = Session::get(self::SESSION_KEY, null, false);

        if (is_array($auth)) {
            $auth['confirmed_at'] = time();
            Session::set(self::SESSION_KEY, $auth);
        }
    }

    /**
     * Whether the user signed in or re-confirmed within CONFIRMED_SECONDS.
     */
    public function recentlyConfirmed(): bool
    {
        $auth = Session::get(self::SESSION_KEY, null, false);

        return is_array($auth) && time() - (int) ($auth['confirmed_at'] ?? 0) <= self::CONFIRMED_SECONDS;
    }

    /**
     * A new passkey challenge for $purpose (e.g. "login", "register"), replacing any earlier one.
     */
    public function newChallenge(string $purpose): string
    {
        $challenge = PasskeyService::challenge();
        $challenges = (array) Session::get(self::CHALLENGE_KEY, [], false);
        $challenges[$purpose] = ['value' => base64_encode($challenge), 'expires' => time() + self::CHALLENGE_SECONDS];
        Session::set(self::CHALLENGE_KEY, $challenges);

        return $challenge;
    }

    /**
     * The challenge for $purpose, if not expired; it can only be taken once.
     */
    public function takeChallenge(string $purpose): ?string
    {
        $challenges = (array) Session::get(self::CHALLENGE_KEY, [], false);
        $entry = $challenges[$purpose] ?? null;
        unset($challenges[$purpose]);
        Session::set(self::CHALLENGE_KEY, $challenges);

        return is_array($entry) && ($entry['expires'] ?? 0) >= time() ? (base64_decode((string) $entry['value'], true) ?: null) : null;
    }

    /**
     * An authenticator secret being set up (shown as a QR code until the user enters a code).
     */
    public function pendingTotpSecret(?string $new = null): ?string
    {
        if ($new !== null) {
            Session::set(self::TOTP_KEY, $new);
        }

        $secret = Session::get(self::TOTP_KEY, null, false);

        return is_string($secret) ? $secret : null;
    }

    public function forgetTotpSecret(): void
    {
        Session::unset(self::TOTP_KEY);
    }

    /**
     * A passkey re-confirmation (made with JavaScript) for the next form
     * submitted, e.g. revealing a password; good for one use within a minute.
     */
    public function rememberPasskeyConfirmation(): void
    {
        Session::set(self::CONFIRMED_KEY, time());
    }

    /**
     * Whether a passkey confirmation is waiting to be used (without using it).
     */
    public function hasPasskeyConfirmation(): bool
    {
        $at = Session::get(self::CONFIRMED_KEY, null, false);

        return is_int($at) && time() - $at <= 60;
    }

    public function takePasskeyConfirmation(): bool
    {
        $at = Session::get(self::CONFIRMED_KEY, null, false);
        Session::unset(self::CONFIRMED_KEY);

        return is_int($at) && time() - $at <= 60;
    }

    /**
     * The signed-in user for this request, or null. Ends the session if the
     * user was deleted, their sessions were revoked, or it was idle longer
     * than the timeout; otherwise records this request as activity.
     */
    public function current(): ?AuthContext
    {
        $auth = Session::get(self::SESSION_KEY, null, false);

        if (!is_array($auth)) {
            return null;
        }

        $timeout = (new SettingsService())->integer(SettingsService::SESSION_TIMEOUT_MINUTES);

        if (self::idleTooLong((int) ($auth['active_at'] ?? 0), time(), $timeout)) {
            $this->end();
            $this->timedOut = true;

            return null;
        }

        $user = User::query()->find($auth['user_id'] ?? null);

        if (!$user instanceof User || (int) ($auth['version'] ?? -1) !== (int) $user->session_version) {
            $this->end();

            return null;
        }

        $auth['active_at'] = time();
        Session::set(self::SESSION_KEY, $auth);

        return new AuthContext($user);
    }

    /**
     * Whether a session last active at $activeAt has passed the idle timeout.
     * Sessions from before activity was recorded (0) count as idle.
     */
    public static function idleTooLong(int $activeAt, int $now, int $timeoutMinutes): bool
    {
        return $now - $activeAt > $timeoutMinutes * 60;
    }

    public function end(): void
    {
        Session::unset(self::SESSION_KEY);
        Session::unset(self::CHALLENGE_KEY);
        Session::unset(self::TOTP_KEY);
        Session::unset(self::CONFIRMED_KEY);
        Session::regenerate(true);
    }

}
