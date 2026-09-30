@extends('layouts.app')

@section('title', 'Servers')

@section('content')
    <div class="card">
        <h1>Servers</h1>
        <p class="muted">Every server, with a button to each of its reports, coloured by how it's doing now:
            <span class="report-link ok">all OK</span> <span class="report-link warning">a warning</span> <span class="report-link critical">critical</span> <span class="report-link unknown">unknown or not checked yet</span>;
            — where that isn't set up. Hover a button for what isn't OK. Virtual hosts go by the monitored certificates that cover their names. The <a href="/">overview</a> has every check result side by side.</p>

        @if (count($rows) === 0)
            <p class="muted">No servers yet.@if ($auth->isAdmin()) Add one from <a href="/admin/ssh/new">SSH</a> or <a href="/admin/mariadb/new">MariaDB</a>.@endif</p>
        @else
            <div class="table-wrap">
            <table class="server-links">
                <thead><tr><th>Server</th><th>SSH</th><th>MariaDB</th><th>Apache</th><th>Vhosts</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($server = $row['server'])
                        <tr>
                            <td><a href="/servers/{{ $server->id }}"><strong>{{ $server->name }}</strong></a> <span class="muted">{{ $server->hostname }}</span></td>
                            @foreach ([['ssh', $server->ssh_enabled, '/ssh/reports', 'SSH'], ['mysql', $server->mysql_enabled, '/mariadb/reports', 'MariaDB'], ['apache', $server->apache_enabled, '/apache/reports', 'Apache']] as [$kind, $enabled, $href, $label])
                                @php($summary = $row['summary'][$kind] ?? null)
                                @php($status = $summary['status'] ?? null)
                                <td data-sort="{{ $enabled ? ($status?->severity() ?? -1) : -2 }}">
                                    @if ($enabled)
                                        <a class="report-link {{ $status?->value ?? 'unknown' }}" href="{{ $href }}?server={{ $server->id }}"
                                            title="{{ $status === null ? 'Not checked yet' : ($summary['issues'] === [] ? 'All ' . $summary['count'] . ' checks OK' : implode("\n", $summary['issues'])) }}">{{ $label }}</a>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td data-sort="{{ $row['vhosts'] > 0 ? ($row['vhost_status']?->severity() ?? -1) : -2 }}">
                                @if ($row['vhosts'] > 0)
                                    <a class="report-link {{ $row['vhost_status']?->value ?? 'unknown' }}" href="/vhosts?server={{ $server->id }}" title="{{ $row['vhost_note'] }}">{{ $row['vhosts'] }} {{ $row['vhosts'] === 1 ? 'vhost' : 'vhosts' }}</a>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <style>
        .report-link { display: inline-block; padding: 4px 14px; border-radius: 999px; font-weight: 600; font-size: 13px; text-decoration: none; border: 1px solid transparent; white-space: nowrap; }
        a.report-link:hover { filter: brightness(.95); text-decoration: underline; }
        .report-link.ok { background: var(--notice-bg); color: var(--notice); border-color: var(--notice); }
        .report-link.warning { background: var(--warn-bg); color: var(--warn); border-color: var(--warn); }
        .report-link.critical { background: var(--error-bg); color: var(--error); border-color: var(--error); }
        .report-link.unknown { background: var(--code-bg); color: var(--muted); border-color: var(--line); }
        p .report-link { padding: 1px 8px; font-size: 12px; }
        table.server-links td { vertical-align: middle; }
    </style>
@endsection
