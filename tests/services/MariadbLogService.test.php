<?php

use App\Enums\ServerPlatform;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ServerConnectionException;
use App\Models\MariadbLogEntry;
use App\Models\Server;
use App\Models\User;
use App\Services\MariadbConfigParser;
use App\Services\MariadbLogParser;
use App\Services\MariadbLogService;
use App\Services\ServerService;
use App\Services\SshService;
use phpseclib3\Net\SSH2;

beforeEach(function () {
    $this->servers = new ServerService($this->cipher);
    $this->admin = new User(['username' => 'admin', 'role' => User::ROLE_ADMIN]);
    $this->server = $this->servers->create($this->admin, [
        'name' => 'db', 'hostname' => 'db.example.com', 'ssh_port' => 22, 'ssh_username' => 'ops',
        'mysql_enabled' => '1', 'mysql_username' => 'mon', 'mysql_password' => 'pw!', 'mysql_tls' => 'off',
    ]);
    $this->server->ssh_host_key = 'ssh-ed25519 AAAA';
    $this->server->save();

    // A scripted server: file contents, directory listings and other command output; everything else fails.
    $this->ssh = new class () extends SshService {
        public array $files = [];
        public array $outputs = [];
        public array $commands = [];
        public ServerPlatform $platform = ServerPlatform::Unix;

        public function __construct()
        {
        }

        public function connect(Server $server): SSH2
        {
            return new class ('localhost') extends SSH2 {
                public function disconnect()
                {
                }
            };
        }

        public function platformOf(SSH2 $ssh): ServerPlatform
        {
            return $this->platform;
        }

        public function exec(SSH2 $ssh, string $command): string
        {
            $this->commands[] = $command;

            if (preg_match("/^(?:cat|tail -c \\d+) -- '([^']+)'/", $command, $m) === 1) {
                if (!array_key_exists($m[1], $this->files)) {
                    throw new ServerConnectionException("The command exited with status 1: cat: {$m[1]}: No such file or directory");
                }

                if ($this->files[$m[1]] === false) {
                    throw new ServerConnectionException("The command exited with status 1: tail: cannot open '{$m[1]}' for reading: Permission denied");
                }

                return $this->files[$m[1]];
            }

            foreach ($this->outputs as $pattern => $output) {
                if (str_contains($command, $pattern)) {
                    return $output;
                }
            }

            throw new ServerConnectionException('The command exited with status 127: not found');
        }
    };
    $this->ssh->outputs = [
        'date +%z' => "-0700\n",
        'hostname' => "db\n",
        'my_print_defaults --help' => "my_print_defaults  Ver 1.7\nDefault options are read from the following files in the given order:\n/etc/my.cnf /etc/mysql/my.cnf ~/.my.cnf\n",
    ];
    $test = $this;
    $this->service = fn (array $variables = []) => new class ($this->ssh, $variables, $this->clock, $this->servers) extends MariadbLogService {
        public function __construct(SshService $ssh, private array $vars, $clock, ServerService $servers)
        {
            parent::__construct($ssh, new App\Services\MysqlService($servers), new MariadbConfigParser(), new MariadbLogParser(), $clock);
        }

        protected function variables(Server $server): array
        {
            return $this->vars;
        }
    };
    $this->recent = fn (int $minutesAgo) => Carbon\Carbon::instance($this->clock->now())->setTimezone('-0700')->subMinutes($minutesAgo)->format('Y-m-d H:i:s');
});

test('log locations come from the option files the server reads, following includes; relative paths are under datadir', function () {
    $this->ssh->files = [
        '/etc/my.cnf' => "[client]\nport=3306\n!includedir /etc/my.cnf.d\n",
        '/etc/my.cnf.d/server.cnf' => "[mysqld]\ndatadir=/srv/db\nlog_error=mariadb.err\nslow_query_log=1\nslow_query_log_file=/logs/slow.log\n",
        '/srv/db/mariadb.err' => ($this->recent)(10) . " 0 [Warning] Aborted connection\n" . ($this->recent)(5) . " 0 [ERROR] mariadbd got signal 6 ;\nstack trace line\n",
        '/logs/slow.log' => "# Time: 260101 00:00:00\n# User@Host: app[app] @ localhost []\n# Query_time: 3.1  Lock_time: 0  Rows_sent: 1  Rows_examined: 5\nSET timestamp=" . ($this->clock->now()->getTimestamp() - 60) . ";\nSELECT 1;\n",
    ];
    $this->ssh->outputs['ls -1 -- '] = "zz-ignored.txt\nserver.cnf\n";

    $result = ($this->service)()->import($this->admin, $this->server);

    expect(implode("\n", $result['configuration']))->toContain('/etc/my.cnf, /etc/mysql/my.cnf')
        ->toContain('datadir: /srv/db (from /etc/my.cnf.d/server.cnf)')
        ->toContain('Error log: /srv/db/mariadb.err (log_error in /etc/my.cnf.d/server.cnf)')
        ->toContain('Slow query log: /logs/slow.log')
        ->and(array_column($result['sources'], 'imported'))->toBe([2, 1])
        ->and(MariadbLogEntry::query()->orderBy('logged_at')->pluck('level')->all())->toBe(['warning', 'crash', 'slow'])
        ->and(collect($this->ssh->commands)->contains(fn ($c) => str_contains($c, '/var/lib/mysql') || str_contains($c, '/var/log')))->toBeFalse();

    // Importing again adds nothing new.
    expect(array_column(($this->service)()->import($this->admin, $this->server)['sources'], 'imported'))->toBe([0, 0])
        ->and(MariadbLogEntry::count())->toBe(3)
        ->and($this->server->fresh()->log_imported_at)->not->toBeNull();
});

test('without log_error in the files, the journal is read by process name', function () {
    $this->ssh->files = ['/etc/mysql/my.cnf' => "[mysqld]\nbind-address=127.0.0.1\n"];
    $this->ssh->outputs['journalctl'] = "2026-09-29T14:03:01-0700 db mariadbd[811]: " . ($this->recent)(3) . " 0 [Warning] Something\n";
    $this->clock->time = strtotime('2026-09-29 21:10:00 UTC');

    $result = ($this->service)()->import($this->admin, $this->server);
    $journal = collect($this->ssh->commands)->first(fn ($c) => str_contains($c, 'journalctl'));

    expect($journal)->toContain('_COMM=mariadbd + _COMM=mysqld')->not->toContain('-u ')
        ->and($result['sources'][0]['where'])->toContain('systemd journal')
        ->and($result['sources'][0]['imported'])->toBe(1)
        ->and(implode("\n", $result['configuration']))->toContain('Slow query log: off');
});

test('without my_print_defaults, the documented option files are read; datadir can come from the running server', function () {
    unset($this->ssh->outputs['my_print_defaults --help']);
    $this->ssh->files = ['/etc/mysql/my.cnf' => "[mariadb]\nlog_error\n", '/var/db/db.err' => ''];

    $result = ($this->service)(['datadir' => '/var/db/', 'hostname' => 'db'])->import($this->admin, $this->server);

    expect(implode("\n", $result['configuration']))->toContain('/etc/my.cnf, /etc/mysql/my.cnf')
        ->toContain('datadir: /var/db/ (the running server)')
        // A bare log_error means <hostname>.err in datadir.
        ->toContain('Error log: /var/db/db.err');
});

test('an unreadable log is reported with a hint, and the others are still imported', function () {
    $this->ssh->files = [
        '/etc/my.cnf' => "[mysqld]\nlog_error=/logs/error.log\nslow_query_log=ON\nslow_query_log_file=/logs/slow.log\n",
        '/logs/error.log' => false,
        '/logs/slow.log' => "# User@Host: a[a] @ x []\n# Query_time: 2  Lock_time: 0  Rows_sent: 0  Rows_examined: 0\nSET timestamp=" . ($this->clock->now()->getTimestamp() - 60) . ";\nSELECT 2;\n",
    ];

    $result = ($this->service)()->import($this->admin, $this->server);

    expect($result['sources'][0]['problem'])->toContain('Permission denied')->toContain('read access')
        ->and($result['sources'][1]['imported'])->toBe(1);
});

test('only admins import, only from MariaDB servers with SSH set up, and not from Windows', function () {
    expect(fn () => ($this->service)()->import(new User(['role' => User::ROLE_USER]), $this->server))->toThrow(AuthorizationException::class);

    $this->ssh->platform = ServerPlatform::Windows;
    expect(fn () => ($this->service)()->import($this->admin, $this->server))->toThrow(DomainException::class, 'Linux');

    $this->server->ssh_host_key = null;
    expect(fn () => ($this->service)()->import($this->admin, $this->server))->toThrow(DomainException::class, 'SSH set up');
});

test('the same message twice in one second is kept twice, and still not again on re-import', function () {
    $line = ($this->recent)(5) . " 0 [Warning] Access denied for user 'x'@'localhost'\n";
    $this->ssh->files = ['/etc/my.cnf' => "[mysqld]\nlog_error=/logs/e.log\n", '/logs/e.log' => $line . $line];

    expect(($this->service)()->import($this->admin, $this->server)['sources'][0]['imported'])->toBe(2)
        ->and(($this->service)()->import($this->admin, $this->server)['sources'][0]['imported'])->toBe(0);
});
