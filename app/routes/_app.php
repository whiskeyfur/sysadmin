<?php

use App\Middleware\Authenticate;

// Home: the overview of every server.
app()->get('/', ['middleware' => Authenticate::class, 'ServerController@index']);
