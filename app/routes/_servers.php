<?php

use App\Middleware\Authenticate;
use App\Middleware\RequireAdmin;

app()->get('/servers', ['middleware' => Authenticate::class, 'ServerController@index']);

app()->group('/admin/servers', ['middleware' => RequireAdmin::class, function () {
    app()->get('/new', 'ServerController@create');
    app()->post('/', 'ServerController@store');
    app()->get('/{id}/edit', 'ServerController@edit');
    app()->post('/{id}', 'ServerController@update');
    app()->post('/{id}/delete', 'ServerController@delete');
    app()->post('/{id}/test', 'ServerController@test');
    app()->get('/{id}/ssh-setup', 'ServerController@sshSetup');
    app()->post('/{id}/ssh-setup', 'ServerController@runSshSetup');
}]);
