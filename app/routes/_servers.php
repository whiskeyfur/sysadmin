<?php

use App\Middleware\Authenticate;
use App\Middleware\RequireAdmin;

// Monitoring (left side of the navbar: SSL, SSH, MariaDB): everyone signed in.
// The overview of all servers is the home page; /servers shows it too.
// Every server with links to its reports (the SysAdmin link, top left); / is the overview of results.
app()->get('/servers', ['middleware' => Authenticate::class, 'ServerController@servers']);
// Reports come first in each menu; registered before /ssl/{id}.
app()->get('/ssl/reports', ['middleware' => Authenticate::class, 'ReportController@ssl']);
app()->get('/ssh/reports', ['middleware' => Authenticate::class, 'ReportController@ssh']);
app()->get('/mariadb/reports', ['middleware' => Authenticate::class, 'ReportController@mariadb']);
app()->get('/mariadb/reports/entries', ['middleware' => Authenticate::class, 'ReportController@mariadbEntries']);
app()->get('/apache/reports', ['middleware' => Authenticate::class, 'ReportController@apache']);
app()->get('/apache/reports/entries', ['middleware' => Authenticate::class, 'ReportController@apacheEntries']);
app()->get('/ssh', ['middleware' => Authenticate::class, 'ServerController@ssh']);
app()->get('/mariadb', ['middleware' => Authenticate::class, 'ServerController@mariadb']);
// The multi-server query tool: everyone signed in, with their own database logins.
app()->get('/mariadb/query', ['middleware' => Authenticate::class, 'MariadbQueryController@index']);
app()->post('/mariadb/query', ['middleware' => Authenticate::class, 'MariadbQueryController@run']);
// Each user's private list of database logins for it.
app()->post('/mariadb/query/accounts', ['middleware' => Authenticate::class, 'MariadbQueryController@addAccount']);
app()->get('/mariadb/query/accounts/{id}', ['middleware' => Authenticate::class, 'MariadbQueryController@editAccount']);
app()->post('/mariadb/query/accounts/{id}', ['middleware' => Authenticate::class, 'MariadbQueryController@updateAccount']);
app()->post('/mariadb/query/accounts/{id}/test', ['middleware' => Authenticate::class, 'MariadbQueryController@testAccount']);
app()->post('/mariadb/query/accounts/{id}/delete', ['middleware' => Authenticate::class, 'MariadbQueryController@deleteAccount']);
app()->get('/apache', ['middleware' => Authenticate::class, 'ServerController@apache']);
app()->get('/servers/{id}', ['middleware' => Authenticate::class, 'ServerController@show']);
app()->get('/ssl', ['middleware' => Authenticate::class, 'SslController@index']);
app()->get('/ssl/{id}', ['middleware' => Authenticate::class, 'SslController@show']);

// Testing and running checks: everyone signed in (non-admins wait CheckCooldown::SECONDS per server).
app()->post('/servers/{id}/checks', ['middleware' => Authenticate::class, 'ServerController@runChecks']);
app()->post('/servers/{id}/test', ['middleware' => Authenticate::class, 'ServerConfigController@test']);
// Importing a MariaDB log reads the server's files over SSH: admins.
app()->post('/servers/{id}/import-log', ['middleware' => RequireAdmin::class, 'ServerController@importLog']);
// Rescanning Apache's configuration: admins.
app()->post('/servers/{id}/apache-rescan', ['middleware' => RequireAdmin::class, 'ServerController@rescanApache']);
app()->post('/ssl/check-all', ['middleware' => Authenticate::class, 'SslController@checkAll']);
app()->post('/ssl/{id}/check', ['middleware' => Authenticate::class, 'SslController@check']);

// Managing certificates and where they're served: admins.
app()->group('/admin/ssl', ['middleware' => RequireAdmin::class, function () {
    app()->get('/new', 'SslController@create');
    app()->post('/', 'SslController@store');
    app()->post('/{id}', 'SslController@update');
    app()->post('/{id}/delete', 'SslController@delete');
    app()->post('/{id}/names', 'SslController@importNames');
    app()->post('/{id}/bindings', 'SslController@addBinding');
    app()->post('/{id}/bindings/{bindingId}/delete', 'SslController@removeBinding');
}]);

// Each module's Add menu item: that module's form for a new server (or one that lacks it).
app()->get('/admin/ssh/new', ['middleware' => RequireAdmin::class, 'ServerConfigController@createSsh']);
app()->get('/admin/mariadb/new', ['middleware' => RequireAdmin::class, 'ServerConfigController@createMariadb']);
app()->get('/admin/apache/new', ['middleware' => RequireAdmin::class, 'ServerConfigController@createApache']);

// Adding, editing and deleting servers, and SSH setup: admins, from the monitoring pages.
app()->group('/admin/servers', ['middleware' => RequireAdmin::class, function () {
    app()->get('/', 'ServerConfigController@index');
    app()->get('/new', 'ServerConfigController@create');
    app()->post('/', 'ServerConfigController@store');
    app()->get('/{id}/edit', 'ServerConfigController@edit');
    app()->post('/{id}', 'ServerConfigController@update');
    app()->post('/{id}/delete', 'ServerConfigController@delete');
    app()->post('/{id}/remove', 'ServerConfigController@remove');
    app()->get('/{id}/ssh-setup', 'ServerConfigController@sshSetup');
    app()->post('/{id}/ssh-setup', 'ServerConfigController@runSshSetup');
    // fail2ban, from a client address in the access log (JSON).
    app()->get('/{id}/fail2ban/jails', 'Fail2banController@jails');
    app()->post('/{id}/fail2ban', 'Fail2banController@change');
}]);
