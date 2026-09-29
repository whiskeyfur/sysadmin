<?php

namespace App\Services;

use App\DTOs\AuthContext;
use App\Exceptions\DecryptionException;
use App\Exceptions\InvalidMasterKeyException;
use App\Models\User;
use Leaf\Anchor\CSRF;
use Leaf\Http\Cookie;
use Leaf\Http\Session;

/**
 * Keeps a user signed in without the master key touching disk (CLAUDE.md
 * rule 7). The server session holds the user id and the sealed master key;
 * the key to unseal it lives only in the browser's cookie.
 */
class AuthSessionService
{
    public const COOKIE = 'sys_key';

    private const SESSION_KEY = 'auth';

    private readonly VaultService $vault;

    private readonly UserKeyService $keys;

    public function __construct(
        private readonly CryptoService $crypto = new CryptoService(),
        ?VaultService $vault = null,
        ?UserKeyService $keys = null,
        private readonly SessionKeyService $sessionKeys = new SessionKeyService(),
    ) {
        $this->vault = $vault ?? new VaultService($crypto);
        $this->keys = $keys ?? new UserKeyService($crypto, $this->vault);
    }

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

    public function start(User $user, string $masterKey): void
    {
        Session::regenerate(true);

        $sealed = $this->sessionKeys->seal($masterKey);

        Session::set(self::SESSION_KEY, [
            'user_id' => $user->id,
            'version' => (int) $user->session_version,
            'sealed' => $sealed->ciphertext,
        ]);

        Cookie::set(self::COOKIE, $sealed->cookieKey, [
            'expires' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        CSRF::regenerate();
    }

    /**
     * The signed-in user for this request, or null. Ends the session if it
     * can no longer be unsealed, the user was deleted, their sessions were
     * revoked (session_version changed), or the master key was rotated since
     * sign-in.
     */
    public function current(): ?AuthContext
    {
        $auth = Session::get(self::SESSION_KEY, null, false);
        $cookieKey = Cookie::get(self::COOKIE);

        if (!is_array($auth) || !is_string($auth['sealed'] ?? null) || !is_string($cookieKey)) {
            return null;
        }

        $user = User::query()->find($auth['user_id'] ?? null);

        if (!$user instanceof User || (int) ($auth['version'] ?? -1) !== (int) $user->session_version) {
            $this->end();

            return null;
        }

        try {
            $masterKey = $this->sessionKeys->open($cookieKey, $auth['sealed']);
            $dataKey = $this->vault->unwrapDataKey($masterKey);
        } catch (DecryptionException | InvalidMasterKeyException) {
            $this->end();

            return null;
        }

        $role = $this->keys->role($user, $dataKey);
        $this->crypto->wipe($dataKey);

        return new AuthContext($user, $masterKey, $role);
    }

    public function end(): void
    {
        Session::unset(self::SESSION_KEY);
        Session::regenerate(true);

        Cookie::set(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
