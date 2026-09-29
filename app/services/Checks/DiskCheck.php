<?php

namespace App\Services\Checks;

use App\DTOs\CheckResult;
use App\Enums\HealthStatus;

/**
 * Space and inode usage on every real filesystem (`df -P`, which works with
 * GNU and BusyBox). Pseudo filesystems, snaps and loop devices are skipped;
 * they're often always full and never a problem.
 */
class DiskCheck extends SshCheck
{
    public const WARNING_PERCENT = 85;

    public const CRITICAL_PERCENT = 95;

    private const INODES_MARKER = '--inodes--';

    private const SKIPPED_FILESYSTEMS = ['tmpfs', 'devtmpfs', 'udev', 'none', 'overlay', 'shm', 'efivarfs', 'cgroup', 'cgroup2', 'proc', 'sysfs', 'squashfs', 'rootfs'];

    private const SKIPPED_MOUNT_PREFIXES = ['/snap/', '/proc', '/sys', '/dev', '/run'];

    public function key(): string
    {
        return 'disk';
    }

    public function label(): string
    {
        return 'Disk space';
    }

    public function command(): string
    {
        return "df -P -k 2>/dev/null; echo '" . self::INODES_MARKER . "'; df -P -i 2>/dev/null";
    }

    public function evaluate(string $output): CheckResult
    {
        [$space, $inodes] = array_pad(explode(self::INODES_MARKER, $output, 2), 2, '');
        $mounts = $this->parse($space);

        if ($mounts === []) {
            return $this->result(HealthStatus::Unknown, "Couldn't read disk usage (df gave no usable output).");
        }

        $inodeUse = [];

        foreach ($this->parse($inodes) as $mount => $row) {
            if (isset($mounts[$mount])) {
                $inodeUse[$mount] = $row['percent'];
            }
        }

        $status = HealthStatus::Ok;
        $worst = 0.0;
        $problems = [];
        $details = [];

        foreach ($mounts as $mount => $row) {
            $inode = $inodeUse[$mount] ?? null;
            $details[$mount] = ['used_percent' => $row['percent'], 'free_kb' => $row['available'], 'inode_percent' => $inode];
            $worst = max($worst, $row['percent']);

            $diskStatus = self::threshold($row['percent'], self::WARNING_PERCENT, self::CRITICAL_PERCENT);
            $inodeStatus = $inode === null ? HealthStatus::Ok : self::threshold($inode, self::WARNING_PERCENT, self::CRITICAL_PERCENT);
            $status = HealthStatus::worst([$status, $diskStatus, $inodeStatus]);

            if ($diskStatus !== HealthStatus::Ok) {
                $problems[] = "$mount {$row['percent']}% full (" . self::bytes($row['available']) . ' free)';
            }

            if ($inodeStatus !== HealthStatus::Ok) {
                $problems[] = "$mount {$inode}% of inodes used";
            }
        }

        $summary = $problems === []
            ? 'All ' . count($mounts) . ' filesystems below ' . self::WARNING_PERCENT . "%; fullest is {$worst}%."
            : implode('; ', $problems) . '.';

        return $this->result($status, $summary, $worst, '%', ['mounts' => $details]);
    }

    /**
     * @return array<string, array{percent: float, available: float}> keyed by mount point
     */
    private function parse(string $df): array
    {
        $mounts = [];

        foreach (preg_split('/\R/', trim($df)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line), 6);

            if (!is_array($fields) || count($fields) < 6 || !is_numeric($fields[2]) || !is_numeric($fields[3])) {
                continue; // header, blank or unparsable line
            }

            [$filesystem, , $used, $available, , $mount] = $fields;
            $total = (float) $used + (float) $available;

            if ($total <= 0 || $this->skipped($filesystem, $mount)) {
                continue;
            }

            // Used / (used + available), like df's own Use%: excludes root-reserved blocks.
            $mounts[$mount] = ['percent' => round((float) $used / $total * 100, 1), 'available' => (float) $available];
        }

        return $mounts;
    }

    private function skipped(string $filesystem, string $mount): bool
    {
        if (in_array($filesystem, self::SKIPPED_FILESYSTEMS, true) || str_starts_with($filesystem, '/dev/loop')) {
            return true;
        }

        foreach (self::SKIPPED_MOUNT_PREFIXES as $prefix) {
            if ($mount === rtrim($prefix, '/') || str_starts_with($mount, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }
}
