<?php

use App\Services\ApacheConfigParser;
use App\Services\ApacheLogParser;

test('configuration: directives with their virtual host and location, quotes and continued lines', function () {
    $directives = (new ApacheConfigParser())->directives(<<<'CONF'
        # main server
        ServerRoot "/etc/httpd"
        ErrorLog logs/error_log
        LogFormat "%h %l %u %t \"%r\" %>s %b" common
        <Location "/server-status">
            SetHandler server-status
            Require local
        </Location>
        <VirtualHost *:443>
            ServerName shop.example.com
            CustomLog "/var/log/httpd/shop access.log" \
                combined
        </VirtualHost>
        CONF);

    $byName = fn (string $name) => array_values(array_filter($directives, fn ($d) => $d['name'] === $name));

    expect($byName('errorlog')[0])->toMatchArray(['args' => ['logs/error_log'], 'vhost' => null])
        ->and($byName('logformat')[0]['args'])->toBe(['%h %l %u %t "%r" %>s %b', 'common'])
        ->and($byName('sethandler')[0])->toMatchArray(['args' => ['server-status'], 'location' => '/server-status', 'vhost' => null])
        ->and($byName('customlog')[0])->toMatchArray(['args' => ['/var/log/httpd/shop access.log', 'combined'], 'vhost' => '*:443']);
});

test('configuration: DUMP_INCLUDES files and -S runtime values', function () {
    $parser = new ApacheConfigParser();
    $includes = "Included configuration files:\n  (*) /etc/apache2/apache2.conf\n    (146) /etc/apache2/mods-enabled/status.load\n      (35) /etc/letsencrypt/options ssl.conf\n";
    $runtime = "VirtualHost configuration:\nServerRoot: \"/etc/apache2\"\nMain DocumentRoot: \"/var/www/html\"\nMain ErrorLog: \"/var/log/apache2/error.log\"\n";

    expect($parser->includedFiles($includes))->toBe(['/etc/apache2/apache2.conf', '/etc/apache2/mods-enabled/status.load', '/etc/letsencrypt/options ssl.conf'])
        ->and($parser->runtime($runtime))->toBe(['server_root' => '/etc/apache2', 'main_error_log' => '/var/log/apache2/error.log']);
});

test('error log: 2.4 and 2.2 lines, levels, crashes, stack traces; info and debug dropped', function () {
    $log = "[Tue Sep 29 15:11:38.055668 2026] [authz_core:error] [pid 2633701] [client 104.1.1.1:30079] AH01630: client denied by server configuration: /var/www/html/x\n"
        . "[Tue Sep 29 15:11:58.115381 2026] [reqtimeout:info] [pid 2633699] [client 1.1.1.1:30078] AH01382: Request header read timeout\n"
        . "[Tue Sep 29 15:12:00.000001 2026] [core:notice] [pid 900:tid 1] AH00051: child pid 1234 exit signal Segmentation fault (11), possible coredump in /etc/apache2\n"
        . "[Tue Sep 29 15:13:00 2026] [warn] [client 1.2.3.4] mod_fcgid: stderr: PHP Warning: x\n"
        . "PHP Stack trace:\n"
        . "[Tue Sep  1 09:00:00.5 2026] [mpm_prefork:notice] [pid 1] AH00163: Apache/2.4.58 (Ubuntu) configured -- resuming normal operations\n";
    $entries = iterator_to_array((new ApacheLogParser())->errorEntries($log, new DateTimeZone('-0700')), false);

    expect(array_column($entries, 'level'))->toBe(['error', 'crash', 'warning', 'note'])
        ->and($entries[0]['message'])->toBe('AH01630: client denied by server configuration: /var/www/html/x')
        ->and($entries[0]['time']->toIso8601String())->toBe('2026-09-29T22:11:38+00:00')
        ->and($entries[2]['message'])->toBe("mod_fcgid: stderr: PHP Warning: x\nPHP Stack trace:")
        ->and($entries[3]['time']->toIso8601String())->toBe('2026-09-01T16:00:00+00:00');
});

test('access log: any format with the common core; other lines are counted as skipped', function () {
    $log = "127.0.0.1 - - [29/Sep/2026:15:12:01 -0700] \"GET / HTTP/1.1\" 200 5120 \"-\" \"curl\"\n"
        . "shop.example.com:443 10.0.0.1 - bob [29/Sep/2026:15:12:02 -0700] \"POST /x HTTP/1.1\" 503 - \"-\" \"-\"\n"
        . "104.129.198.215 - - [29/Sep/2026:15:11:58 -0700] \"-\" 408 3998 \"-\" \"-\"\n"
        . "a custom line without the usual fields\n";
    $skipped = 0;
    $lines = iterator_to_array((new ApacheLogParser())->accessLines($log, $skipped), false);

    expect(array_column($lines, 'status'))->toBe([200, 503, 408])
        ->and(array_column($lines, 'bytes'))->toBe([5120, 0, 3998])
        ->and($lines[0]['time'])->toBe(strtotime('2026-09-29 22:12:01 UTC'))
        ->and($skipped)->toBe(1);
});
