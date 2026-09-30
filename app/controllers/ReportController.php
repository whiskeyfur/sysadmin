<?php

namespace App\Controllers;

use App\DTOs\AuthContext;
use App\Models\ApacheVhost;
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
     * One page of an Apache report's access or error log, as JSON {html (table rows), total, page, pages},
     * for the paged tables (public/assets/js/paged-table.js):
     * ?kind=access|errors|days&server=<id> or &vhost=<id>&range=&page=&q=&sort=&dir=asc|desc; for the access
     * log also &s2xx=..&s5xx=, &banned=, &protected=, &listed=, &local= (each in|out)&client=<start of address>
     */
    public function apacheEntries()
    {
        $vhost = ($vhostId = (int) $this->request->get('vhost')) > 0 ? ApacheVhost::query()->with('server')->find($vhostId) : null;
        $server = $vhost instanceof ApacheVhost ? $vhost->server : Server::query()->find((int) $this->request->get('server'));

        if (!$server instanceof Server || !$server->apache_enabled) {
            $this->response->json(['error' => 'That server or virtual host is no longer monitored.'], 404);

            return;
        }

        $vhost = $vhost instanceof ApacheVhost ? $vhost : null;
        $args = [
            $server,
            $this->period(),
            $vhost,
            max(1, (int) $this->request->get('page')),
            mb_substr((string) $this->request->get('q', false), 0, 200),
            (string) $this->request->get('sort', false),
            (string) $this->request->get('dir', false) === 'asc' ? 'asc' : 'desc',
        ];
        $reports = new ApacheReportService();

        if ($this->request->get('kind') === 'days') {
            $page = $reports->dailyPage(...$args);
            $this->response->json(['html' => $this->view('reports.day-rows', ['rows' => $page['rows']]), 'total' => $page['total'], 'page' => $page['page'], 'pages' => $page['pages']]);

            return;
        }

        $access = $this->request->get('kind') !== 'errors';
        // Tri-state filters: "in" (only), "out" (not), anything else: any.
        $state = fn (string $name) => in_array($v = (string) $this->request->get($name, false), ['in', 'out'], true) ? $v : '';
        $filters = [
            'statuses' => array_filter([2 => $state('s2xx'), 3 => $state('s3xx'), 4 => $state('s4xx'), 5 => $state('s5xx')]),
            'client' => mb_substr(trim((string) $this->request->get('client', false)), 0, 64),
            'banned' => $state('banned'),
            'protected' => $state('protected'),
            'listed' => $state('listed'),
            'local' => $state('local'),
        ];
        $page = $access ? $reports->accessPage(...$args, filters: $filters) : $reports->errorPage(...$args);
        $html = $access
            ? $this->view('reports.access-rows', ['rows' => $page['rows'], 'errors' => $page['errors'] ?? [], 'errorEntries' => $page['error_entries'] ?? [], 'listed' => (new \App\Services\BlocklistService())->listed(array_values(array_filter(array_map(fn ($r) => $r->client, $page['rows'])))), 'banServer' => $server, 'canBan' => $this->authContext()->isAdmin() && $server->sshReady(), 'protected' => \App\Models\Fail2banProtection::ipsFor($server)])
            : $this->view('reports.error-rows', ['rows' => $page['rows']]);

        $this->response->json(['html' => $html, 'total' => $page['total'], 'page' => $page['page'], 'pages' => $page['pages']]);
    }

    /**
     * One page of a MariaDB report's imported log, as JSON {html, total, page, pages}:
     * ?server=<id>&range=&page=&q=&sort=time|level|source&dir=asc|desc
     */
    public function mariadbEntries()
    {
        $server = Server::query()->find((int) $this->request->get('server'));

        if (!$server instanceof Server || !$server->mysql_enabled) {
            $this->response->json(['error' => 'That server is no longer monitored.'], 404);

            return;
        }

        $page = (new MariadbReportService())->logPage(
            $server,
            $this->period(),
            max(1, (int) $this->request->get('page')),
            mb_substr((string) $this->request->get('q', false), 0, 200),
            (string) $this->request->get('sort', false),
            (string) $this->request->get('dir', false) === 'asc' ? 'asc' : 'desc',
        );

        $this->response->json(['html' => $this->view('reports.mariadb-log-rows', ['rows' => $page['rows']]), 'total' => $page['total'], 'page' => $page['page'], 'pages' => $page['pages']]);
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
