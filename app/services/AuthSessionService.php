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
 * Also holds a pending first-login setup (after a correct one-time
 * password): the user, their session version and the authenticator secret
 * being enrolled, for SETUP_SECONDS.
 */
class AuthSessionService
{
    public const SETUP_SECONDS = 600;

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
    }

    public function start(User $user): void
    {
        Session::regenerate(true);
        Session::set(self::SESSION_KEY, [
            'user_id' => $user->id,
            'version' => (int) $user->session_version,
        ]);
        CSRF::regenerate();
    }

    /**
     * The signed-in user for this request, or null. Ends the session if the
     * user was deleted or their sessions were revoked.
     */
    public function current(): ?AuthContext
    {
        $auth = Session::get(self::SESSION_KEY, null, false);

        if (!is_array($auth)) {
            return null;
        }

        $user = User::query()->find($auth['user_id'] ?? null);

        if (!$user instanceof User || (int) ($auth['version'] ?? -1) !== (int) $user->session_version) {
            $this->end();

            return null;
        }

        return new AuthContext($user);
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
