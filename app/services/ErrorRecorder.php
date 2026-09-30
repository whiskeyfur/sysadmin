<?php

namespace App\Services;

use App\Utils\BasePath;
use Leaf\Crash\Redactor;
use Leaf\Crash\Report;
use Leaf\Crash\Reporter;

/**
 * Keeps every uncaught error: a folder per error in storage/errors named after the request's ID
 * (mod_unique_id's, the same one at the end of its access and error log lines; a random one where Apache
 * has none), holding report.json (everything) and report.md (to read). The report is Leaf's (exception,
 * the chain of causes, stack frames with code, breadcrumbs, timings) plus the request as it arrived: method,
 * URL and base path, query and form fields, uploaded files, headers, client, the signed-in user, server,
 * PHP and the git commit running. Passwords, codes, tokens, cookies, passkey data and the like are masked
 * (Leaf's Redactor plus EXTRA_SECRETS). Folders older than KEEP_DAYS are removed as new ones are written.
 *
 * Registered in public/index.php (crash()->reportTo()); Leaf delivers reports after the response, and a
 * reporter must never throw, so anything that goes wrong here is swallowed.
 */
class ErrorRecorder implements Reporter
{
    public const KEEP_DAYS = 30;

    /**
     * Key fragments masked on top of Leaf's (password, token, auth, cookie, session, secret, private...).
     */
    public const EXTRA_SECRETS = ['code', 'otp', 'credential', 'passkey', 'csrf', 'x-leaf', 'key'];

    private static ?string $id = null;

    /**
     * @param array<string, mixed>|null $server $_SERVER (tests pass their own)
     * @param array<string, mixed>|null $get
     * @param array<string, mixed>|null $post
     */
    public function __construct(
        private readonly ?string $dir = null,
        private readonly ?array $server = null,
        private readonly ?array $get = null,
        private readonly ?array $post = null,
    ) {
    }

    /**
     * This request's ID: Apache's (UNIQUE_ID, or REDIRECT_UNIQUE_ID after an internal rewrite), else one
     * made up once per request. The error page shows it.
     *
     * @param array<string, mixed>|null $server
     */
    public static function requestId(?array $server = null): string
    {
        $server ??= $_SERVER;

        foreach (['UNIQUE_ID', 'REDIRECT_UNIQUE_ID', 'REDIRECT_REDIRECT_UNIQUE_ID'] as $key) {
            if (is_string($server[$key] ?? null) && preg_match('/^[A-Za-z0-9@_-]{16,64}$/', $server[$key]) === 1) {
                return $server[$key];
            }
        }

        return self::$id ??= 'sys-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
    }

    /**
     * The production error page (Leaf's, with the request's ID so it can be quoted to an admin).
     */
    public static function page(): string
    {
        $template = dirname(__DIR__, 2) . '/vendor/leafs/exception/src/Crash/Renderer/views/production.html.php';
        $render = static function (string $template, string $message, string $actionUrl): string {
            ob_start();
            include $template;

            return (string) ob_get_clean();
        };

        return $render($template, 'We hit an unexpected problem while handling your request. It has been recorded as ' . self::requestId() . ': quote that to an admin.', BasePath::to('/'));
    }

    public function report(Report $report): void
    {
        try {
            $this->write($report);
        } catch (\Throwable) {
            // Recording must never become a second error.
        }
    }

    /**
     * @return string the folder written
     */
    public function write(Report $report): string
    {
        $server = $this->server ?? $_SERVER;
        $id = self::requestId($server);
        $root = $this->root();

        if (!is_dir($root) && !@mkdir($root, 0o2770, true) && !is_dir($root)) {
            throw new \RuntimeException("Can't create $root.");
        }

        $folder = "$root/" . preg_replace('/[^A-Za-z0-9@_-]/', '_', $id);

        // Several errors in one request (rare): a folder each.
        for ($n = 2; is_dir($folder); $n++) {
            $folder = "$root/" . preg_replace('/[^A-Za-z0-9@_-]/', '_', $id) . "-$n";
        }

        mkdir($folder, 0o2770);
        $data = self::scrub(['id' => $id, 'recorded_at' => gmdate('c')] + $report->toArray() + ['request_details' => $this->request($server), 'environment' => $this->environment($server)]);
        file_put_contents("$folder/report.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        file_put_contents("$folder/report.md", self::scrub($this->markdown($id, $report, $data)));
        @chmod("$folder/report.json", 0o640);
        @chmod("$folder/report.md", 0o640);
        $this->prune($root);

        return $folder;
    }

    /**
     * Secrets inside text, e.g. a database error quoting its SQL with the values filled in: a value given
     * to a column (or field) whose name says it's secret, and password hashes.
     *
     * @template T of array|string
     *
     * @param T $value
     * @return T
     */
    public static function scrub(array|string $value): array|string
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_array($item) || is_string($item)) {
                    $value[$key] = self::scrub($item);
                }
            }

            return $value;
        }

        // Password hashes first (they contain commas): Argon2 and bcrypt.
        $value = (string) preg_replace(['/\$argon2(?:id|i|d)\$v=\d+\$m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+\/=]+\$[A-Za-z0-9+\/=]+/', '/\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}/'], Redactor::MASK, $value);
        $name = '[`"\'\[]?[\w.]*(?:password|passwd|secret|token|code|key|credential)[\w]*[`"\'\]]?';

        return (string) preg_replace('/(' . $name . '\s*(?:=>|:=|=)\s*)(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^\s,)]+)/i', '$1' . Redactor::MASK, $value);
    }

    /**
     * Folders older than KEEP_DAYS.
     */
    public function prune(?string $root = null, ?int $now = null): int
    {
        $root ??= $this->root();
        $before = ($now ?? time()) - self::KEEP_DAYS * 86400;
        $removed = 0;

        foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $folder) {
            if (filemtime($folder) < $before) {
                array_map('unlink', glob("$folder/*") ?: []);
                $removed += @rmdir($folder) ? 1 : 0;
            }
        }

        return $removed;
    }

    /**
     * The request as it arrived, secrets masked.
     *
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function request(array $server): array
    {
        $redactor = new Redactor(self::EXTRA_SECRETS);
        $headers = [];

        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $headers[ucwords(strtolower(str_replace('_', '-', substr($key, 5))), '-')] = $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($server[$key])) {
                $headers[$name] = $server[$key];
            }
        }

        $files = [];

        foreach ($_FILES as $field => $file) {
            $files[$field] = is_array($file) ? array_intersect_key($file, array_flip(['name', 'type', 'size', 'error'])) : $file;
        }

        return [
            'method' => $server['REQUEST_METHOD'] ?? null,
            // The path only: the query string is under "query", masked.
            'uri' => isset($server['REQUEST_URI']) ? (parse_url((string) $server['REQUEST_URI'], PHP_URL_PATH) ?: '/') : null,
            'base_path' => BasePath::get(),
            'route_path' => BasePath::path((string) ($server['REQUEST_URI'] ?? '/')),
            'query' => $redactor->redact($this->get ?? $_GET),
            'form' => $redactor->redact($this->post ?? $_POST),
            'raw_body' => $this->rawBody($server, $redactor),
            'files' => $files,
            'headers' => $redactor->redact($headers),
            'client' => $server['REMOTE_ADDR'] ?? null,
            'user' => $this->user(),
        ];
    }

    /**
     * A JSON body (the passkey and fail2ban requests), masked; other bodies are already in "form" or aren't kept.
     *
     * @param array<string, mixed> $server
     * @return array<mixed>|string|null
     */
    private function rawBody(array $server, Redactor $redactor): array|string|null
    {
        if (!str_contains(strtolower((string) ($server['CONTENT_TYPE'] ?? '')), 'json')) {
            return null;
        }

        $body = @file_get_contents('php://input', false, null, 0, 65536);
        $json = is_string($body) ? json_decode($body, true) : null;

        return is_array($json) ? $redactor->redact($json) : (is_string($body) && $body !== '' ? '(not JSON, ' . strlen($body) . ' bytes, not kept)' : null);
    }

    /**
     * @return array{id: int, username: ?string}|null
     */
    private function user(): ?array
    {
        $id = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['auth']['user_id'] ?? null) : null;

        if (!is_numeric($id)) {
            return null;
        }

        try {
            $username = \App\Models\User::query()->whereKey((int) $id)->value('username');
        } catch (\Throwable) {
            $username = null; // e.g. the database is what failed
        }

        return ['id' => (int) $id, 'username' => is_string($username) ? $username : null];
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function environment(array $server): array
    {
        $keep = ['SERVER_NAME', 'SERVER_PORT', 'SERVER_SOFTWARE', 'HTTPS', 'SCRIPT_NAME', 'DOCUMENT_ROOT', 'REQUEST_TIME_FLOAT'];

        return [
            'server' => array_intersect_key($server, array_flip($keep)),
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'app_root' => dirname(__DIR__, 2),
            'commit' => $this->commit(),
        ];
    }

    /**
     * The git commit the app is running, from .git (no git command).
     */
    private function commit(): ?string
    {
        $git = dirname(__DIR__, 2) . '/.git';
        $head = @file_get_contents("$git/HEAD");

        if (!is_string($head)) {
            return null;
        }

        $head = trim($head);

        if (!str_starts_with($head, 'ref: ')) {
            return substr($head, 0, 12);
        }

        $ref = substr($head, 5);
        $hash = @file_get_contents("$git/$ref");

        if (!is_string($hash) && is_string($packed = @file_get_contents("$git/packed-refs")) && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m) === 1) {
            $hash = $m[1];
        }

        return is_string($hash) ? substr(trim($hash), 0, 12) . ' (' . basename($ref) . ')' : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function markdown(string $id, Report $report, array $data): string
    {
        $details = $data['request_details'];
        $lines = [
            "# Error $id",
            '',
            '- Recorded: ' . $data['recorded_at'],
            '- Request: ' . ($details['method'] ?? '?') . ' ' . ($details['uri'] ?? '?') . ($details['client'] ? " from {$details['client']}" : ''),
            '- User: ' . ($details['user'] ? "{$details['user']['username']} (#{$details['user']['id']})" : 'not signed in'),
            '- Commit: ' . ($data['environment']['commit'] ?? 'unknown') . ', PHP ' . PHP_VERSION,
            '',
            $report->toMarkdown(),
            '',
            '## Request details',
            '',
            '```json',
            (string) json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            '```',
        ];

        return implode("\n", $lines) . "\n";
    }

    private function root(): string
    {
        return $this->dir ?? (getenv('ERRORS_PATH') ?: dirname(__DIR__, 2) . '/storage/errors');
    }
}
