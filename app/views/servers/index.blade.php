@extends('layouts.app')

@section('title', 'Servers')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>Servers</h1>
            @if ($auth->isAdmin())
                <a class="button" href="/admin/servers/new">Add server</a>
            @endif
        </div>

        @if (count($servers) === 0)
            <p class="muted">No servers yet.
                @if ($auth->isAdmin())
                    Add one to monitor it over SSH, with the MariaDB client, or both.
                @else
                    An admin can add them.
                @endif
            </p>
        @else
            <p class="muted">The latest result of each kind of monitoring. MariaDB and SSH are separate: a server can be monitored through its database only, over SSH only, or both.</p>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Server</th><th><a href="/mariadb">MariaDB</a></th><th><a href="/ssh">SSH</a></th><th><a href="/ssl">SSL</a></th><th>Last checked</th></tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        <tr>
                            <td><a href="/servers/{{ $server->id }}">{{ $server->name }}</a></td>
                            <td>@include('servers.summary', ['enabled' => $server->mysql_enabled, 'result' => $summaries[$server->id]['mysql'], 'pending' => 'Not checked yet'])</td>
                            <td>@include('servers.summary', ['enabled' => $server->ssh_enabled, 'result' => $summaries[$server->id]['ssh'], 'pending' => $server->sshReady() ? 'Not checked yet' : 'Not set up yet'])</td>
                            <td>
                                @if (($sslCounts[$server->id] ?? 0) > 0 && $server->last_ssl_status)
                                    <span class="badge {{ $server->last_ssl_status }}">{{ ucfirst($server->last_ssl_status) }}</span>
                                    <div class="hint">{{ $sslCounts[$server->id] }} certificate(s)</div>
                                @elseif (($sslCounts[$server->id] ?? 0) > 0)
                                    <span class="muted">Not checked yet</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="muted">{{ \App\Utils\LocalTime::format(collect([$server->last_checked_at, $server->last_ssl_checked_at])->filter()->max()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
@endsection
