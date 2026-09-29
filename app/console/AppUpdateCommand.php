<?php

namespace App\Console;

use Leaf\Sprout\Command;
use Leaf\Sprout\Process;

/**
 * `php leaf app:update`: bring a copy of the app up to date in one step:
 * git pull (fast-forward only), composer install, db:migrate. Stops at the
 * first failure. Works the same on Windows, Linux and macOS.
 */
class AppUpdateCommand extends Command
{
    protected $signature = 'app:update
        {--no-pull : Skip git pull and only install dependencies and migrate}';

    protected $description = 'Update the app: git pull, composer install, db:migrate';

    protected $help = 'Pulls the latest code (fast-forward only, so local changes are never merged or overwritten), installs dependencies and applies database changes.';

    protected function handle()
    {
        $php = escapeshellarg(PHP_BINARY);

        if ($this->option('no-pull')) {
            $this->comment('Skipping git pull.');
        } elseif (!is_dir(getcwd() . DIRECTORY_SEPARATOR . '.git')) {
            $this->comment('Not a git checkout, so skipping git pull. Replace the files by hand to update the code.');
        } elseif (!$this->step('Pulling the latest code', 'git pull --ff-only')) {
            $this->error('git pull failed. If you have local changes, commit or stash them first; nothing was overwritten.');

            return 1;
        }

        if (!$this->step('Installing dependencies', $this->composer() . ' install --no-interaction')) {
            return 1;
        }

        if (!$this->step('Applying database changes', "$php leaf db:migrate")) {
            return 1;
        }

        $this->success('Up to date. Restart the server if it is running.');

        return 0;
    }

    private function step(string $title, string $command): bool
    {
        $this->info("> $title: $command");

        try {
            $status = (new Process($command))->setTimeout(null)->setWorkingDirectory(getcwd())->run();
        } catch (\Throwable $e) {
            $this->error("$title failed: {$e->getMessage()}");

            return false;
        }

        if ($status !== 0) {
            $this->error("$title failed (exit code $status).");

            return false;
        }

        return true;
    }

    /**
     * Composer as run by Composer itself when available, else from PATH
     * (composer, or composer.bat on Windows).
     */
    private function composer(): string
    {
        $binary = getenv('COMPOSER_BINARY');

        return is_string($binary) && $binary !== '' ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary) : 'composer';
    }
}
