<?php

namespace App\Console;

use App\Models\Server;
use App\Services\ApacheService;
use App\Utils\DatabaseConfig;
use App\Utils\FileSemaphore;
use DomainException;
use Leaf\Sprout\Command;

/**
 * `php leaf app:apache-history SERVER`: import the rotated copies of a server's Apache logs (history
 * from before monitoring started). See ApacheService::importRotated().
 */
class AppApacheHistoryCommand extends Command
{
    protected $signature = 'app:apache-history
        {server : The server, by name or id}';

    protected $description = 'Import the rotated copies of a server\'s Apache logs (access.log.1, error.log.2.gz and so on)';

    protected function handle()
    {
        if (!DatabaseConfig::isConfigured()) {
            $this->error('The database isn\'t set up yet.');

            return 1;
        }

        $name = (string) $this->argument('server');
        $server = Server::query()->where('name', $name)->first() ?? (ctype_digit($name) ? Server::query()->find((int) $name) : null);

        if (!$server instanceof Server) {
            $this->error("No server called $name.");

            return 1;
        }

        // Not while the scheduler imports the same logs.
        $lock = new FileSemaphore(StoragePath('framework/scheduler'), 1);

        while (!$lock->tryAcquire()) {
            $this->comment('Waiting for the scheduled checks to finish...');
            sleep(5);
        }

        try {
            foreach ((new ApacheService())->importRotated($server) as $line) {
                $this->writeln($line);
            }
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return 1;
        } finally {
            $lock->release();
        }

        return 0;
    }
}
