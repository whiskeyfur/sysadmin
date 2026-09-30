<?php

$appPath = dirname(__DIR__);

/*
|--------------------------------------------------------------------------
| Switch to root path
|--------------------------------------------------------------------------
|
| Point to the application root directory so leaf can accurately
| resolve app paths.
|
*/
chdir($appPath);

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader
| for our application. We just need to utilize it! We'll require it
| into the script here so that we do not have to worry about the
| loading of any our classes "manually". Feels great to relax.
|
*/
require "$appPath/vendor/autoload.php";

/*
|--------------------------------------------------------------------------
| Where the app lives
|--------------------------------------------------------------------------
|
| '' at a site's root; e.g. '/sysadmin' when installed in a subdirectory of
| another site (the top-level .htaccess sends requests into public/).
| APP_BASE_PATH in the server's environment (SetEnv) overrides it.
|
*/
\App\Utils\BasePath::set(\App\Utils\BasePath::detect($_SERVER, getenv('APP_BASE_PATH') ?: null));

/*
|--------------------------------------------------------------------------
| Harden the session cookie
|--------------------------------------------------------------------------
|
| Must run before Leaf boots, because the CSRF module starts the session.
|
*/
\App\Services\AuthSessionService::configureSessionCookie();

/*
|--------------------------------------------------------------------------
| Make sure APP_KEY exists
|--------------------------------------------------------------------------
|
| Creates .env from .env.example if needed and generates APP_KEY when it's
| empty, before Leaf loads .env (its CSRF module needs the key at boot).
|
*/
\App\Utils\AppKeyBootstrap::ensureOrFail($appPath);

/*
|--------------------------------------------------------------------------
| Load application paths
|--------------------------------------------------------------------------
|
| Decline static file requests back to the PHP built-in webserver
|
*/
if (php_sapi_name() === 'cli-server') {
    $path = realpath(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

    if (is_string($path) && __FILE__ !== $path && is_file($path)) {
        return false;
    }

    unset($path);
}

/*
|--------------------------------------------------------------------------
| Bring in (env)
|--------------------------------------------------------------------------
|
| Load our environment variables into our application context
|
*/
\Leaf\Core::loadApplicationEnv($appPath);

/*
|--------------------------------------------------------------------------
| Run your Leaf MVC application
|--------------------------------------------------------------------------
|
| This line brings in all your routes and starts your application
|
*/
if (\App\Utils\BasePath::get() !== '') {
    // Routes match without the base, Leaf's redirects add it, and the app's root-relative URLs in its
    // HTML and JSON get it (App\Utils\BasePath::rewriteOutput()).
    app()->setBasePath(\App\Utils\BasePath::get() . '/');
    ob_start(fn (string $body) => \App\Utils\BasePath::rewriteOutput($body, headers_list()));
}

\Leaf\Core::runApplication();
