<?php

namespace App\Middleware;

use App\Utils\BasePath;
use App\Utils\DatabaseConfig;
use Leaf\Middleware;

/**
 * Runs before every route: until a database is set up, every page leads to
 * the install wizard (/install/database); afterwards the wizard is gone.
 */
class RequireDatabase extends Middleware
{
    public function call()
    {
        $path = BasePath::path();
        $installing = $path === '/install/database';

        if (!DatabaseConfig::isConfigured() && !$installing) {
            response()->redirect('/install/database');

            return;
        }

        if (DatabaseConfig::isConfigured() && $installing) {
            response()->redirect('/login');
        }
    }
}
