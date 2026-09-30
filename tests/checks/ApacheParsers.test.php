<?php

use App\Services\ApacheConfigFiles;
use App\Services\ApacheConfigParser;
use App\Services\ApacheLogParser;
use App\Services\SshService;

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

test('includes: files, whole directories and wildcards in any path component, in Apache\'s order', function () {
    $files = new ApacheConfigFiles(new class () extends SshService {
        public function __construct()
        {
        }
    });

    expect($files->matches('/etc/httpd/conf.d/*.conf', ['/etc/httpd/conf.d/b.conf', '/etc/httpd/conf.d/a.conf', '/etc/httpd/conf.d/README', '/etc/httpd/conf.d/.hidden.conf', '/etc/httpd/conf.d/sub/c.conf']))
        ->toBe(['/etc/httpd/conf.d/a.conf', '/etc/httpd/conf.d/b.conf'])
        ->and($files->matches('/etc/apache2/sites-enabled', ['/etc/apache2/sites-enabled/z.conf', '/etc/apache2/sites-enabled/a/b.conf']))
        ->toBe(['/etc/apache2/sites-enabled/a/b.conf', '/etc/apache2/sites-enabled/z.conf'])
        ->and($files->matches('/srv/*/apache/*.conf', ['/srv/one/apache/x.conf', '/srv/two/other/y.conf', '/srv/two/apache/y.txt']))
        ->toBe(['/srv/one/apache/x.conf'])
        ->and($files->matches('/etc/httpd/vhosts.d/*', ['/etc/httpd/vhosts.d/site/a.conf']))
        ->toBe(['/etc/httpd/vhosts.d/site/a.conf']);
});

test('Debian envvars and ServerRoot, as read without the control program', function () {
    $files = new ApacheConfigFiles(new class () extends SshService {
        public function __construct()
        {
        }
    });
    $envvars = "unset HOME\nif [ \"\${APACHE_CONFDIR##/etc/apache2-}\" != \"\${APACHE_CONFDIR}\" ] ; then\n    SUFFIX=\"-\${APACHE_CONFDIR##/etc/apache2-}\"\nfi\nexport APACHE_RUN_USER=www-data\nexport APACHE_LOG_DIR=/var/log/apache2\$SUFFIX\nexport APACHE_LOCK_DIR=\"/var/lock/apache2\${SUFFIX}\" # lock\n";

    expect($files->exports($envvars))->toBe(['APACHE_RUN_USER' => 'www-data', 'APACHE_LOG_DIR' => '/var/log/apache2', 'APACHE_LOCK_DIR' => '/var/lock/apache2'])
        ->and($files->serverRoot("# ServerRoot \"/etc/apache2\"\nListen 80\n"))->toBeNull()
        ->and($files->serverRoot("ServerRoot \"/etc/httpd/\"\n"))->toBe('/etc/httpd');
});

test('access log formats: the fields each one logs, in its own order and time format', function (string $format, string $line, array $expected) {
    $entry = (new App\Services\AccessLogFormat($format))->parse($line);

    expect($entry)->not->toBeNull()
        ->and(array_intersect_key($entry, $expected))->toEqual($expected);
})->with([
    'common with a timed-out request' => ['%h %l %u %t "%r" %>s %b', '10.0.0.1 - - [29/Sep/2026:10:00:00 +0000] "-" 408 -', ['time' => 1790676000, 'status' => 408, 'bytes' => 0, 'path' => null]],
    'escapes in quoted fields' => ['%h %t "%r" %>s %O "%{User-Agent}i"', '::1 [29/Sep/2026:10:00:00 -0700] "GET /a\x22b HTTP/1.1" 200 5 "x \"y\" \\\\z"', ['client' => '::1', 'path' => '/a"b', 'agent' => 'x "y" \\z', 'time' => 1790701200]],
    'strftime time, path and query apart, microseconds taken' => ['%{%Y-%m-%d %H:%M:%S}t %a %m %U%q %>s %B %D %V', '2026-09-29 10:00:00 10.0.0.2 GET /x?y=1 500 1234 250000 shop.example.com', ['time' => 1790676000, 'method' => 'GET', 'path' => '/x?y=1', 'status' => 500, 'bytes' => 1234, 'duration_ms' => 250, 'vhost' => 'shop.example.com']],
    'epoch seconds and milliseconds taken' => ['%{sec}t|%h|%>s|%b|%{ms}T', '1790701200|10.0.0.3|201|99|42', ['time' => 1790701200, 'client' => '10.0.0.3', 'duration_ms' => 42]],
    'host and port logged apart' => ['%V %p %h %t "%r" %s %b', 'shop 8080 1.2.3.4 [29/Sep/2026:10:00:00 +0000] "GET / HTTP/1.1" 200 1', ['vhost' => 'shop:8080']],
]);

test('a line in another format doesn\'t parse', function () {
    expect((new App\Services\AccessLogFormat('%h %t "%r" %>s %b'))->parse('garbage line'))->toBeNull();
});

test('request IDs: read from the access log format or a trailing ID, and taken out of error messages', function () {
    $id = 'arya5LNEQ_6DuP3m_vCtTAAAAAE';
    $line = "198.51.100.4 - - [29/Sep/2026:22:15:16 -0700] \"GET /x HTTP/1.1\" 403 437 \"-\" \"Mozilla/5.0 (X)\" $id";
    $parser = new App\Services\ApacheLogParser();
    $fallback = iterator_to_array($parser->accessLines($line));
    $extra = iterator_to_array($parser->accessLines('1.2.3.4 - - [29/Sep/2026:22:15:16 -0700] "GET /y HTTP/1.1" 200 5 "-" "curl" 250000 more'));
    $errors = iterator_to_array($parser->errorEntries(
        "[Tue Sep 29 22:15:16.496724 2026] [authz_core:error] [pid 3] [client 127.0.0.1:5] AH01630: client denied: /x, referer: http://x/ [id $id]\n"
        . "[Tue Sep 29 22:15:17.100000 2026] [core:error] [pid 3] AH00000: no request here\n",
        new DateTimeZone('UTC'),
    ));

    expect((new App\Services\AccessLogFormat('%h %l %u %t "%r" %>s %O "%{Referer}i" "%{User-Agent}i" %{UNIQUE_ID}e'))->parse($line)['request_id'])->toBe($id)
        ->and((new App\Services\AccessLogFormat('%h %t "%r" %>s %b %L'))->parse('1.2.3.4 [29/Sep/2026:22:15:16 -0700] "GET / HTTP/1.1" 200 5 -')['request_id'])->toBeNull()
        ->and($fallback[0]['request_id'])->toBe($id)
        ->and($fallback[0]['agent'])->toBe('Mozilla/5.0 (X)')
        // Other fields after the user agent: still read, no ID made up.
        ->and($extra[0]['request_id'])->toBeNull()
        ->and($extra[0]['path'])->toBe('/y')
        ->and($errors[0]['request_id'])->toBe($id)
        ->and($errors[0]['message'])->toBe('AH01630: client denied: /x, referer: http://x/')
        ->and($errors[1]['request_id'])->toBeNull();
});
