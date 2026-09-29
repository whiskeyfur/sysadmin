<?php

use App\Middleware\LimitConcurrentLogins;
use App\Middleware\RequireAdmin;

app()->group('/admin', ['middleware' => RequireAdmin::class, function () {
    app()->get('/users', 'AdminUserController@index');
    app()->post('/users/{id}/approve', 'AdminUserController@approve');
    app()->post('/users/{id}/reject', 'AdminUserController@reject');
    app()->post('/users/{id}/promote', 'AdminUserController@promote');
    app()->post('/users/{id}/demote', 'AdminUserController@demote');
    app()->get('/users/{id}/delete', 'AdminUserController@confirmDelete');
    app()->get('/key/rotate', 'AdminUserController@confirmRotate');
    app()->post('/key-file', 'KeyFileController@download');
}]);

/*
| Routes that run a password derivation also need the concurrency cap.
| They are outside the group on purpose: in Leaf, route-level middleware
| replaces group middleware rather than adding to it, and a middleware list
| only accepts callables or named middleware, not class names.
*/
$requireAdmin = fn () => (new RequireAdmin())->call();
$limitConcurrentLogins = fn () => (new LimitConcurrentLogins())->call();
$adminWithPassword = ['middleware' => [$requireAdmin, $limitConcurrentLogins]];

app()->post('/admin/users/{id}/reset-password', $adminWithPassword + ['AdminUserController@resetPassword']);
app()->post('/admin/users/{id}/delete', $adminWithPassword + ['AdminUserController@delete']);
app()->post('/admin/key/rotate', $adminWithPassword + ['AdminUserController@rotate']);
