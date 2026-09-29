@extends('layouts.app')

@section('title', $server->name)

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <div>
                <h1>{{ $server->name }}</h1>
                <p class="muted">
                    SSH: {{ !$server->ssh_enabled ? 'off' : ($server->sshReady() ? 'set up' : 'not set up') }}@if ($auth->isAdmin() && $server->ssh_enabled && !$server->sshReady()) (<a href="/admin/servers/{{ $server->id }}/ssh-setup">set it up</a>)@endif ·
                    MariaDB:
                    @if ($server->mysql_enabled)
                        <code>{{ $server->mysqlHost() . ':' . $server->mysql_port }}</code>
                    @else
                        off
                    @endif
                    · SSL: {{ count($bindings) ? count($bindings) . ' certificate(s)' : 'none' }}
                    @if ($auth->isAdmin()) · <a href="/admin/servers/{{ $server->id }}/edit">Configure</a>@endif
                </p>
            </div>
            @if ($auth->isAdmin() && $canCheck)
                <form method="post" action="/servers/{{ $server->id }}/checks">
                    @csrf
                    <button type="submit">Run checks now</button>
                </form>
            @endif
        </div>

        @if (!$canCheck && $server->last_checked_at === null)
            <p class="muted">Nothing to check yet: set up SSH (for disk, load and memory), MariaDB (for database health) or SSL.</p>
        @elseif ($server->last_checked_at === null && ($server->sshReady() || $server->mysql_enabled))
            <p class="muted">No health checks have run yet.{{ $auth->isAdmin() ? '' : ' An admin can run them.' }}</p>
        @elseif ($server->last_checked_at !== null)
            <h2 style="margin-top: 16px">Health</h2>
            <p class="muted">Last checked {{ \App\Utils\LocalTime::format($server->last_checked_at) }}. SSH checks use one login per run. Checks run when an admin starts them; results are kept {{ \App\Services\HealthCheckService::RETENTION_DAYS }} days.</p>

            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Check</th><th>Status</th><th>Result</th><th>Recent runs</th></tr>
                </thead>
                <tbody>
                    @foreach ($checks as $check)
                        <tr>
                            <td>{{ $health->label($check->check_key) }}</td>
                            <td><span class="badge {{ $check->status->value }}">{{ $check->status->label() }}</span></td>
                            <td>{{ $check->summary }}</td>
                            <td>
                                <span class="history" aria-label="Recent runs, oldest first">
                                    @foreach ($history[$check->check_key] ?? [] as $past)
                                        <span class="dot {{ $past->status->value }}" title="{{ \App\Utils\LocalTime::format($past->checked_at) }}: {{ $past->status->label() }}. {{ $past->summary }}"></span>
                                    @endforeach
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    @if (count($bindings))
    <div class="card">
        <h2>SSL certificates</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Certificate</th><th>Port</th><th>Status</th><th>Result</th><th>Recent runs</th></tr>
            </thead>
            <tbody>
                @foreach ($bindings as $binding)
                    <tr>
                        <td><a href="/ssl/{{ $binding->certificate->id }}">{{ $binding->certificate->name }}</a><br><span class="hint">{{ $binding->certificate->primaryHostname() }}</span></td>
                        <td>{{ $binding->port }}</td>
                        <td>
                            @if ($binding->last_status)
                                <span class="badge {{ $binding->last_status }}">{{ ucfirst($binding->last_status) }}</span>
                            @else
                                <span class="muted">Not checked</span>
                            @endif
                        </td>
                        <td>{{ $binding->last_summary }}</td>
                        <td>
                            <span class="history" aria-label="Recent runs, oldest first">
                                @foreach ($ssl->history($binding) as $past)
                                    <span class="dot {{ $past->status->value }}" title="{{ \App\Utils\LocalTime::format($past->checked_at) }}: {{ $past->status->label() }}. {{ $past->summary }}"></span>
                                @endforeach
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @endif

    @if ($server->mysql_enabled)
    <div class="card">
        <h2>Monitoring user</h2>
        <p>The checks need these privileges; a check without them reports <em>Unknown</em>:</p>
        <code class="pubkey">GRANT SELECT, PROCESS, SLAVE MONITOR ON *.* TO 'sys_monitor'@'this-host';</code>
        <p class="hint">SELECT lets the crashed-table check run CHECK TABLE; SLAVE MONITOR (MariaDB 10.5+; REPLICATION CLIENT on older versions and MySQL) lets it read replication status.</p>
    </div>
    @endif

    <p><a href="/servers">Back to servers</a></p>
@endsection
