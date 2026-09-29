@extends('layouts.app')

@section('title', 'Configure servers')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>Configure servers</h1>
            <a class="button" href="/admin/servers/new">Add server</a>
        </div>

        @if (count($servers) === 0)
            <p class="muted">No servers yet. A server can have any of SSH (disk, load, memory), MariaDB (database health) and SSL (certificates) monitoring.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Name</th><th>SSH</th><th>MariaDB</th><th>SSL</th><th>Connection test</th><th><span class="muted">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        <tr>
                            <td><a href="/servers/{{ $server->id }}">{{ $server->name }}</a></td>
                            {{-- Not "}}@{{": Blade treats @{{ as an escaped, literal {{. --}}
                            <td>
                                @if ($server->ssh_enabled)
                                    <code>{{ $server->ssh_username . '@' . $server->hostname . ':' . $server->ssh_port }}</code>
                                    @unless ($server->sshReady())
                                        <a class="badge untested" href="/admin/servers/{{ $server->id }}/ssh-setup">Set up</a>
                                    @endunless
                                @else
                                    <span class="muted">Off</span>
                                @endif
                            </td>
                            <td>
                                @if ($server->mysql_enabled)
                                    <code>{{ $server->mysqlHost() }}:{{ $server->mysql_port }}</code>
                                    @if ($server->mysql_tls === 'verify')
                                        <span class="badge ok">TLS</span>
                                    @elseif ($server->mysql_tls === 'encrypt')
                                        <span class="badge untested" title="Encrypted, certificate not checked">TLS, unverified</span>
                                    @else
                                        <span class="badge failed">Unencrypted</span>
                                    @endif
                                @else
                                    <span class="muted">Off</span>
                                @endif
                            </td>
                            <td>
                                @if ($server->ssl_enabled)
                                    {{ implode(', ', array_map(fn ($t) => $t['port'] === 443 ? $t['host'] : $t['host'] . ':' . $t['port'], array_slice($server->sslTargets(), 0, 3))) }}{{ count($server->sslTargets()) > 3 ? ' +' . (count($server->sslTargets()) - 3) : '' }}
                                @else
                                    <span class="muted">Off</span>
                                @endif
                            </td>
                            <td>
                                @if (!$server->ssh_enabled && !$server->mysql_enabled)
                                    <span class="muted">—</span>
                                @elseif ($server->last_tested_at === null)
                                    <span class="badge untested">Not tested</span>
                                @else
                                    <span class="badge {{ $server->last_test_ok ? 'ok' : 'failed' }}" title="{{ $server->last_test_message }}">{{ $server->last_test_ok ? 'OK' : 'Failed' }}</span>
                                    <span class="muted">{{ \App\Utils\LocalTime::format($server->last_tested_at) }}</span>
                                @endif
                            </td>
                            @if ($auth->isAdmin())
                                <td class="row-actions">
                                    @if ($server->ssh_enabled || $server->mysql_enabled)
                                        <form method="post" action="/admin/servers/{{ $server->id }}/test">
                                            @csrf
                                            <button type="submit">Test</button>
                                        </form>
                                    @endif
                                    <a class="button secondary-link" href="/admin/servers/{{ $server->id }}/edit">Edit</a>
                                    <form method="post" action="/admin/servers/{{ $server->id }}/delete" data-confirm="Delete {{ $server->name }}?">
                                        @csrf
                                        <button type="submit" class="danger">Delete</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    @if ($publicKey)
        <div class="card">
            <h2>The app's SSH key</h2>
            <p>The app logs in to every server with this key. On each server, add this line to the SSH user's <code>~/.ssh/authorized_keys</code>. The private key never leaves the app.</p>
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
