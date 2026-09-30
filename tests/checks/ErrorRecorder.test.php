<?php

use App\Services\ErrorRecorder;
use Leaf\Crash\Report;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/sys-errors-' . bin2hex(random_bytes(4));
});

afterEach(function () {
    foreach (glob("{$this->dir}/*/*") ?: [] as $file) {
        unlink($file);
    }

    foreach (glob("{$this->dir}/*") ?: [] as $folder) {
        rmdir($folder);
    }

    @rmdir($this->dir);
});

test('an error is kept under the request\'s ID with the whole request, secrets masked', function () {
    // Built at run time: the report's stack frames quote source lines, and these must only be findable if leaked.
    [$pw, $dbpw, $code, $sess, $csrf, $tok] = array_map(fn ($s) => strrev($s), ['2retnuh2retnuh', 'ssap-bd-wen', '654321', 'noisses-terces', 'eulav-frsc', 'cba']);
    $server = [
        'UNIQUE_ID' => 'ar0zLFZCleqpxHfdvTkNswAAAAc', 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => "/mariadb/users/account?user=app&token=$tok",
        'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'curl/8', 'HTTP_COOKIE' => "PHPSESSID=$sess", 'HTTP_X_CSRF_TOKEN' => $csrf,
        'SERVER_NAME' => 'sys.localhost', 'SCRIPT_NAME' => '/index.php',
    ];
    $post = ['action' => 'queue', 'acct_password' => $pw, 'db_password' => $dbpw, 'code' => $code, 'X-Leaf-CSRF-Token' => $csrf, 'user' => 'app'];
    $recorder = new ErrorRecorder($this->dir, $server, ['user' => 'app', 'token' => $tok], $post);
    $folder = $recorder->write(Report::from(new RuntimeException('boom', 0, new LogicException('the cause'))));
    $json = json_decode((string) file_get_contents("$folder/report.json"), true);
    $text = file_get_contents("$folder/report.json") . file_get_contents("$folder/report.md");

    expect(basename($folder))->toBe('ar0zLFZCleqpxHfdvTkNswAAAAc')
        ->and($json['id'])->toBe('ar0zLFZCleqpxHfdvTkNswAAAAc')
        ->and($json['exception'])->toBe('RuntimeException')->and($json['message'])->toBe('boom')
        ->and(json_encode($json['chain'] ?: $json['previous']))->toContain('the cause')
        ->and($json['frames'])->not->toBeEmpty()
        ->and($json['request_details']['method'])->toBe('POST')
        ->and($json['request_details']['uri'])->toBe('/mariadb/users/account')
        ->and($json['request_details']['client'])->toBe('203.0.113.9')
        ->and($json['request_details']['form']['action'])->toBe('queue')->and($json['request_details']['form']['user'])->toBe('app')
        ->and($json['request_details']['headers']['User-Agent'])->toBe('curl/8')
        ->and($json['environment']['php'])->toBe(PHP_VERSION)
        ->and(file_get_contents("$folder/report.md"))->toContain('# Error ar0zLFZCleqpxHfdvTkNswAAAAc')->toContain('POST /mariadb/users/account from 203.0.113.9');

    // Nothing secret is written anywhere.
    foreach ([$pw, $dbpw, $code, $sess, $csrf, "token=$tok", "\"$tok\""] as $secret) {
        expect($text)->not->toContain($secret);
    }
});

test('without Apache\'s ID, one is made up once per request; a second error in it gets its own folder', function () {
    $id = ErrorRecorder::requestId([]);
    $recorder = new ErrorRecorder($this->dir, [], [], []);
    $first = $recorder->write(Report::from(new RuntimeException('one')));
    $second = $recorder->write(Report::from(new RuntimeException('two')));

    expect($id)->toMatch('/^sys-\d{8}-\d{6}-[0-9a-f]{12}$/')
        ->and(ErrorRecorder::requestId([]))->toBe($id)
        ->and(ErrorRecorder::requestId(['REDIRECT_UNIQUE_ID' => 'ar0zLFZCleqpxHfdvTkNswAAAAc']))->toBe('ar0zLFZCleqpxHfdvTkNswAAAAc')
        ->and(basename($first))->toBe($id)->and(basename($second))->toBe("$id-2");
});

test('reports older than the keep period are removed', function () {
    $recorder = new ErrorRecorder($this->dir, ['UNIQUE_ID' => 'oldoldoldoldoldold01'], [], []);
    $old = $recorder->write(Report::from(new RuntimeException('old')));
    touch($old, time() - (ErrorRecorder::KEEP_DAYS + 1) * 86400);
    $new = (new ErrorRecorder($this->dir, ['UNIQUE_ID' => 'newnewnewnewnewnew01'], [], []))->write(Report::from(new RuntimeException('new')));

    expect(is_dir($old))->toBeFalse()->and(is_dir($new))->toBeTrue();
});

test('a reporter never throws, even when it can\'t write', function () {
    (new ErrorRecorder('/proc/cannot-write-here', [], [], []))->report(Report::from(new RuntimeException('x')));
    expect(true)->toBeTrue();
});

test('secrets inside error text are masked: values given to secret-sounding columns, and password hashes', function (string $text, string $masked) {
    expect(ErrorRecorder::scrub($text))->toBe($masked);
})->with([
    ['update `users` set `password` = $argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA, `updated_at` = 2026-09-30', 'update `users` set `password` = ••••••••, `updated_at` = 2026-09-30'],
    ["where `totp_secret` = 'JBSWY3DP' and name = 'bob'", "where `totp_secret` = •••••••• and name = 'bob'"],
    ['api_key => sk-live-1, other => keep', 'api_key => ••••••••, other => keep'],
    ['Undefined array key "db_password"', 'Undefined array key "db_password"'],
    ['hash $2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234 end', 'hash •••••••• end'],
]);

test('the error page shows the request\'s ID', function () {
    $_SERVER['UNIQUE_ID'] = 'ar0zLFZCleqpxHfdvTkNswAAAAc';

    expect(ErrorRecorder::page())->toContain('recorded as ar0zLFZCleqpxHfdvTkNswAAAAc')->toContain('Something went wrong');
    unset($_SERVER['UNIQUE_ID']);
});
