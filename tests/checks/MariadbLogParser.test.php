<?php

use App\Services\MariadbConfigParser;
use App\Services\MariadbLogParser;

beforeEach(function () {
    $this->parser = new MariadbLogParser();
    $this->pacific = new DateTimeZone('-0700');
});

test('MariaDB 10.x error log lines, in the server\'s timezone, with levels', function () {
    $log = "2026-09-29 14:03:01 0 [Note] Starting MariaDB 10.11.14\n"
        . "2026-09-29 14:03:02 0 [Warning] 'user' entry 'root@x' ignored in --skip-name-resolve mode.\n"
        . "2026-09-29 14:03:03 12 [ERROR] Incorrect definition of table mysql.proc\n"
        . "2026-09-29 14:03:04 0 [Note] /usr/sbin/mariadbd: ready for connections.\n";
    $entries = $this->parser->parseErrorLog($log, $this->pacific);

    expect(array_column($entries, 'level'))->toBe(['note', 'warning', 'error', 'note'])
        ->and($entries[0]['time']->toIso8601String())->toBe('2026-09-29T21:03:01+00:00')
        ->and($entries[2]['message'])->toBe('Incorrect definition of table mysql.proc');
});

test('older and MySQL 8 formats', function () {
    $entries = $this->parser->parseErrorLog("250929 14:03:01 [Note] Plugin 'FEEDBACK' is disabled.\n2026-09-29T21:03:01.123456Z 0 [Warning] [MY-010068] [Server] CA certificate is self signed.", $this->pacific);

    expect($entries[0]['time']->toIso8601String())->toBe('2025-09-29T21:03:01+00:00')
        ->and($entries[1]['level'])->toBe('warning')
        ->and($entries[1]['message'])->toBe('CA certificate is self signed.')
        ->and($entries[1]['time']->toIso8601String())->toBe('2026-09-29T21:03:01+00:00');
});

test('a crash is its own level and keeps the stack trace that follows', function () {
    $log = "2026-09-29 14:03:01 0 [ERROR] mariadbd got signal 11 ;\n"
        . "Sorry, we probably made a mistake, and this is a bug.\n"
        . "stack_bottom = 0x0 thread_stack 0x49000\n"
        . "2026-09-29 14:03:09 0 [Note] Starting MariaDB 10.11.14\n";
    $entries = $this->parser->parseErrorLog($log, $this->pacific);

    expect(count($entries))->toBe(2)
        ->and($entries[0]['level'])->toBe('crash')
        ->and($entries[0]['message'])->toContain('got signal 11')->toContain('stack_bottom');
});

test('journal lines use the journal\'s own time and the wrapped message\'s level', function () {
    $log = "-- Boot 3b1c --\n"
        . "2026-09-29T14:03:01-0700 web mariadbd[811]: 2026-09-29 14:03:01 0 [Warning] Aborted connection 5 to db: 'app'\n"
        . "2026-09-29T14:05:00-07:00 web mariadbd[811]: Some plain message\n";
    $entries = $this->parser->parseErrorLog($log, new DateTimeZone('UTC'));

    expect(count($entries))->toBe(2)
        ->and($entries[0]['level'])->toBe('warning')
        ->and($entries[0]['message'])->toBe("Aborted connection 5 to db: 'app'")
        ->and($entries[0]['time']->toIso8601String())->toBe('2026-09-29T21:03:01+00:00')
        ->and($entries[1]['level'])->toBe('note');
});

test('slow query log entries, one per query, at the time it ran', function () {
    $log = "/usr/sbin/mariadbd, Version: 10.11.14 (Ubuntu). started with:\nTcp port: 3306  Unix socket: /run/mysqld/mysqld.sock\nTime                Id Command  Argument\n"
        . "# Time: 260929 14:03:01\n# User@Host: app[app] @ localhost []\n# Thread_id: 8  Schema: shop  QC_hit: No\n# Query_time: 2.000146  Lock_time: 0.000010  Rows_sent: 1  Rows_examined: 120000\nuse shop;\nSET timestamp=1790700181;\nSELECT * FROM orders\nWHERE total > 100;\n"
        . "# User@Host: report[report] @ [10.0.0.5]\n# Query_time: 5.5  Lock_time: 0  Rows_sent: 10  Rows_examined: 99\nSELECT SLEEP(5.5);\n";
    $entries = $this->parser->parseSlowLog($log, $this->pacific);

    expect(count($entries))->toBe(2)
        ->and($entries[0]['level'])->toBe('slow')
        ->and($entries[0]['time']->getTimestamp())->toBe(1790700181)
        ->and($entries[0]['message'])->toBe("took 2 s, 120000 rows examined, 1 sent, app[app] on shop: SELECT * FROM orders\nWHERE total > 100;")
        // No SET timestamp: the "# Time:" above, in the server's timezone.
        ->and($entries[1]['time']->toIso8601String())->toBe('2026-09-29T21:03:01+00:00')
        ->and($entries[1]['message'])->toStartWith('took 5.5 s');
});

test('option files: server sections, normalised names, bare options, quotes, comments and includes', function () {
    $parsed = (new MariadbConfigParser())->parse(<<<'CNF'
        # The MariaDB configuration
        [client]
        port = 3306
        [mysqld]
        log-error = /var/log/mysql/error.log   # the error log
        slow_query_log
        slow-query-log-file = "/var/log/mysql/slow file.log"
        loose-datadir=/srv/mysql
        [mariadb-10.11]
        log_output = FILE
        !include /etc/mysql/extra.cnf
        !includedir /etc/mysql/conf.d/
        CNF);
    $config = new MariadbConfigParser();
    $server = array_values(array_filter($parsed['options'], fn ($o) => $config->isServerSection($o['section'])));

    expect(array_column($server, 'value', 'name'))->toBe([
        'log_error' => '/var/log/mysql/error.log',
        'slow_query_log' => null,
        'slow_query_log_file' => '/var/log/mysql/slow file.log',
        'datadir' => '/srv/mysql',
        'log_output' => 'FILE',
    ])
        ->and($parsed['includes'])->toBe([['type' => 'file', 'path' => '/etc/mysql/extra.cnf'], ['type' => 'dir', 'path' => '/etc/mysql/conf.d/']])
        ->and($config->isServerSection('client'))->toBeFalse();
});
