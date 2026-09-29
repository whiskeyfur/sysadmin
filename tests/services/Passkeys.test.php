<?php

use App\Enums\LoginStatus;
use App\Exceptions\InvalidCredentialsException;
use App\Models\Passkey;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthService;
use App\Services\LoginMethodService;
use App\Services\LoginThrottleService;
use App\Services\PasskeyService;
use App\Services\SettingsService;
use App\Services\TotpService;

beforeEach(function () {
    $this->site = PasskeyService::site('sys.localhost:5015', false);
    $this->passkeys = new PasskeyService('test-key');
    $this->user = User::query()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'must_change_password' => false, 'session_version' => 0]);
    // Written directly: the admin has no method yet, so the settings page would refuse it.
    Setting::query()->create(['key' => SettingsService::LOGIN_AUTHENTICATOR, 'value' => '1']);
    Setting::query()->create(['key' => SettingsService::LOGIN_PASSKEY, 'value' => '2']);

    // CBOR, just what an authenticator sends: unsigned/negative ints, byte and text strings, maps.
    $this->cbor = function ($value) use (&$cbor) {
        $head = fn (int $major, int $n) => $n < 24 ? chr($major << 5 | $n) : ($n < 256 ? chr($major << 5 | 24) . chr($n) : chr($major << 5 | 25) . pack('n', $n));

        return match (true) {
            is_int($value) => $value >= 0 ? $head(0, $value) : $head(1, -1 - $value),
            $value instanceof \stdClass => $head(2, strlen($value->bytes)) . $value->bytes,
            is_string($value) => $head(3, strlen($value)) . $value,
            is_array($value) => $head(5, count($value)) . implode('', array_map(fn ($k, $v) => ($this->cbor)($k) . ($this->cbor)($v), array_keys($value), $value)),
        };
    };
    $bytes = fn (string $b) => (object) ['bytes' => $b];

    // A software authenticator: an ES256 key, making and using credentials like a real one.
    $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $this->credentialId = random_bytes(16);
    $this->create = function (string $challenge, array $overrides = []) use ($bytes) {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = ($this->cbor)([1 => 2, 3 => -7, -1 => 1, -2 => $bytes($ec['x']), -3 => $bytes($ec['y'])]);
        $flags = $overrides['flags'] ?? 0x45; // user present, user verified, attested credential data
        $authData = hash('sha256', $overrides['rp_id'] ?? 'sys.localhost', true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($this->credentialId)) . $this->credentialId . $cose;
        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => PasskeyService::encode($challenge), 'origin' => $overrides['origin'] ?? 'http://sys.localhost:5015']);

        return ['clientDataJSON' => PasskeyService::encode($clientData), 'attestationObject' => PasskeyService::encode(($this->cbor)(['fmt' => 'none', 'attStmt' => [], 'authData' => $bytes($authData)]))];
    };
    $this->counter = 0;
    $this->get = function (string $challenge, array $overrides = []) {
        $this->counter = $overrides['counter'] ?? $this->counter + 1;
        $authData = hash('sha256', $overrides['rp_id'] ?? 'sys.localhost', true) . chr($overrides['flags'] ?? 0x05) . pack('N', $this->counter);
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => PasskeyService::encode($challenge), 'origin' => $overrides['origin'] ?? 'http://sys.localhost:5015']);
        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return ['id' => PasskeyService::encode($this->credentialId), 'response' => [
            'clientDataJSON' => PasskeyService::encode($clientData),
            'authenticatorData' => PasskeyService::encode($authData),
            'signature' => PasskeyService::encode($overrides['signature'] ?? $signature),
            'userHandle' => PasskeyService::encode($overrides['handle'] ?? PasskeyService::userHandle($this->user)),
        ]];
    };
});

test('passkeys work over https, or plain http on localhost and *.localhost only, never on IP addresses', function () {
    expect(PasskeyService::site('sys.localhost:5015', false))->toBe(['rp_id' => 'sys.localhost', 'origin' => 'http://sys.localhost:5015'])
        ->and(PasskeyService::site('localhost:5015', false))->toBe(['rp_id' => 'localhost', 'origin' => 'http://localhost:5015'])
        ->and(PasskeyService::site('sys.example.com', true))->toBe(['rp_id' => 'sys.example.com', 'origin' => 'https://sys.example.com'])
        ->and(PasskeyService::site('sys.example.com', false))->toBeNull()
        ->and(PasskeyService::site('10.0.0.5', true))->toBeNull()
        ->and(PasskeyService::site('evil.com/x', true))->toBeNull();
});

test('a passkey registers, signs in, and its counter moves on', function () {
    $options = $this->passkeys->registrationOptions($this->user, 'sys.localhost', $challenge = PasskeyService::challenge());
    $passkey = $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)($challenge), 'YubiKey');

    expect($options['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($passkey->name)->toBe('YubiKey')
        ->and($passkey->rp_id)->toBe('sys.localhost')
        ->and($this->passkeys->registrationOptions($this->user, 'sys.localhost', 'x')['excludeCredentials'])->toHaveCount(1);

    $used = $this->passkeys->verify($this->site, $challenge = PasskeyService::challenge(), ($this->get)($challenge));

    expect($used->id)->toBe($passkey->id)
        ->and($used->sign_count)->toBe(1)
        ->and($used->last_used_at)->not->toBeNull();
});

test('registration refuses another site, another origin, a stale challenge, no user verification, and duplicates', function () {
    $challenge = PasskeyService::challenge();

    expect(fn () => $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)($challenge, ['origin' => 'http://evil.localhost:5015']), 'x'))->toThrow(DomainException::class, 'another site')
        ->and(fn () => $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)($challenge, ['rp_id' => 'evil.localhost']), 'x'))->toThrow(DomainException::class, 'another site')
        ->and(fn () => $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)(PasskeyService::challenge()), 'x'))->toThrow(DomainException::class, 'expired')
        ->and(fn () => $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)($challenge, ['flags' => 0x41]), 'x'))->toThrow(DomainException::class, 'PIN');

    $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)($challenge), 'x');

    expect(fn () => $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)($challenge), 'x'))->toThrow(DomainException::class, 'already');
});

test('sign-in refuses a bad signature, another origin or site, a replayed challenge, a counter going back, and someone else\'s handle', function (array $overrides, bool $sameChallenge) {
    $challenge = PasskeyService::challenge();
    $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)($challenge), 'x');
    $this->passkeys->verify($this->site, $c = PasskeyService::challenge(), ($this->get)($c));

    $challenge = PasskeyService::challenge();
    $credential = ($this->get)($sameChallenge ? $challenge : PasskeyService::challenge(), $overrides);

    expect(fn () => $this->passkeys->verify($this->site, $challenge, $credential))->toThrow(InvalidCredentialsException::class);
})->with([
    'bad signature' => [['signature' => str_repeat("\1", 70)], true],
    'other origin' => [['origin' => 'http://evil.localhost:5015'], true],
    'other site' => [['rp_id' => 'evil.localhost'], true],
    'other challenge' => [[], false],
    'counter back' => [['counter' => 1], true],
    'not verified' => [['flags' => 0x01], true],
    'other user' => [['handle' => 'sys-user:999'], true],
]);

test('sign-in with a passkey through AuthService, rate limited per user; for confirming it must be your own', function () {
    $challenge = PasskeyService::challenge();
    $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)($challenge), 'x');
    $clock = $this->clock;
    $auth = new AuthService($this->passwords, new TotpService($clock), $this->cipher, new LoginThrottleService($clock), new LoginMethodService(), $this->passkeys);

    $result = $auth->attemptPasskey($this->site, $c = PasskeyService::challenge(), ($this->get)($c), '10.0.0.1');
    expect($result->status)->toBe(LoginStatus::Success)->and($result->user->id)->toBe($this->user->id);

    $bob = User::query()->create(['username' => 'bob', 'role' => User::ROLE_USER, 'must_change_password' => false, 'session_version' => 0]);
    expect($auth->confirm($bob, ['passkey' => [$this->site, $c = PasskeyService::challenge(), ($this->get)($c)]], '10.0.0.1')->status)->toBe(LoginStatus::InvalidCode)
        ->and($auth->confirm($this->user, ['passkey' => [$this->site, $c = PasskeyService::challenge(), ($this->get)($c)]], '10.0.0.1')->status)->toBe(LoginStatus::Success);

    for ($i = 0; $i < LoginThrottleService::MAX_FAILURES_PER_USERNAME; $i++) {
        $auth->attemptPasskey($this->site, PasskeyService::challenge(), ($this->get)(PasskeyService::challenge()), "10.0.1.$i");
    }

    expect($auth->attemptPasskey($this->site, $c = PasskeyService::challenge(), ($this->get)($c), '10.0.2.1')->status)->toBe(LoginStatus::TooManyAttempts);

    // Turned off: refused, though still stored.
    (new SettingsService())->update($this->user, [SettingsService::LOGIN_AUTHENTICATOR => 2, SettingsService::LOGIN_PASSKEY => 0]);
})->throws(DomainException::class, 'unable to sign in');

test('asking for a username\'s passkeys lists them, or a made-up id that stays the same, so it doesn\'t tell who exists', function () {
    $challenge = PasskeyService::challenge();
    $this->passkeys->register($this->user, $this->site, $challenge, ($this->create)($challenge), 'x');

    $mine = $this->passkeys->assertionOptions('sys.localhost', 'c', 'admin')['allowCredentials'];
    $nobody = $this->passkeys->assertionOptions('sys.localhost', 'c', 'nobody')['allowCredentials'];

    expect($mine)->toBe([['type' => 'public-key', 'id' => PasskeyService::encode($this->credentialId)]])
        ->and($nobody)->toHaveCount(1)
        ->and($this->passkeys->assertionOptions('sys.localhost', 'c', 'nobody')['allowCredentials'])->toBe($nobody)
        ->and($this->passkeys->assertionOptions('sys.localhost', 'c')['allowCredentials'])->toBe([])
        ->and(Passkey::query()->count())->toBe(1);
});
