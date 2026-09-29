<?php

namespace App\Services;

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
 * Also holds a pending first-login setup (after a correct one-time
 * password): the user, their session version and the authenticator secret
 * being enrolled, for SETUP_SECONDS.
 */
class AuthSessionService
{
    public const SETUP_SECONDS = 600;

    /**
     * The longest idle timeout an admin can set (SettingsService range).
     */
    public const MAX_TIMEOUT_MINUTES = 1440;

    /**
     * Set by current() when it ended a session for inactivity.
     */
    public bool $timedOut = false;

    private const SESSION_KEY = 'auth';

    private const SETUP_KEY = 'setup';

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
        ]);
        CSRF::regenerate();
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
        Session::unset(self::SETUP_KEY);
        Session::regenerate(true);
    }

    public function beginSetup(User $user, string $totpSecret): void
    {
        Session::regenerate(true);
        Session::set(self::SETUP_KEY, [
            'user_id' => $user->id,
            'version' => (int) $user->session_version,
            'secret' => $totpSecret,
            'expires' => time() + self::SETUP_SECONDS,
        ]);
    }

    /**
     * The pending setup, or null if there is none or it expired.
     *
     * @return array{user: User, version: int, secret: string}|null
     */
    public function pendingSetup(): ?array
    {
        $setup = Session::get(self::SETUP_KEY, null, false);

        if (!is_array($setup) || ($setup['expires'] ?? 0) < time()) {
            Session::unset(self::SETUP_KEY);

            return null;
        }

        $user = User::query()->find($setup['user_id'] ?? null);

        return $user instanceof User ? ['user' => $user, 'version' => (int) $setup['version'], 'secret' => (string) $setup['secret']] : null;
    }

    public function endSetup(): void
    {
        Session::unset(self::SETUP_KEY);
    }
}
