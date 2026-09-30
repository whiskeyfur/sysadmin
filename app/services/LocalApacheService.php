<?php

namespace App\Services;

use App\Models\ApacheAdminLog;
use App\Models\User;
use Carbon\Carbon;
use DomainException;

/**
 * This machine's own Apache, managed through sys-apache-helper (the only
 * part of sys that runs as root; bin/sys-apache-helper, installed with
 * `sudo sh bin/install-apache-helper`): list, read and edit site/conf
 * files and apache2.conf/ports.conf, create sites, enable/disable sites,
 * confs and modules, test, reload and restart. The helper backs files up
 * and undoes anything `apache2ctl configtest` refuses. Every change is
 * logged (apache_admin_log). The controller only lets admins on this
 * machine reach it, each change after a fresh confirmation.
 */
class LocalApacheService
{
    public const HELPER = '/usr/local/sbin/sys-apache-helper';

    /**
     * The helper version this code expects (bin/sys-apache-helper HELPER_VERSION).
     */
    public const HELPER_VERSION = 2;

    public const KINDS = ['site' => 'Site', 'conf' => 'Configuration snippet', 'main' => 'apache2.conf', 'ports' => 'ports.conf'];

    /**
     * @param list<string>|null $command how to run the helper (tests run bin/sys-apache-helper directly)
     * @param array<string, string> $env extra environment for it (tests point it at a fake /etc/apache2)
     */
    public function __construct(private readonly ?array $command = null, private readonly array $env = [])
    {
    }

    /**
     * Whether the helper is installed, allowed through sudo, and up to date.
     *
     * @return array{ready: bool, problem: ?string}
     */
    public function status(): array
    {
        if ($this->command === null && !is_file(self::HELPER)) {
            return ['ready' => false, 'problem' => 'The helper isn\'t installed. As root, from ' . dirname(__DIR__, 2) . ': sudo sh bin/install-apache-helper'];
        }

        try {
            $version = $this->call(['version'])['helper'] ?? null;
        } catch (DomainException $e) {
            return ['ready' => false, 'problem' => 'The helper doesn\'t answer (' . $e->getMessage() . '). Run sudo sh bin/install-apache-helper again.'];
        }

        return $version === self::HELPER_VERSION
            ? ['ready' => true, 'problem' => null]
            : ['ready' => false, 'problem' => 'The installed helper is out of date. Run sudo sh bin/install-apache-helper again.'];
    }

    /**
     * Whether the helper is installed at all (for showing the menu item).
     */
    public static function installed(): bool
    {
        return is_file(self::HELPER);
    }

    /**
     * @return array<string, mixed> version, state, sites, confs, mods
     */
    public function overview(): array
    {
        return $this->call(['list']);
    }

    public function read(string $kind, string $name = ''): string
    {
        return (string) ($this->call(['read', $kind, $name])['content'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function write(User $admin, string $kind, string $name, string $content): array
    {
        return $this->change($admin, 'write', trim("$kind $name"), ['write', $kind, $name], $content);
    }

    /**
     * Write (or create) the .htaccess file of a served directory.
     *
     * @return array<string, mixed>
     */
    public function writeHtaccess(User $admin, string $dir, string $content, bool $create = false): array
    {
        return $this->change($admin, $create ? 'create' : 'write', "htaccess $dir", [$create ? 'create' : 'write', 'htaccess', $dir], $content);
    }

    /**
     * Create a site from the form's fields, optionally enabled right away.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createSite(User $admin, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $content = self::siteFile($input);
        $result = $this->change($admin, 'create', "site $name", ['create', 'site', $name], $content);

        if (!empty($input['enable'])) {
            $result = $this->toggle($admin, 'enable', 'site', $name);
        }

        return $result;
    }

    /**
     * @param 'enable'|'disable' $action
     * @return array<string, mixed>
     */
    public function toggle(User $admin, string $action, string $kind, string $name): array
    {
        if (!in_array($action, ['enable', 'disable'], true) || !in_array($kind, ['site', 'conf', 'mod'], true)) {
            throw new DomainException('Unknown change.');
        }

        if ($action === 'disable' && $kind === 'site' && str_contains($this->read('site', $name), dirname(__DIR__, 2) . '/public')) {
            throw new DomainException("$name serves this app: disabling it here would take this page down with it. Do it from a shell if you mean to.");
        }

        return $this->change($admin, $action, "$kind $name", [$action, $kind, $name]);
    }

    /**
     * @param 'test'|'reload'|'restart' $action
     * @return array<string, mixed>
     */
    public function service(User $admin, string $action): array
    {
        if (!in_array($action, ['test', 'reload', 'restart'], true)) {
            throw new DomainException('Unknown action.');
        }

        return $action === 'test' ? $this->call(['test'], null, false) : $this->change($admin, $action, 'apache2', [$action]);
    }

    /**
     * @return list<string>
     */
    public function errorLog(int $lines = 100): array
    {
        return array_values((array) ($this->call(['errorlog', (string) $lines])['lines'] ?? []));
    }

    /**
     * @return list<ApacheAdminLog>
     */
    public function recentChanges(int $limit = 30): array
    {
        /** @var list<ApacheAdminLog> $changes */
        $changes = ApacheAdminLog::query()->with('user')->orderByDesc('id')->limit($limit)->get()->all();

        return $changes;
    }

    /**
     * The file for a new site. Every value is checked, since it goes into
     * Apache's configuration as root.
     *
     * @param array<string, mixed> $input name, server_name, aliases (space/comma/newline separated), port,
     *                                    document_root, error_log, access_log, ssl_certificate, ssl_key
     *
     * @throws DomainException
     */
    public static function siteFile(array $input): string
    {
        $host = '/^(?=.{1,253}$)(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';
        $path = fn (string $field, bool $required) => self::path((string) ($input[$field] ?? ''), $field, $required);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,99}$/', trim((string) ($input['name'] ?? ''))) !== 1) {
            throw new DomainException('The file name is letters, digits and . _ + - (e.g. shop.example.com).');
        }

        $serverName = strtolower(trim((string) ($input['server_name'] ?? '')));

        if (preg_match($host, $serverName) !== 1 || str_starts_with($serverName, '*')) {
            throw new DomainException('Give the site\'s hostname (ServerName), e.g. shop.example.com.');
        }

        $aliases = array_values(array_filter(preg_split('/[\s,]+/', strtolower((string) ($input['aliases'] ?? ''))) ?: []));

        foreach ($aliases as $alias) {
            if (preg_match($host, $alias) !== 1) {
                throw new DomainException("\"$alias\" isn't a hostname.");
            }
        }

        $ssl = trim((string) ($input['ssl_certificate'] ?? '')) !== '' || trim((string) ($input['ssl_key'] ?? '')) !== '';
        $port = (int) (($input['port'] ?? '') ?: ($ssl ? 443 : 80));

        if ($port < 1 || $port > 65535) {
            throw new DomainException('The port must be between 1 and 65535.');
        }

        $lines = ["<VirtualHost *:$port>", "    ServerName $serverName"];

        if ($aliases !== []) {
            $lines[] = '    ServerAlias ' . implode(' ', $aliases);
        }

        $lines[] = '    DocumentRoot "' . $path('document_root', true) . '"';
        $lines[] = '    ErrorLog "' . ($path('error_log', false) ?? '${APACHE_LOG_DIR}/' . $serverName . '-error.log') . '"';
        $lines[] = '    CustomLog "' . ($path('access_log', false) ?? '${APACHE_LOG_DIR}/' . $serverName . '-access.log') . '" combined';

        if ($ssl) {
            $lines[] = '    SSLEngine on';
            $lines[] = '    SSLCertificateFile "' . $path('ssl_certificate', true) . '"';
            $lines[] = '    SSLCertificateKeyFile "' . $path('ssl_key', true) . '"';
        }

        $lines[] = '</VirtualHost>';

        return "# Created by sys.\n" . implode("\n", $lines) . "\n";
    }

    private static function path(string $value, string $field, bool $required): ?string
    {
        $value = trim($value);

        if ($value === '') {
            if ($required) {
                throw new DomainException(ucfirst(str_replace('_', ' ', $field)) . ' is needed.');
            }

            return null;
        }

        if (!str_starts_with($value, '/') || preg_match('#^/[A-Za-z0-9._/@+-]{0,500}$#', $value) !== 1 || str_contains($value, '/../')) {
            throw new DomainException(ucfirst(str_replace('_', ' ', $field)) . ' must be a full path (letters, digits and . _ / @ + -).');
        }

        return $value;
    }

    /**
     * Run a change through the helper and log it, whatever the outcome.
     *
     * @param list<string> $args
     * @return array<string, mixed>
     *
     * @throws DomainException when the helper refused or failed (logged first)
     */
    private function change(User $admin, string $action, string $target, array $args, ?string $stdin = null): array
    {
        if (!$admin->isAdmin()) {
            throw new \App\Exceptions\AuthorizationException('Only admins can change Apache.');
        }

        try {
            $result = $this->call($args, $stdin);
        } catch (DomainException $e) {
            $this->log($admin, $action, $target, false, $e->getMessage());

            throw $e;
        }

        $this->log($admin, $action, $target, true, (string) ($result['output'] ?? ''));

        return $result;
    }

    private function log(User $admin, string $action, string $target, bool $ok, string $output): void
    {
        ApacheAdminLog::query()->create(['user_id' => $admin->id, 'action' => $action, 'target' => $target, 'ok' => $ok, 'output' => mb_substr($output, 0, 60000), 'created_at' => Carbon::now()]);
    }

    /**
     * Run the helper: its JSON answer, or a DomainException with its error (and Apache's output).
     *
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function call(array $args, ?string $stdin = null, bool $throw = true): array
    {
        $command = [...($this->command ?? ['sudo', '-n', self::HELPER]), ...$args];
        $env = ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C'] + $this->env;
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

        if (!is_resource($process)) {
            throw new DomainException('Could not run the helper.');
        }

        fwrite($pipes[0], (string) $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode($out, true);

        if (!is_array($result)) {
            // sudo refusing ("a password is required") ends up here.
            throw new DomainException(trim($err) !== '' ? trim($err) : 'The helper gave no answer.');
        }

        if ($throw && ($result['ok'] ?? false) !== true) {
            throw new DomainException(trim(($result['error'] ?? 'The helper failed.') . (isset($result['output']) && $result['output'] !== '' ? "\n" . $result['output'] : '')));
        }

        return $result;
    }
}
