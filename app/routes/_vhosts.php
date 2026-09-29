<?php

use App\Middleware\Authenticate;

// Apache virtual hosts found in the servers' configurations (Vhosts menu): everyone signed in.
app()->get('/vhosts/reports', ['middleware' => Authenticate::class, 'VhostsController@reports']);
app()->get('/vhosts', ['middleware' => Authenticate::class, 'VhostsController@index']);
