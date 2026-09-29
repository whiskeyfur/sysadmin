<?php

use App\Middleware\LimitConcurrentLogins;

app()->get('/login', 'AuthController@showLogin');
app()->post('/logout', 'AuthController@logout');

// Every route that hashes or checks a password.
app()->post('/login', ['middleware' => LimitConcurrentLogins::class, 'AuthController@login']);
app()->post('/setup', ['middleware' => LimitConcurrentLogins::class, 'AuthController@setup']);
