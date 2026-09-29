<?php

use App\Middleware\Authenticate;
use App\Middleware\RequireAdmin;

// Monitoring (left side of the navbar): everyone signed in.
app()->get('/servers', ['middleware' => Authenticate::class, 'ServerController@index']);
app()->get('/servers/{id}', ['middleware' => Authenticate::class, 'ServerController@show']);
app()->get('/ssl', ['middleware' => Authenticate::class, 'SslController@index']);

app()->post('/servers/{id}/checks', ['middleware' => RequireAdmin::class, 'ServerController@runChecks']);
app()->post('/ssl/check-all', ['middleware' => RequireAdmin::class, 'SslController@checkAll']);

// Configuration (right side of the navbar): admins.
app()->group('/admin/servers', ['middleware' => RequireAdmin::class, function () {
    app()->get('/', 'ServerConfigController@index');
    app()->get('/new', 'ServerConfigController@create');
    app()->post('/', 'ServerConfigController@store');
    app()->get('/{id}/edit', 'ServerConfigController@edit');
    app()->post('/{id}', 'ServerConfigController@update');
    app()->post('/{id}/delete', 'ServerConfigController@delete');
    app()->post('/{id}/test', 'ServerConfigController@test');
    app()->get('/{id}/ssh-setup', 'ServerConfigController@sshSetup');
    app()->post('/{id}/ssh-setup', 'ServerConfigController@runSshSetup');
}]);
