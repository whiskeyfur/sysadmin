<?php

use App\Middleware\Authenticate;
use App\Middleware\LimitConcurrentLogins;

app()->get('/login', 'AuthController@showLogin');
app()->post('/logout', 'AuthController@logout');

// Setup moved to Profile.
app()->get('/setup', 'AuthController@showSetup');

// Checks a password (Argon2id): the user's own, or a one-time password on first sign-in.
app()->post('/login', ['middleware' => LimitConcurrentLogins::class, 'AuthController@login']);

// Passkeys (JSON, called by the login page's script).
app()->post('/login/passkey/options', 'AuthController@passkeyOptions');
app()->post('/login/passkey', 'AuthController@passkeyLogin');

// Your own ways to sign in. Accounts still in setup (or missing a required method) can reach only these.
// The two that hash a password also take the concurrency cap: outside the group, because route-level
// middleware replaces the group's (see _admin.php).
$signedInHashing = ['middleware' => [
    fn () => (new Authenticate())->call(),
    fn () => (new LimitConcurrentLogins())->call(),
]];

app()->post('/profile/confirm', $signedInHashing + ['ProfileController@confirm']);
app()->post('/profile/password', $signedInHashing + ['ProfileController@setPassword']);

app()->group('/profile', ['middleware' => Authenticate::class, function () {
    app()->get('/', 'ProfileController@show');
    app()->post('/confirm/passkey/options', 'ProfileController@confirmPasskeyOptions');
    app()->post('/confirm/passkey', 'ProfileController@confirmPasskey');
    app()->post('/password/remove', 'ProfileController@removePassword');
    app()->post('/authenticator/start', 'ProfileController@startAuthenticator');
    app()->post('/authenticator', 'ProfileController@enrolAuthenticator');
    app()->post('/authenticator/remove', 'ProfileController@removeAuthenticator');
    app()->post('/passkeys/options', 'ProfileController@passkeyOptions');
    app()->post('/passkeys', 'ProfileController@addPasskey');
    app()->post('/passkeys/{id}/remove', 'ProfileController@removePasskey');
    app()->post('/finish', 'ProfileController@finish');
}]);
