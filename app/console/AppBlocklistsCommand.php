<?php

namespace App\Console;

use App\Services\BlocklistService;
use App\Utils\DatabaseConfig;
use Leaf\Sprout\Command;

/**
 * `php leaf app:blocklists`: download the public blocklists now and check every client address in the
 * access logs against them (the scheduler does it once a day). See BlocklistService.
 */
class AppBlocklistsCommand extends Command
{
    protected $signature = 'app:blocklists';

    protected $description = 'Download the public blocklists (blocklist.de, Spamhaus DROP) and check the access log clients against them';

    protected function handle()
    {
        if (!DatabaseConfig::isConfigured()) {
            $this->error('The database isn\'t set up yet.');

            return 1;
        }

        foreach ((new BlocklistService())->refresh() as $line) {
            $this->writeln($line);
        }

        return 0;
    }
}
