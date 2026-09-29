<?php

use App\Middleware\LimitConcurrentLogins;

app()->get('/login', 'AuthController@showLogin');
app()->post('/logout', 'AuthController@logout');
app()->get('/register', 'RegisterController@show');

// Every route that runs a password derivation.
app()->post('/login', ['middleware' => LimitConcurrentLogins::class, 'AuthController@login']);
app()->post('/setup', ['middleware' => LimitConcurrentLogins::class, 'AuthController@setup']);
app()->post('/key/replace', ['middleware' => LimitConcurrentLogins::class, 'AuthController@replaceKey']);
app()->post('/register', ['middleware' => LimitConcurrentLogins::class, 'RegisterController@store']);
