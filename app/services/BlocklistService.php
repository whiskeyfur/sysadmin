<?php

namespace App\Services;

use App\Models\ApacheAccessEntry;
use App\Models\BlocklistIp;
use App\Utils\SystemClock;
use Carbon\Carbon;
use Psr\Clock\ClockInterface;

/**
 * Public blocklists, downloaded once a day and checked locally: no client address is sent anywhere.
 *
 * - blocklist.de: addresses fail2ban users worldwide report for attacking them (single addresses).
 * - Spamhaus DROP: networks hijacked or run by criminals (ranges, IPv4 and IPv6); free to use under
 *   Spamhaus's DROP terms.
 *
 * The lists are kept as files in storage/app/blocklists (one entry per line, with status.json); a
 * failed download keeps the previous copy. Every client address in the access logs gets a row in
 * blocklist_ips saying which lists name it: new ones as they're imported (checkNew()), all of them
 * again after each download (recheckAll()).
 */
class BlocklistService
{
    /**
     * @var array<string, array{label: string, urls: list<string>, about: string}>
     */
    public const SOURCES = [
        'blocklist.de' => [
            'label' => 'blocklist.de',
            'urls' => ['https://lists.blocklist.de/lists/all.txt'],
            'about' => 'addresses fail2ban users report for attacking them',
        ],
        'spamhaus-drop' => [
            'label' => 'Spamhaus DROP',
            'urls' => ['https://www.spamhaus.org/drop/drop_v4.json', 'https://www.spamhaus.org/drop/drop_v6.json'],
            'about' => 'networks hijacked or run by criminals',
        ],
    ];

    public const REFRESH_HOURS = 24;

    /**
     * The most read from one download.
     */
    public const MAX_BYTES = 20 * 1024 * 1024;

    private readonly string $dir;

    /**
     * @var (callable(string): string)|null
     */
    private $fetch;

    /**
     * @var array{ips: array<string, list<string>>, ranges: list<array{0: string, 1: string, 2: string}>}|null
     */
    private ?array $matcher = null;

    /**
     * @param (callable(string): string)|null $fetch downloads a URL (tests pass a stand-in); throws on failure
     */
    public function __construct(?string $dir = null, ?callable $fetch = null, private readonly ClockInterface $clock = new SystemClock())
    {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/storage/app/blocklists';
        $this->fetch = $fetch;
    }

    /**
     * Whether a list is missing or older than REFRESH_HOURS.
     */
    public function due(): bool
    {
        $status = $this->status();
        $before = Carbon::instance($this->clock->now())->subHours(self::REFRESH_HOURS)->getTimestamp();

        foreach (array_keys(self::SOURCES) as $name) {
            if (($status[$name]['fetched_at'] ?? 0) < $before) {
                return true;
            }
        }

        return false;
    }

    /**
     * Download every list (keeping the previous copy of one that fails), then check every client again.
     *
     * @return list<string> one line per list
     */
    public function refresh(): array
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o2775, true) && !is_dir($this->dir)) {
            return ["Can't create {$this->dir}."];
        }

        $status = $this->status();
        $report = [];

        foreach (self::SOURCES as $name => $source) {
            $entries = [];

            try {
                foreach ($source['urls'] as $url) {
                    array_push($entries, ...$this->parse($this->download($url)));
                }

                if ($entries === []) {
                    throw new \RuntimeException('the download had no addresses in it');
                }

                $entries = array_values(array_unique($entries));
                $file = "{$this->dir}/$name.txt";
                file_put_contents("$file.tmp", implode("\n", $entries) . "\n");
                rename("$file.tmp", $file);
                $status[$name] = ['fetched_at' => $this->clock->now()->getTimestamp(), 'entries' => count($entries), 'error' => null];
                $report[] = "{$source['label']}: " . number_format(count($entries)) . ' entries.';
            } catch (\Throwable $e) {
                $status[$name] = ['fetched_at' => $status[$name]['fetched_at'] ?? 0, 'entries' => $status[$name]['entries'] ?? 0, 'error' => $e->getMessage()];
                $report[] = "{$source['label']}: download failed ({$e->getMessage()}); " . ($status[$name]['entries'] > 0 ? 'the previous copy stays.' : 'no copy yet.');
            }
        }

        file_put_contents("{$this->dir}/status.json", json_encode($status, JSON_PRETTY_PRINT));
        $this->matcher = null;
        $listed = $this->recheckAll();
        $report[] = "Client addresses checked again: $listed listed.";

        return $report;
    }

    /**
     * Per list: when it was downloaded, how many entries, the last error.
     *
     * @return array<string, array{fetched_at: int, entries: int, error: ?string}>
     */
    public function status(): array
    {
        $file = "{$this->dir}/status.json";
        $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($status) ? $status : [];
    }

    /**
     * The entries of a downloaded list: addresses and CIDR ranges, from plain lines ("1.2.3.4",
     * "1.2.3.0/24 ; SBL123") or JSON lines ({"cidr": "1.2.3.0/24", ...}); comments and anything else
     * skipped.
     *
     * @return list<string>
     */
    public function parse(string $text): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }

            if ($line[0] === '{') {
                $json = json_decode($line, true);
                $line = is_array($json) && is_string($json['cidr'] ?? null) ? $json['cidr'] : '';
            } else {
                $line = (string) preg_split('/[\s;#]/', $line)[0];
            }

            if ($this->range($line) !== null) {
                $entries[] = strtolower($line);
            }
        }

        return $entries;
    }

    /**
     * The lists that name an address (by label).
     *
     * @return list<string>
     */
    public function match(string $ip): array
    {
        $matcher = $this->matcher();
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return [];
        }

        $sources = $matcher['ips'][(string) inet_ntop($binary)] ?? [];

        foreach ($matcher['ranges'] as [$start, $end, $source]) {
            if (strlen($start) === strlen($binary) && strcmp($binary, $start) >= 0 && strcmp($binary, $end) <= 0) {
                $sources[] = $source;
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * Check the client addresses in the access logs that haven't been checked yet.
     *
     * @return int how many of them are listed
     */
    public function checkNew(): int
    {
        $known = BlocklistIp::query()->select('ip');
        $ips = ApacheAccessEntry::query()->whereNotNull('client')->whereNotIn('client', $known)->distinct()->pluck('client')->all();

        return $this->store(array_map('strval', $ips));
    }

    /**
     * Check every client address in the access logs again (after new lists), and forget the ones no
     * longer there.
     *
     * @return int how many are listed
     */
    public function recheckAll(): int
    {
        $ips = array_map('strval', ApacheAccessEntry::query()->whereNotNull('client')->distinct()->pluck('client')->all());
        BlocklistIp::query()->whereNotIn('ip', ApacheAccessEntry::query()->whereNotNull('client')->select('client'))->delete();

        return $this->store($ips);
    }

    /**
     * Which lists name each of these addresses (only the listed ones).
     *
     * @param list<string> $ips
     * @return array<string, string> address => lists, comma-separated
     */
    public function listed(array $ips): array
    {
        if ($ips === []) {
            return [];
        }

        return array_map('strval', BlocklistIp::query()->whereIn('ip', array_values(array_unique($ips)))->whereNotNull('sources')->pluck('sources', 'ip')->all());
    }

    /**
     * @param list<string> $ips
     */
    private function store(array $ips): int
    {
        $now = Carbon::instance($this->clock->now())->format('Y-m-d H:i:s');
        $listed = 0;

        foreach (array_chunk($ips, 500) as $chunk) {
            $rows = [];

            foreach ($chunk as $ip) {
                $sources = $this->match($ip);
                $listed += $sources === [] ? 0 : 1;
                $rows[] = ['ip' => $ip, 'sources' => $sources === [] ? null : mb_substr(implode(', ', $sources), 0, 255), 'checked_at' => $now];
            }

            BlocklistIp::query()->upsert($rows, ['ip'], ['sources', 'checked_at']);
        }

        return $listed;
    }

    /**
     * @return array{ips: array<string, list<string>>, ranges: list<array{0: string, 1: string, 2: string}>}
     */
    private function matcher(): array
    {
        if ($this->matcher !== null) {
            return $this->matcher;
        }

        $matcher = ['ips' => [], 'ranges' => []];

        foreach (self::SOURCES as $name => $source) {
            $file = "{$this->dir}/$name.txt";

            if (!is_file($file)) {
                continue;
            }

            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $entry) {
                $range = $this->range($entry);

                if ($range === null) {
                    continue;
                }

                if ($range[0] === $range[1]) {
                    $matcher['ips'][(string) inet_ntop($range[0])][] = $source['label'];
                } else {
                    $matcher['ranges'][] = [$range[0], $range[1], $source['label']];
                }
            }
        }

        return $this->matcher = $matcher;
    }

    /**
     * An address or CIDR range as its first and last address (binary, as inet_pton gives them).
     *
     * @return array{0: string, 1: string}|null
     */
    private function range(string $entry): ?array
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $binary = @inet_pton((string) $address);

        if ($binary === false) {
            return null;
        }

        $length = strlen($binary) * 8;
        $bits = $bits === null ? $length : (ctype_digit($bits) ? (int) $bits : -1);

        if ($bits < 0 || $bits > $length) {
            return null;
        }

        $start = '';
        $end = '';

        for ($i = 0; $i < strlen($binary); $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $start .= chr(ord($binary[$i]) & $mask);
            $end .= chr((ord($binary[$i]) & $mask) | (~$mask & 0xFF));
        }

        return [$start, $end];
    }

    /**
     * @throws \RuntimeException
     */
    private function download(string $url): string
    {
        if ($this->fetch !== null) {
            return ($this->fetch)($url);
        }

        $context = stream_context_create([
            'http' => ['timeout' => 30, 'user_agent' => 'sys blocklist check', 'follow_location' => 1, 'max_redirects' => 3],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => (new CaCertificateService())->systemBundle()],
        ]);
        // Network and TLS errors arrive as PHP warnings, which Leaf would turn into exceptions of its own.
        $warning = null;
        set_error_handler(function (int $level, string $message) use (&$warning) {
            $warning = $message;

            return true;
        });

        try {
            $stream = fopen($url, 'rb', false, $context);
            $body = $stream === false ? false : stream_get_contents($stream, self::MAX_BYTES);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw new \RuntimeException($warning ?? "couldn't open $url");
        }

        if ($body === false) {
            throw new \RuntimeException("couldn't read $url");
        }

        return $body;
    }
}
