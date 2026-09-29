<?php

namespace App\Console;

use App\Services\ScheduledCheckService;
use App\Utils\FileSemaphore;
use App\Utils\LocalTime;
use Carbon\Carbon;
use Leaf\Sprout\Command;

/**
 * `php leaf app:run-checks`: run the checks that are due (see
 * ScheduledCheckService). Run it every minute from cron (Linux, macOS) or
 * Task Scheduler (Windows), or keep it running with --loop.
 */
class AppRunChecksCommand extends Command
{
    protected $signature = 'app:run-checks
        {--l|loop : Keep running and check every minute (stop with Ctrl+C)}';

    protected $description = 'Run the scheduled server and certificate checks that are due';

    protected $help = 'Each server is checked every 5 minutes (its check interval), and servers that cannot be reached are retried less often. Run this every minute from cron or Task Scheduler, or use --loop. Runs never overlap.';

    protected function handle()
    {
        if (!\App\Utils\DatabaseConfig::isConfigured()) {
            return 0; // not installed yet: nothing to check (quietly, it runs every minute)
        }

        // One run at a time: a slow server must not make runs pile up.
        $lock = new FileSemaphore(StoragePath('framework/scheduler'), 1);

        if (!$lock->tryAcquire()) {
            $this->comment('Checks are already running; nothing to do.');

            return 0;
        }

        do {
            $started = time();

            foreach ((new ScheduledCheckService())->runDue() as $line) {
                $this->writeln(LocalTime::format(Carbon::now(), 'Y-m-d H:i:s') . "  $line");
            }

            if ($this->option('loop')) {
                sleep(max(1, 60 - (time() - $started)));
            }
        } while ($this->option('loop'));

        $lock->release();

        return 0;
    }
}
