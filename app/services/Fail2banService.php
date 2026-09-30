<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\ApacheAdminLog;
use App\Models\Server;
use App\Models\User;
use Carbon\Carbon;
use DomainException;

/**
 * Ban or unban an address with fail2ban on a monitored server (from the Apache access log's client
 * column). Runs `sudo -n fail2ban-client` over the app's SSH login, so it relies on the SSH user's own
 * sudo rules, as the remote Apache editing does: nothing is installed there, and -n never prompts.
 * Every attempt is logged in apache_admin_log.
 */
class Fail2banService
{
    /**
     * The sudoers line an admin can add when the SSH user isn't allowed yet.
     */
    public const SUDOERS_HINT = '%s ALL=(root) NOPASSWD: /usr/bin/fail2ban-client';

    public function __construct(private readonly SshService $ssh = new SshService())
    {
    }

    /**
     * The server's jails, in fail2ban's order.
     *
     * @return list<string>
     *
     * @throws DomainException with a user-facing message
     */
    public function jails(User $admin, Server $server): array
    {
        $this->requireAdmin($admin);
        $connection = $this->connect($server);

        try {
            return $this->jailList($this->run($connection, $server, 'status'));
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @param 'ban'|'unban' $action
     * @return string what fail2ban answered
     *
     * @throws DomainException with a user-facing message (refused, or fail2ban said no)
     */
    public function change(User $admin, Server $server, string $action, string $ip, string $jail, ?string $ownIp = null): string
    {
        $this->requireAdmin($admin);
        $ip = trim($ip);

        if (!in_array($action, ['ban', 'unban'], true)) {
            throw new DomainException('Choose ban or unban.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new DomainException("\"$ip\" isn't an IP address.");
        }

        if ($action === 'ban' && ($ip === $ownIp || $this->isLoopback($ip))) {
            throw new DomainException($ip === $ownIp ? "$ip is your own address: banning it would lock you out." : "$ip is the server itself.");
        }

        if (preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $jail) !== 1) {
            throw new DomainException('Choose one of the server\'s jails.');
        }

        $connection = $this->connect($server);

        try {
            if (!in_array($jail, $this->jailList($this->run($connection, $server, 'status')), true)) {
                throw new DomainException("{$server->name} has no fail2ban jail called $jail.");
            }

            $output = $this->run($connection, $server, 'set ' . escapeshellarg($jail) . ' ' . ($action === 'ban' ? 'banip' : 'unbanip') . ' ' . escapeshellarg($ip));
            $this->log($admin, $server, $action, "$ip in $jail", true, $output);
            $bans = $server->fail2ban_bans ?? [];
            $bans[$jail] = $action === 'ban'
                ? array_values(array_unique([...($bans[$jail] ?? []), $ip]))
                : array_values(array_diff($bans[$jail] ?? [], [$ip]));
            $server->fail2ban_bans = $bans;
            $server->save();

            return trim($output);
        } catch (DomainException $e) {
            $this->log($admin, $server, $action, "$ip in $jail", false, $e->getMessage());

            throw $e;
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Read every jail's banned addresses into the server (fail2ban_bans), over an open SSH connection
     * (the Apache import's). Asks sudo first whether the SSH user may (`sudo -n -l`, which runs nothing),
     * so a server where it may not doesn't collect a failed sudo every few minutes.
     */
    public function readBans(\phpseclib3\Net\SSH2 $connection, Server $server): void
    {
        $script = 'command -v fail2ban-client >/dev/null 2>&1 || { echo "@@none"; exit 0; }; '
            . 'sudo -n -l fail2ban-client status >/dev/null 2>&1 || { echo "@@denied"; exit 0; }; '
            . 'out=$(sudo -n fail2ban-client status 2>&1) || { echo "@@error $out"; exit 0; }; '
            . 'for j in $(printf "%s\n" "$out" | sed -n "s/.*Jail list:[[:space:]]*//p" | tr "," " "); do echo "@@jail $j"; sudo -n fail2ban-client status "$j" 2>&1; done; exit 0';

        try {
            $output = $this->ssh->exec($connection, 'sh -c ' . escapeshellarg($script));
        } catch (ServerConnectionException $e) {
            $output = '@@error ' . $e->getMessage();
        }

        $server->fail2ban_checked_at = Carbon::now();
        $parsed = $this->parseBans($output);

        if (is_string($parsed)) {
            $server->fail2ban_bans = null;
            $server->fail2ban_message = match ($parsed) {
                'none' => 'fail2ban-client isn\'t installed.',
                'denied' => "The SSH user {$server->ssh_username} may not run fail2ban-client with sudo; to see and change bans, add e.g.: " . sprintf(self::SUDOERS_HINT, $server->ssh_username),
                default => $parsed,
            };
        } else {
            $server->fail2ban_bans = $parsed;
            $server->fail2ban_message = null;
        }

        $server->save();
    }

    /**
     * The readBans() script's output: jail => banned addresses, or why there are none.
     *
     * @return array<string, list<string>>|string
     */
    public function parseBans(string $output): array|string
    {
        if (preg_match('/^@@(none|denied)$/m', $output, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^@@error (.*)$/ms', $output, $m) === 1) {
            return 'fail2ban: ' . trim(mb_substr($m[1], 0, 500));
        }

        $bans = [];

        foreach (preg_split('/^@@jail /m', $output) ?: [] as $i => $section) {
            if ($i === 0) {
                continue;
            }

            [$jail, $rest] = array_pad(explode("\n", $section, 2), 2, '');
            $ips = preg_match('/Banned IP list:[ \t]*(.*)$/m', $rest, $m) === 1 ? preg_split('/[\s,]+/', trim($m[1])) : [];
            $bans[trim($jail)] = array_values(array_filter($ips ?: [], fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        }

        return $bans;
    }

    /**
     * The jails in `fail2ban-client status` ("`- Jail list:	sshd, apache-auth").
     *
     * @return list<string>
     */
    public function jailList(string $status): array
    {
        if (preg_match('/Jail list:\s*(.*)$/m', $status, $m) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $m[1])), fn ($j) => $j !== ''));
    }

    private function run(\phpseclib3\Net\SSH2 $connection, Server $server, string $arguments): string
    {
        try {
            return $this->ssh->exec($connection, "sudo -n fail2ban-client $arguments 2>&1");
        } catch (ServerConnectionException $e) {
            $message = $e->getMessage();

            throw new DomainException(match (true) {
                str_contains($message, 'password is required') || str_contains($message, 'not allowed') || str_contains($message, 'may not run')
                    => "The SSH user {$server->ssh_username} may not run fail2ban-client with sudo on {$server->name}. Add a sudoers rule there, e.g.: " . sprintf(self::SUDOERS_HINT, $server->ssh_username),
                str_contains($message, 'command not found') || str_contains($message, 'status 127') || str_contains($message, 'status 1: sudo: fail2ban-client')
                    => "fail2ban-client isn't installed on {$server->name}.",
                default => "fail2ban on {$server->name}: " . trim((string) preg_replace('/^The command exited with status \d+: /', '', $message)),
            });
        }
    }

    private function connect(Server $server): \phpseclib3\Net\SSH2
    {
        if (!$server->sshReady()) {
            throw new DomainException("SSH isn't set up for {$server->name}, so fail2ban can't be reached.");
        }

        try {
            return $this->ssh->connect($server);
        } catch (ServerConnectionException $e) {
            throw new DomainException("Couldn't log in to {$server->name}: {$e->getMessage()}");
        }
    }

    private function isLoopback(string $ip): bool
    {
        return $ip === '::1' || str_starts_with($ip, '127.') || $ip === '0.0.0.0' || $ip === '::';
    }

    private function log(User $admin, Server $server, string $action, string $target, bool $ok, string $output): void
    {
        ApacheAdminLog::query()->create([
            'user_id' => $admin->id, 'server_id' => $server->id, 'action' => "fail2ban $action", 'target' => $target,
            'ok' => $ok, 'output' => mb_substr(trim($output), 0, 4000), 'created_at' => Carbon::now(),
        ]);
    }

    private function requireAdmin(User $admin): void
    {
        if (!$admin->isAdmin()) {
            throw new AuthorizationException('Only admins can ban addresses.');
        }
    }
}
