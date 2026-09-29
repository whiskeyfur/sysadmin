<?php

use App\Middleware\RequireAdmin;

// Tracked accounts: admins. SSH accounts are under the SSH menu, database
// accounts under MariaDB; the literal paths come before /{id}.
app()->group('/admin/accounts', ['middleware' => RequireAdmin::class, function () {
    app()->get('/', 'AccountController@home');
    app()->get('/ssh', 'AccountController@ssh');
    app()->get('/mariadb', 'AccountController@mariadb');
    app()->post('/mariadb/import', 'AccountController@importUsers');
    app()->get('/new', 'AccountController@create');
    app()->post('/', 'AccountController@store');
    app()->get('/{id}', 'AccountController@show');
    app()->get('/{id}/edit', 'AccountController@edit');
    app()->post('/{id}', 'AccountController@update');
    app()->post('/{id}/password', 'AccountController@recordPassword');
    app()->post('/{id}/reveal', 'AccountController@reveal');
    app()->post('/{id}/delete', 'AccountController@delete');
}]);
