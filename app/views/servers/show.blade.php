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
                    · Apache: {{ $server->apache_enabled ? ($server->apache_config['version'] ?? 'on') : 'off' }}
                    · SSL: {{ count($bindings) ? count($bindings) . ' certificate(s)' : 'none' }}
                </p>
            </div>
            <div class="row-actions">
                @if ($canCheck)
                    <form method="post" action="/servers/{{ $server->id }}/checks">
                        @csrf
                        <button type="submit">Run checks now</button>
                    </form>
                @endif
                @if ($server->ssh_enabled || $server->mysql_enabled)
                    <form method="post" action="/servers/{{ $server->id }}/test">
                        @csrf
                        <button type="submit" class="secondary">Test connection</button>
                    </form>
                @endif
                @if ($auth->isAdmin())
                    <a class="button secondary-link" href="/admin/servers/{{ $server->id }}/edit">Edit</a>
                    <form method="post" action="/admin/servers/{{ $server->id }}/delete" data-confirm="Delete {{ $server->name }} and its history?">
                        @csrf
                        <button type="submit" class="danger">Delete</button>
                    </form>
                @endif
            </div>
        </div>

        @if (!$canCheck && $server->last_checked_at === null)
            <p class="muted">Nothing to check yet: set up SSH (for disk, load and memory), MariaDB (for database health) or SSL.</p>
        @elseif ($server->last_checked_at === null && ($server->sshReady() || $server->mysql_enabled))
            <p class="muted">No health checks have run yet.</p>
        @elseif ($server->last_checked_at !== null)
            <p class="muted" style="margin-top: 16px">Last checked {{ \App\Utils\LocalTime::format($server->last_checked_at) }}. Checks run when an admin starts them; results are kept {{ \App\Services\HealthCheckService::RETENTION_DAYS }} days.</p>

            @foreach (['mysql' => 'MariaDB', 'ssh' => 'SSH: disk, load and memory', 'apache' => 'Apache'] as $kind => $heading)
            @continue($checks[$kind] === [] && !['mysql' => $server->mysql_enabled, 'ssh' => $server->ssh_enabled, 'apache' => $server->apache_enabled][$kind])
            <h2 style="margin-top: 16px">{{ $heading }}</h2>
            @if ($checks[$kind] === [])
                <p class="muted">{{ $kind !== 'mysql' && !$server->sshReady() ? "SSH isn't set up yet, so these checks don't run." : 'Not checked in the last run.' }}</p>
            @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Check</th><th>Status</th><th>Result</th><th data-nosort>Recent runs</th></tr>
                </thead>
                <tbody>
                    @foreach ($checks[$kind] as $check)
                        <tr>
                            <td>{{ $health->label($check->check_key) }}</td>
                            <td data-sort="{{ $check->status->severity() }}"><span class="badge {{ $check->status->value }}">{{ $check->status->label() }}</span></td>
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
            @endforeach
        @endif
    </div>

    @if (count($bindings))
    <div class="card">
        <h2>SSL certificates</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Certificate</th><th>Port</th><th>Status</th><th>Result</th><th data-nosort>Recent runs</th></tr>
            </thead>
            <tbody>
                @foreach ($bindings as $binding)
                    <tr>
                        <td><a href="/ssl/{{ $binding->certificate->id }}">{{ $binding->certificate->name }}</a><br><span class="hint">{{ $binding->certificate->primaryHostname() }}</span></td>
                        <td>{{ $binding->port }}</td>
                        <td data-sort="{{ \App\Enums\HealthStatus::tryFrom((string) $binding->last_status)?->severity() ?? '' }}">
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
        @include('servers.grants')
    </div>
    @endif

    <p><a href="/">Back to all servers</a></p>
    <script>
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); }
            });
        });
    </script>
@endsection
