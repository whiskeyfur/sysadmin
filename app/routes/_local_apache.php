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

// mod_rewrite rules and the URL simulator.
app()->get('/admin/apache/local/rewrite', $localAdmin + ['RewriteController@index']);
app()->get('/admin/apache/local/rewrite/scope', $localAdmin + ['RewriteController@scope']);
app()->get('/admin/apache/local/rewrite/rule', $localAdmin + ['RewriteController@rule']);
app()->post('/admin/apache/local/rewrite/rule', $localAdminHashing + ['RewriteController@saveRule']);
app()->post('/admin/apache/local/rewrite/change', $localAdminHashing + ['RewriteController@change']);
app()->get('/admin/apache/local/simulate', $localAdmin + ['RewriteController@simulate']);

// One <VirtualHost> definition, this machine's or a monitored server's (through its SSH user's sudo rules).
app()->get('/admin/vhost', $localAdmin + ['VhostEditorController@edit']);
app()->post('/admin/vhost', $localAdminHashing + ['VhostEditorController@save']);
app()->post('/admin/vhost/service', $localAdminHashing + ['VhostEditorController@service']);
