<?php

namespace App\Console;

use Leaf\Sprout\Command;
use Leaf\Sprout\Process;

/**
 * `php leaf app:start`: serve the app with PHP's built-in web server, at the
 * address in APP_URL (default http://localhost:5500). Works the same on
 * Windows, Linux and macOS. Unlike `leaf serve`, it never rewrites .env.
 */
class AppStartCommand extends Command
{
    public const DEFAULT_HOST = 'localhost';

    public const DEFAULT_PORT = 5500;

    protected $signature = 'app:start
        {--s|host= : Host to listen on, defaults to the APP_URL host or localhost}
        {--p|port= : Port to listen on, defaults to the APP_URL port or 5500}';

    protected $description = "Run the app on PHP's built-in web server";

    protected $help = "Serves public/ with PHP's built-in web server at the address in APP_URL. Stop it with Ctrl+C.";

    /**
     * Host and port from the options, else APP_URL, else the defaults.
     *
     * @return array{0: string, 1: int}
     */
    public static function address(?string $host, ?string $port, ?string $appUrl): array
    {
        $url = parse_url((string) $appUrl) ?: [];

        return [
            $host ?: ($url['host'] ?? self::DEFAULT_HOST),
            (int) ($port ?: ($url['port'] ?? (($url['scheme'] ?? '') === 'https' ? 443 : self::DEFAULT_PORT))),
        ];
    }

    protected function handle()
    {
        [$host, $port] = self::address($this->option('host') ?: null, $this->option('port') ?: null, (string) _env('APP_URL', ''));
        $public = getcwd() . DIRECTORY_SEPARATOR . 'public';

        if (!is_dir($public)) {
            $this->error('Run this from the project folder (public/ not found).');

            return 1;
        }

        if ($port < 1 || $port > 65535) {
            $this->error("Invalid port: $port");

            return 1;
        }

        $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if ($socket !== false) {
            fclose($socket);
            $this->error("Something is already listening on $host:$port. Stop it, or pick another port: php leaf app:start --port=" . ($port + 1));

            return 1;
        }

        $this->success("Serving http://$host:$port (Ctrl+C to stop)");

        $command = escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg("$host:$port") . ' -t public public/index.php';

        return (new Process($command))->setTimeout(null)->setWorkingDirectory(getcwd())->run();
    }
}
