<?php

namespace App\Services;

use App\Exceptions\ServerConnectionException;
use App\Models\Server;
use PDO;
use PDOException;

/**
 * PDO connections to a server's MariaDB/MySQL, over direct TCP, with the
 * server's TLS mode:
 *
 * - verify: TLS, certificate chain and hostname checked against the pasted
 *   CA or the system CAs.
 * - encrypt: TLS without checking the certificate. PDO only turns TLS on
 *   when a CA option is set, so the system bundle is passed with checking off.
 * - off: unencrypted.
 *
 * A server that can't do TLS fails to connect rather than silently falling
 * back, and every TLS connection is checked to really be encrypted.
 */
class MysqlService
{
    public const CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly ServerService $servers = new ServerService(),
        private readonly CaCertificateService $certificates = new CaCertificateService(),
    ) {
    }

    /**
     * @throws ServerConnectionException
     */
    public function connect(Server $server): PDO
    {
        if (!$server->mysql_enabled) {
            throw new ServerConnectionException('MySQL is not configured for this server.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $server->mysqlHost(), $server->mysql_port);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $caFile = null;

        try {
            if ($server->mysql_tls !== Server::TLS_OFF) {
                $verify = $server->mysql_tls === Server::TLS_VERIFY;

                if ($verify && $server->mysql_tls_ca !== null) {
                    // PDO needs a file path; the CA is public, and the file only lives for this connect.
                    $caFile = $this->writeTemporaryCa($server->mysql_tls_ca);
                }

                $options[$this->attribute('SSL_CA')] = $caFile ?? $this->certificates->systemBundle();
                $options[$this->attribute('SSL_VERIFY_SERVER_CERT')] = $verify;
            }

            $pdo = new PDO($dsn, (string) $server->mysql_username, (string) $this->servers->mysqlPassword($server), $options);
        } catch (PDOException $e) {
            throw new ServerConnectionException($this->explain($server, $e), 0, $e);
        } finally {
            if ($caFile !== null) {
                unlink($caFile);
            }
        }

        if ($server->mysql_tls !== Server::TLS_OFF && $this->tls($pdo) === null) {
            throw new ServerConnectionException('MySQL connected, but the session is not encrypted. Refusing to use it.');
        }

        return $pdo;
    }

    /**
     * The session's TLS version and cipher, or null when unencrypted.
     *
     * @return array{version: string, cipher: string}|null
     */
    public function tls(PDO $pdo): ?array
    {
        $status = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version', 'Ssl_cipher')")?->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        if (($status['Ssl_cipher'] ?? '') === '') {
            return null;
        }

        return ['version' => (string) $status['Ssl_version'], 'cipher' => (string) $status['Ssl_cipher']];
    }

    private function explain(Server $server, PDOException $e): string
    {
        $message = "MySQL connection to {$server->mysqlHost()}:{$server->mysql_port} failed: " . $e->getMessage();

        // PDO reports every TLS failure the same vague way; add the likely causes.
        if ($server->mysql_tls !== Server::TLS_OFF && preg_match('/using SSL|gone away/i', $e->getMessage()) === 1) {
            $message .= $server->mysql_tls === Server::TLS_VERIFY
                ? ". The server may not support TLS, or its certificate isn't signed by the trusted CA, or it isn't issued for \"{$server->mysqlHost()}\"."
                : '. The server may not support TLS.';
        }

        return $message;
    }

    private function writeTemporaryCa(string $pem): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sys-ca-');

        if ($path === false || file_put_contents($path, $pem) === false) {
            throw new ServerConnectionException("Couldn't write a temporary CA file.");
        }

        return $path;
    }

    /**
     * PHP 8.4 moved the MySQL PDO constants to Pdo\Mysql; use whichever exists.
     */
    private function attribute(string $name): int
    {
        return class_exists('Pdo\Mysql') && defined("Pdo\\Mysql::ATTR_$name")
            ? constant("Pdo\\Mysql::ATTR_$name")
            : constant("PDO::MYSQL_ATTR_$name");
    }
}
