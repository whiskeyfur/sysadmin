<?php

use App\Middleware\RequireAdmin;

app()->group('/admin', ['middleware' => RequireAdmin::class, function () {
    app()->get('/users', 'AdminUserController@index');
    app()->post('/users/{id}/approve', 'AdminUserController@approve');
    app()->post('/users/{id}/reject', 'AdminUserController@reject');
    app()->post('/key-file', 'KeyFileController@download');
}]);
