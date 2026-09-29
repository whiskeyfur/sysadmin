<?php

use App\Middleware\RequireAdmin;

// Tracked accounts (right side of the navbar): admins.
app()->group('/admin/accounts', ['middleware' => RequireAdmin::class, function () {
    app()->get('/', 'AccountController@index');
    app()->get('/new', 'AccountController@create');
    app()->post('/', 'AccountController@store');
    app()->get('/{id}', 'AccountController@show');
    app()->get('/{id}/edit', 'AccountController@edit');
    app()->post('/{id}', 'AccountController@update');
    app()->post('/{id}/password', 'AccountController@recordPassword');
    app()->post('/{id}/reveal', 'AccountController@reveal');
    app()->post('/{id}/delete', 'AccountController@delete');
}]);
