<?php

namespace App\Services;

use App\Exceptions\ServerConnectionException;
use App\Models\ApacheAdminLog;
use App\Models\Server;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use phpseclib3\Net\SSH2;

/**
 * Changing a monitored server's Apache over SSH, relying on the SSH user's
 * own sudo rules (nothing is installed there): its configuration test,
 * reload/restart, and reading/writing configuration files (directly when
 * the SSH user may, else `sudo tee FILE`). What's allowed is asked with
 * `sudo -n -l COMMAND`, which checks a rule without running anything; `-n`
 * means sudo never asks for a password, so nothing hangs and no password
 * is tried. A write is checked with the configuration test and put back if
 * Apache refuses it; the old text is kept in apache_admin_log.previous.
 * File contents travel base64-encoded through a mode-600 file in the SSH
 * user's home (one command can only be so long), never on a command line.
 *
 * Example sudoers rule on a Debian server (visudo -f /etc/sudoers.d/sys):
 *   deploy ALL=(root) NOPASSWD: /usr/sbin/apache2ctl configtest, /usr/bin/systemctl reload apache2,
 *     /usr/bin/systemctl restart apache2, /usr/bin/tee /etc/apache2/sites-available/*
 */
class RemoteApacheService
{
    public const MAX_CONTENT = 524288;

    private const CHUNK = 60000;

    public function __construct(private readonly SshService $ssh = new SshService())
    {
    }

    /**
     * What the SSH user may do with Apache there.
     *
     * @return array{ctl: ?string, test: ?string, service: ?string, reload: bool, restart: bool, graceful: bool, sudo: bool}
     *         test: the configtest arguments allowed ("configtest" or "-t"), or null
     */
    public function capabilities(Server $server, ?SSH2 $connection = null): array
    {
        $script = <<<'SH'
            ctl=""
            for c in apache2ctl apachectl; do
                p=$(command -v "$c" 2>/dev/null || { [ -x "/usr/sbin/$c" ] && echo "/usr/sbin/$c"; })
                [ -n "$p" ] && { ctl="$p"; break; }
            done
            echo "ctl:$ctl"
            svc=""
            for s in apache2 httpd; do
                if systemctl list-unit-files "$s.service" 2>/dev/null | grep -q "^$s.service"; then svc="$s"; break; fi
            done
            echo "service:$svc"
            command -v sudo >/dev/null 2>&1 && echo "sudo:yes"
            if [ -n "$ctl" ]; then
                sudo -n -l "$ctl" configtest >/dev/null 2>&1 && echo "test:configtest"
                sudo -n -l "$ctl" -t >/dev/null 2>&1 && echo "test:-t"
                sudo -n -l "$ctl" graceful >/dev/null 2>&1 && echo "graceful:yes"
            fi
            if [ -n "$svc" ]; then
                sudo -n -l systemctl reload "$svc" >/dev/null 2>&1 && echo "reload:yes"
                sudo -n -l systemctl restart "$svc" >/dev/null 2>&1 && echo "restart:yes"
            fi
            exit 0
            SH;
        $output = $this->withConnection($server, $connection, fn (SSH2 $ssh) => $this->ssh->exec($ssh, 'sh -c ' . escapeshellarg($script)));
        $found = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^(\w+):(.*)$/', trim($line), $m) === 1) {
                $found[$m[1]][] = trim($m[2]);
            }
        }

        $ctl = ($found['ctl'][0] ?? '') ?: null;

        return [
            'ctl' => $ctl,
            'test' => in_array('configtest', $found['test'] ?? [], true) ? 'configtest' : (in_array('-t', $found['test'] ?? [], true) ? '-t' : null),
            'service' => ($found['service'][0] ?? '') ?: null,
            'reload' => isset($found['reload']),
            'restart' => isset($found['restart']),
            'graceful' => isset($found['graceful']),
            'sudo' => isset($found['sudo']),
        ];
    }

    /**
     * A configuration file's text and how it could be written.
     *
     * @return array{text: string, write: ?string} write: "direct", "sudo" or null (can't)
     *
     * @throws DomainException when it can't be read
     */
    public function readFile(Server $server, string $path, ?SSH2 $connection = null): array
    {
        $this->checkPath($path);

        return $this->withConnection($server, $connection, function (SSH2 $ssh) use ($path) {
            $file = escapeshellarg($path);
            $access = $this->ssh->exec($ssh, 'sh -c ' . escapeshellarg("if [ -r $file ]; then echo read:direct; elif sudo -n -l cat $file >/dev/null 2>&1; then echo read:sudo; fi; "
                . "if [ -w $file ]; then echo write:direct; elif sudo -n -l tee $file >/dev/null 2>&1; then echo write:sudo; fi; exit 0"));
            $read = preg_match('/read:(\w+)/', $access, $m) === 1 ? $m[1] : null;
            $write = preg_match('/write:(\w+)/', $access, $w) === 1 ? $w[1] : null;

            if ($read === null) {
                throw new DomainException("{$path} can't be read by the SSH user, and no sudo rule allows `cat` on it.");
            }

            try {
                $text = $this->ssh->exec($ssh, ($read === 'sudo' ? 'sudo -n cat -- ' : 'cat -- ') . $file);
            } catch (ServerConnectionException $e) {
                throw new DomainException("Reading $path failed: {$e->getMessage()}");
            }

            return ['text' => $text, 'write' => $write];
        });
    }

    /**
     * Replace a configuration file, if it's still what the page saw: then the configuration test,
     * and the old text back if Apache refuses the new one.
     *
     * @return array{output: string}
     *
     * @throws DomainException (logged)
     */
    public function writeFile(User $admin, Server $server, string $path, string $expectedHash, string $text): array
    {
        if (!$admin->isAdmin()) {
            throw new \App\Exceptions\AuthorizationException('Only admins can change Apache.');
        }

        if (strlen($text) > self::MAX_CONTENT || str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) {
            throw new DomainException('The file must be UTF-8 text of at most ' . self::MAX_CONTENT . ' bytes.');
        }

        $old = null;

        try {
            $result = $this->withConnection($server, null, function (SSH2 $ssh) use ($server, $path, $expectedHash, $text, &$old) {
                $caps = $this->capabilities($server, $ssh);

                if ($caps['test'] === null) {
                    throw new DomainException('No sudo rule lets the SSH user run the configuration test (e.g. `' . ($caps['ctl'] ?? 'apachectl') . ' configtest`), so a change can\'t be checked: nothing was written.');
                }

                $current = $this->readFile($server, $path, $ssh);
                $old = $current['text'];

                if (!hash_equals($expectedHash, hash('sha256', $old))) {
                    throw new DomainException('The file changed since you opened it; nothing was written. Reload and make the change again.');
                }

                if ($current['write'] === null) {
                    throw new DomainException("The SSH user can't write $path, and no sudo rule allows `tee $path`.");
                }

                $this->put($ssh, $path, $text, $current['write']);
                $test = $this->configtest($ssh, $caps);

                if (!$test['ok']) {
                    $this->put($ssh, $path, $old, $current['write']);

                    throw new DomainException("Apache refused the configuration, so the old file was put back.\n" . $test['output']);
                }

                return ['output' => $test['output']];
            });
        } catch (DomainException | ServerConnectionException $e) {
            $this->log($admin, $server, 'write', $path, false, $e->getMessage(), $old);

            throw new DomainException($e->getMessage());
        }

        $this->log($admin, $server, 'write', $path, true, $result['output'], $old);

        return $result;
    }

    /**
     * @param 'test'|'reload'|'restart' $action
     * @return array{ok: bool, output: string}
     *
     * @throws DomainException when sudo doesn't allow it (logged, except for tests)
     */
    public function service(User $admin, Server $server, string $action): array
    {
        try {
            $result = $this->withConnection($server, null, function (SSH2 $ssh) use ($server, $action) {
                $caps = $this->capabilities($server, $ssh);

                if ($caps['test'] === null) {
                    throw new DomainException('No sudo rule lets the SSH user run the configuration test.');
                }

                $test = $this->configtest($ssh, $caps);

                if ($action === 'test' || !$test['ok']) {
                    if (!$test['ok'] && $action !== 'test') {
                        throw new DomainException("Apache refused the configuration, so it wasn't {$action}ed.\n" . $test['output']);
                    }

                    return $test;
                }

                $command = match (true) {
                    $caps[$action] && $caps['service'] !== null => 'sudo -n systemctl ' . $action . ' ' . escapeshellarg($caps['service']),
                    $action === 'reload' && $caps['graceful'] => 'sudo -n ' . escapeshellarg((string) $caps['ctl']) . ' graceful',
                    default => throw new DomainException("No sudo rule lets the SSH user $action Apache (e.g. `systemctl $action " . ($caps['service'] ?? 'apache2') . '`).'),
                };

                try {
                    return ['ok' => true, 'output' => trim($test['output'] . "\n" . $this->ssh->exec($ssh, "$command 2>&1"))];
                } catch (ServerConnectionException $e) {
                    throw new DomainException("$action failed: {$e->getMessage()}");
                }
            });
        } catch (DomainException | ServerConnectionException $e) {
            if ($action !== 'test') {
                $this->log($admin, $server, $action, 'apache', false, $e->getMessage());
            }

            throw new DomainException($e->getMessage());
        }

        if ($action !== 'test') {
            $this->log($admin, $server, $action, 'apache', true, $result['output']);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $caps
     * @return array{ok: bool, output: string}
     */
    private function configtest(SSH2 $ssh, array $caps): array
    {
        try {
            return ['ok' => true, 'output' => trim($this->ssh->exec($ssh, 'sudo -n ' . escapeshellarg((string) $caps['ctl']) . ' ' . $caps['test'] . ' 2>&1'))];
        } catch (ServerConnectionException $e) {
            return ['ok' => false, 'output' => trim((string) preg_replace('/^The command exited with status \d+: /', '', $e->getMessage()))];
        }
    }

    /**
     * Stage the text in the SSH user's home (mode 600, base64 in chunks), then copy it over the file
     * (keeping the file's owner and mode) directly or with sudo tee, and remove the stage.
     */
    private function put(SSH2 $ssh, string $path, string $text, string $how): void
    {
        $stage = '$HOME/.sys-stage-' . bin2hex(random_bytes(6));
        $encoded = str_split(base64_encode($text), self::CHUNK) ?: [''];

        try {
            foreach ($encoded as $i => $chunk) {
                $this->ssh->exec($ssh, 'sh -c ' . escapeshellarg("umask 077; printf %s '$chunk' " . ($i === 0 ? '>' : '>>') . " \"$stage.b64\""));
            }

            $copy = $how === 'sudo' ? 'sudo -n tee ' . escapeshellarg($path) . ' >/dev/null' : 'cat > ' . escapeshellarg($path);
            $this->ssh->exec($ssh, 'sh -c ' . escapeshellarg("umask 077; base64 -d \"$stage.b64\" > \"$stage\" && [ \"\$(wc -c < \"$stage\")\" -eq " . strlen($text) . " ] && $copy < \"$stage\""));
        } catch (ServerConnectionException $e) {
            throw new DomainException("Writing $path failed: {$e->getMessage()}");
        } finally {
            try {
                $this->ssh->exec($ssh, 'sh -c ' . escapeshellarg("rm -f \"$stage\" \"$stage.b64\""));
            } catch (ServerConnectionException) {
                // Nothing more to do; the stage is in the SSH user's home, mode 600.
            }
        }
    }

    private function checkPath(string $path): void
    {
        if (!str_starts_with($path, '/') || str_contains($path, '/../') || preg_match('#^/[A-Za-z0-9._/@+-]+$#', $path) !== 1) {
            throw new DomainException('Not a plain full path.');
        }
    }

    /**
     * @template T
     * @param callable(SSH2): T $work
     * @return T
     */
    private function withConnection(Server $server, ?SSH2 $connection, callable $work): mixed
    {
        if ($connection !== null) {
            return $work($connection);
        }

        if (!$server->sshReady()) {
            throw new DomainException("SSH isn't set up for {$server->name}.");
        }

        $ssh = $this->ssh->connect($server);

        try {
            return $work($ssh);
        } finally {
            $ssh->disconnect();
        }
    }

    private function log(User $admin, Server $server, string $action, string $target, bool $ok, string $output, ?string $previous = null): void
    {
        ApacheAdminLog::query()->create([
            'user_id' => $admin->id, 'server_id' => $server->id, 'action' => $action, 'target' => $target, 'ok' => $ok,
            'output' => mb_substr($output, 0, 60000), 'previous' => $previous, 'created_at' => Carbon::now(),
        ]);
    }
}
