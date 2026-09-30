<?php

use App\Middleware\LimitConcurrentLogins;
use App\Middleware\RequireLocalAdmin;

/*
| This machine's Apache, through the root helper: admins, from this machine only.
| Changes may hash a password (the fresh check), so they take the concurrency cap too;
| as in _admin.php, that needs a list of closures (route middleware replaces a group's).
*/
$localAdmin = ['middleware' => [fn () => (new RequireLocalAdmin())->call()]];
$localAdminHashing = ['middleware' => [
    fn () => (new RequireLocalAdmin())->call(),
    fn () => (new LimitConcurrentLogins())->call(),
]];

app()->get('/admin/apache/local', $localAdmin + ['LocalApacheController@index']);
app()->get('/admin/apache/local/edit', $localAdmin + ['LocalApacheController@edit']);
app()->get('/admin/apache/local/new', $localAdmin + ['LocalApacheController@create']);
app()->post('/admin/apache/local/edit', $localAdminHashing + ['LocalApacheController@save']);
app()->post('/admin/apache/local/new', $localAdminHashing + ['LocalApacheController@store']);
app()->post('/admin/apache/local/change', $localAdminHashing + ['LocalApacheController@change']);
