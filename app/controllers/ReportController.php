<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Services\ApacheReportService;
use App\Services\HistoryReport;
use App\Services\MariadbLogService;
use App\Services\MariadbReportService;
use App\Services\ServerService;
use App\Services\SettingsService;
use App\Services\SshReportService;
use App\Services\SslReportService;

/**
 * Reports for each monitoring area (the first item of its menu): each
 * charts its stored check values over time, with the data in a table.
 */
class ReportController extends Controller
{
    /**
     * ?server=<id>&range=24h|7d|30d|START-END, or &start=&end=
     */
    public function apache()
    {
        [$servers, $server, $range] = $this->pick(fn (Server $s) => $s->apache_enabled);

        $this->response->view('reports.apache', [
            'auth' => $this->authContext(),
            'servers' => $servers,
            'server' => $server,
            'range' => $range,
            'report' => $server === null ? null : (new ApacheReportService())->report($server, $range),
        ]);
    }

    /**
     * ?certificate=<id> (else all)&range=24h|7d|30d|START-END, or &start=&end=
     */
    public function ssl()
    {
        $certificates = SslCertificate::query()->get()->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        $certificate = collect($certificates)->firstWhere('id', (int) $this->request->get('certificate'));
        $range = $this->period();

        $this->response->view('reports.ssl', [
            'auth' => $this->authContext(),
            'certificates' => $certificates,
            'certificate' => $certificate,
            'range' => $range,
            'report' => (new SslReportService())->report($certificate, $range),
            'warningDays' => (new SettingsService())->sslWarningDays(),
        ]);
    }

    /**
     * ?server=<id>&range=24h|7d|30d|START-END, or &start=&end=
     */
    public function ssh()
    {
        [$servers, $server, $range] = $this->pick(fn (Server $s) => $s->ssh_enabled);

        $this->response->view('reports.ssh', [
            'auth' => $this->authContext(),
            'servers' => $servers,
            'server' => $server,
            'range' => $range,
            'report' => $server === null ? null : (new SshReportService())->report($server, $range),
        ]);
    }

    /**
     * ?server=<id>&range=24h|7d|30d|START-END, or &start=&end=
     */
    public function mariadb()
    {
        [$servers, $server, $range] = $this->pick(fn (Server $s) => $s->mysql_enabled);

        $this->response->view('reports.mariadb', [
            'auth' => $this->authContext(),
            'servers' => $servers,
            'server' => $server,
            'range' => $range,
            'report' => $server === null ? null : (new MariadbReportService())->report($server, $range),
            'canImport' => $server !== null && $this->authContext()->isAdmin() && (new MariadbLogService())->canImport($server),
            'notice' => $this->request->flash('notice'),
            'error' => $this->request->flash('error'),
            'import' => json_decode((string) $this->request->flash('log_import'), true),
        ]);
    }

    /**
     * The servers a report covers, the one picked (?server, else the first)
     * and the period (?range, else the default).
     *
     * @param callable(Server): bool $covered
     * @return array{0: list<Server>, 1: Server|null, 2: string}
     */
    private function pick(callable $covered): array
    {
        $servers = array_values(array_filter((new ServerService())->all(), $covered));
        $server = collect($servers)->firstWhere('id', (int) $this->request->get('server')) ?? ($servers[0] ?? null);

        return [$servers, $server, $this->period()];
    }

    /**
     * ?start=&end= (date fields), else ?range= (a preset or a zoomed-in "START-END").
     */
    private function period(): string
    {
        return HistoryReport::period((string) $this->request->get('range', false), (string) $this->request->get('start', false), (string) $this->request->get('end', false));
    }

    private function authContext(): AuthContext
    {
        return $this->request->next('auth');
    }
}
