<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;
use PDO;
use Throwable;

/**
 * Disk space read through MariaDB, without SSH: the DISKS plugin's
 * information_schema.DISKS lists the server's filesystems like df. Judged
 * exactly like the SSH disk check (same skipped filesystems and levels).
 *
 * Optional: without the plugin, or without the FILE privilege (DISKS then
 * returns no rows), the check says so and stays OK, so a server isn't
 * left "unknown" for a source nobody set up. FILE also lets the user read
 * files on the server, so granting it is the admin's call.
 */
class DisksCheck extends MariaDbCheck
{
    public function __construct(private readonly DiskCheck $disk = new DiskCheck())
    {
    }

    public function key(): string
    {
        return 'disk_space';
    }

    public function label(): string
    {
        return 'Disk space';
    }

    public function run(PDO $pdo): CheckResult
    {
        try {
            $plugin = $pdo->query("SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME = 'DISKS'")?->fetchColumn();
        } catch (Throwable) {
            $plugin = false;
        }

        if ($plugin !== 'ACTIVE') {
            return $this->evaluate(null);
        }

        $rows = $pdo->query('SELECT Disk, Path, Total, Used, Available FROM information_schema.DISKS')?->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->evaluate($rows);
    }

    /**
     * @param list<array<string, mixed>>|null $rows information_schema.DISKS rows (sizes in KB); null when the plugin isn't installed
     */
    public function evaluate(?array $rows): CheckResult
    {
        if ($rows === null) {
            return $this->result(HealthStatus::Ok, "Not available: MariaDB's DISKS plugin isn't installed (INSTALL SONAME 'disks'). It shows disk space without SSH.");
        }

        if ($rows === []) {
            return $this->result(HealthStatus::Ok, "Not available: the monitoring user needs the FILE privilege to read disk space through MariaDB. FILE also lets it read files on the server; grant it only if that's acceptable.");
        }

        $mounts = [];

        foreach ($rows as $row) {
            $used = (float) ($row['Used'] ?? 0);
            $available = (float) ($row['Available'] ?? 0);
            $mount = (string) ($row['Path'] ?? '');

            if ($used + $available <= 0 || $mount === '' || $this->disk->skipped((string) ($row['Disk'] ?? ''), $mount)) {
                continue;
            }

            // Used / (used + available), like df's Use%.
            $mounts[$mount] = ['percent' => round($used / ($used + $available) * 100, 1), 'available' => $available];
        }

        if ($mounts === []) {
            return $this->result(HealthStatus::Ok, 'No real filesystems reported.');
        }

        $judged = $this->disk->evaluateMounts($mounts);

        return $this->result($judged->status, $judged->summary, $judged->value, $judged->unit, $judged->details);
    }
}
