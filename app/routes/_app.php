<?php

use App\Middleware\Authenticate;
use App\Middleware\AuthenticateAllowingExpiredPassword;
use App\Middleware\LimitConcurrentLogins;

app()->get('/', ['middleware' => Authenticate::class, 'DashboardController@index']);

// Reachable with an expired password. The POST hashes a password, so it
// also takes the concurrency cap; see app/routes/_admin.php for why this is
// a list of closures.
app()->get('/password', ['middleware' => AuthenticateAllowingExpiredPassword::class, 'PasswordController@show']);
app()->post('/password', ['middleware' => [
    fn () => (new AuthenticateAllowingExpiredPassword())->call(),
    fn () => (new LimitConcurrentLogins())->call(),
], 'PasswordController@update']);
