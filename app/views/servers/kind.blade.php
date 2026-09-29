@extends('layouts.app')

@section('title', $title)
@section('width', 'wide')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>{{ $title }}</h1>
            @if ($auth->isAdmin())
                <a class="button" href="/admin/servers/new?kind={{ $kind }}&amp;back=/{{ $kind === 'ssh' ? 'ssh' : 'mariadb' }}">Add server</a>
            @endif
        </div>
        @if ($kind === 'ssh')
            <p class="muted">Disk, load and memory, read over one SSH login per run. Servers without SSH (e.g. reached with the database client only) are on <a href="/mariadb">MariaDB</a>.</p>
        @else
            <p class="muted">Database health, read with the MariaDB/MySQL client only: no SSH needed. Hover a result for details.</p>
        @endif

        @if (count($servers) === 0)
            <p class="muted">No server has {{ $title }} monitoring turned on.{{ $auth->isAdmin() ? ' Add one, or turn it on when editing a server.' : '' }}</p>
        @else
            <div class="table-wrap">
            <table class="top">
                <thead>
                    <tr>
                        <th>Server</th>
                        @foreach ($columns as $label)
                            <th>{{ $label }}</th>
                        @endforeach
                        <th>Last checked</th>
                        <th data-nosort><span class="muted">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        @php($row = $results[$server->id])
                        <tr>
                            <td>
                                <a href="/servers/{{ $server->id }}">{{ $server->name }}</a>
                                @if ($kind === 'ssh')
                                    {{-- Not "}}@{{": Blade treats @{{ as an escaped, literal {{. --}}
                                    <div class="hint"><code>{{ $server->ssh_username . '@' . $server->hostname . ':' . $server->ssh_port }}</code></div>
                                @else
                                    <div class="hint"><code>{{ $server->mysqlHost() . ':' . $server->mysql_port }}</code>
                                        @if ($server->mysql_tls === 'verify')
                                            <span class="badge ok">TLS</span>
                                        @elseif ($server->mysql_tls === 'encrypt')
                                            <span class="badge untested" title="Encrypted, certificate not checked">TLS, unverified</span>
                                        @else
                                            <span class="badge failed">Unencrypted</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            @if ($kind === 'ssh' && !$server->sshReady())
                                <td colspan="{{ count($columns) }}" class="muted">SSH isn't set up yet.@if ($auth->isAdmin()) <a href="/admin/servers/{{ $server->id }}/ssh-setup">Set it up</a>.@endif</td>
                            @elseif (isset($row[$connectionKey]))
                                <td colspan="{{ count($columns) }}"><span class="badge {{ $row[$connectionKey]->status->value }}">{{ $row[$connectionKey]->status->label() }}</span> {{ $row[$connectionKey]->summary }}</td>
                            @elseif ($row === [])
                                <td colspan="{{ count($columns) }}" class="muted">Not checked yet.</td>
                            @else
                                @foreach ($columns as $key => $label)
                                    <td data-sort="{{ isset($row[$key]) ? $row[$key]->status->severity() : '' }}">
                                        @if (isset($row[$key]))
                                            <span class="badge {{ $row[$key]->status->value }}" title="{{ $row[$key]->summary }}">{{ $row[$key]->status->label() }}</span>
                                            <div class="hint">{{ \Illuminate\Support\Str::limit($row[$key]->summary, 80) }}</div>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            @endif
                            <td class="muted">{{ \App\Utils\LocalTime::format($server->last_checked_at) }}</td>
                            <td class="row-actions">
                                @if ($kind === 'mysql' || $server->sshReady())
                                    <form method="post" action="/servers/{{ $server->id }}/checks">
                                        @csrf
                                        <input type="hidden" name="back" value="/{{ $kind === 'ssh' ? 'ssh' : 'mariadb' }}">
                                        <button type="submit">Check</button>
                                    </form>
                                @endif
                                <form method="post" action="/servers/{{ $server->id }}/test">
                                    @csrf
                                    <button type="submit" class="secondary">Test</button>
                                </form>
                                @if ($auth->isAdmin())
                                    <a class="button secondary-link" href="/admin/servers/{{ $server->id }}/edit?kind={{ $kind }}&amp;back=/{{ $kind === 'ssh' ? 'ssh' : 'mariadb' }}">Edit</a>
                                    <form method="post" action="/admin/servers/{{ $server->id }}/remove" data-confirm="Stop monitoring {{ $title }} on {{ $server->name }}? Its other monitoring stays; a server left with nothing to monitor is deleted.">
                                        @csrf
                                        <input type="hidden" name="kind" value="{{ $kind }}">
                                        <input type="hidden" name="back" value="/{{ $kind === 'ssh' ? 'ssh' : 'mariadb' }}">
                                        <button type="submit" class="danger">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    @if ($kind === 'mysql')
        <div class="card">
            <h2>Monitoring user</h2>
            <p>Create a user with only what the checks read; a check without its privilege reports <em>Unknown</em>:</p>
            <code class="pubkey">GRANT SELECT, PROCESS, SLAVE MONITOR ON *.* TO 'sys_monitor'@'this-host';</code>
            <p class="hint">SELECT lets the crashed-table check run CHECK TABLE; SLAVE MONITOR (MariaDB 10.5+; REPLICATION CLIENT on older versions and MySQL) lets it read replication status.</p>
        </div>
    @endif

    @if ($publicKey)
        <div class="card">
            <h2>The app's SSH key</h2>
            <p>The app logs in to every server with this key. On each server, add this line to the SSH user's <code>~/.ssh/authorized_keys</code> (on Windows: <code>C:\ProgramData\ssh\administrators_authorized_keys</code> for administrator accounts, otherwise <code>C:\Users\&lt;user&gt;\.ssh\authorized_keys</code>). Each server's SSH setup page shows the right place once it has detected the platform. The private key never leaves the app.</p>
            <code class="pubkey">{{ $publicKey }}</code>
        </div>
    @endif

    <script>
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); }
            });
        });
    </script>
@endsection
