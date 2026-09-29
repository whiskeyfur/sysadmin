<?php

use App\Middleware\Authenticate;

app()->get('/', ['middleware' => Authenticate::class, 'DashboardController@index']);
