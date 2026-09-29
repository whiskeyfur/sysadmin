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
                    SSH: {{ $server->sshReady() ? 'set up' : 'not set up' }}@if ($auth->isAdmin() && !$server->sshReady()) (<a href="/admin/servers/{{ $server->id }}/ssh-setup">set it up</a>)@endif ·
                    MySQL:
                    @if ($server->mysql_enabled)
                        <code>{{ $server->mysqlHost() . ':' . $server->mysql_port }}</code>
                    @else
                        not configured
                    @endif
                </p>
            </div>
            @if ($auth->isAdmin() && $canCheck)
                <form method="post" action="/admin/servers/{{ $server->id }}/checks">
                    @csrf
                    <button type="submit">Run checks now</button>
                </form>
            @endif
        </div>

        @if (!$canCheck && $server->last_checked_at === null)
            <p class="muted">Nothing to check yet: set up SSH (for disk, load and memory) or configure MySQL (for database health).</p>
        @elseif ($server->last_checked_at === null)
            <p class="muted">No health checks have run yet.{{ $auth->isAdmin() ? '' : ' An admin can run them.' }}</p>
        @else
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
