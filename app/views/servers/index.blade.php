@extends('layouts.app')

@section('title', 'Servers')

@section('content')
    <div class="card">
        <h1>Servers</h1>

        @if (count($servers) === 0)
            <p class="muted">No servers yet.
                @if ($auth->isAdmin())
                    <a href="/admin/servers/new">Add one</a> under Configure.
                @else
                    An admin can add them.
                @endif
            </p>
        @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Server</th><th>Health</th><th>SSL</th><th>Monitoring</th><th>Last checked</th></tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        <tr>
                            <td><a href="/servers/{{ $server->id }}">{{ $server->name }}</a></td>
                            <td>
                                @if ($server->last_health_status)
                                    <span class="badge {{ $server->last_health_status }}">{{ ucfirst($server->last_health_status) }}</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if (($sslCounts[$server->id] ?? 0) > 0 && $server->last_ssl_status)
                                    <span class="badge {{ $server->last_ssl_status }}">{{ ucfirst($server->last_ssl_status) }}</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="muted">{{ implode(', ', array_filter([$server->ssh_enabled ? 'SSH' : null, $server->mysql_enabled ? 'MariaDB' : null, ($sslCounts[$server->id] ?? 0) > 0 ? 'SSL' : null])) }}</td>
                            <td class="muted">{{ \App\Utils\LocalTime::format(collect([$server->last_checked_at, $server->last_ssl_checked_at])->filter()->max()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
@endsection
