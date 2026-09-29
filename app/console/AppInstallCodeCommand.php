<?php

namespace App\Console;

use App\Utils\DatabaseConfig;
use App\Utils\InstallCode;
use Leaf\Sprout\Command;

/**
 * `php leaf app:install-code`: the code the install wizard asks for.
 */
class AppInstallCodeCommand extends Command
{
    protected $signature = 'app:install-code';

    protected $description = 'Print the code the install wizard asks for';

    protected function handle()
    {
        if (DatabaseConfig::isConfigured()) {
            $this->comment('The database is already set up; the install wizard is closed.');

            return 0;
        }

        $this->writeln(InstallCode::current());

        return 0;
    }
}
