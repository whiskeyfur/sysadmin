<?php

use App\Middleware\LimitConcurrentLogins;
use App\Middleware\RequireAdmin;

app()->group('/admin', ['middleware' => RequireAdmin::class, function () {
    app()->get('/users', 'AdminUserController@index');
    app()->post('/users/{id}/promote', 'AdminUserController@promote');
    app()->post('/users/{id}/demote', 'AdminUserController@demote');
    app()->post('/users/{id}/delete', 'AdminUserController@delete');
}]);

/*
| Routes that hash a password also need the concurrency cap. They are
| outside the group on purpose: in Leaf, route-level middleware replaces
| group middleware rather than adding to it, and a middleware list only
| accepts callables or named middleware, not class names.
*/
$adminHashingPassword = ['middleware' => [
    fn () => (new RequireAdmin())->call(),
    fn () => (new LimitConcurrentLogins())->call(),
]];

app()->post('/admin/users', $adminHashingPassword + ['AdminUserController@create']);
app()->post('/admin/users/{id}/reset-password', $adminHashingPassword + ['AdminUserController@resetPassword']);
