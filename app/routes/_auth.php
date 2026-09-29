<?php

use App\Middleware\LimitConcurrentLogins;

app()->get('/login', 'AuthController@showLogin');
app()->post('/logout', 'AuthController@logout');

app()->get('/setup', 'AuthController@showSetup');
app()->post('/setup', 'AuthController@setup');

// Checks a one-time password (Argon2id) on first sign-in.
app()->post('/login', ['middleware' => LimitConcurrentLogins::class, 'AuthController@login']);
