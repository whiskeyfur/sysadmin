<?php

namespace App\Console;

use App\Services\AuthService;
use App\Services\UserAdminService;
use DomainException;
use Leaf\Sprout\Command;

/**
 * `php leaf app:reset-admin [username]`: reset an account (default "admin")
 * to the one-time password "changeme", as on a fresh install. Only possible
 * with shell access to the server, so it's the way back in when locked out.
 */
class AppResetAdminCommand extends Command
{
    protected $signature = 'app:reset-admin
        {username? : The user to reset, defaults to admin}';

    protected $description = 'Reset the admin to the one-time password changeme and remove its authenticator';

    protected $help = 'The next sign-in with the one-time password must set up a new authenticator. The user is signed out everywhere and any login lockout is cleared. A missing admin user is recreated.';

    protected function handle()
    {
        $username = (string) ($this->argument('username') ?: AuthService::DEFAULT_ADMIN_USERNAME);

        try {
            $result = (new UserAdminService())->resetFromConsole($username, AuthService::DEFAULT_ADMIN_PASSWORD);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $user = $result['user'];
        $this->success(($result['created'] ? "Created $username as an admin" : "Reset $username") . ' with the one-time password "' . AuthService::DEFAULT_ADMIN_PASSWORD . '".');
        $this->comment("Sign in with it straight away: you'll set up a new authenticator. Until then, anyone who can reach the site can claim the account.");

        if (!$user->isAdmin()) {
            $this->warning("Note: $username is not an admin.");
        }

        return 0;
    }
}
