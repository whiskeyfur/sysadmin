@extends('layouts.app')

@section('title', 'SSL certificates')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>SSL certificates</h1>
            @if ($auth->isAdmin())
                <form method="post" action="/ssl/check-all">
                    @csrf
                    <button type="submit">Check all now</button>
                </form>
            @endif
        </div>
        <p class="muted">Each certificate is checked on every server that serves it: the app connects to the server's own address and port and asks for the certificate's first hostname, then checks it like a browser would (trusted issuer, matching names, not expired). Invalid certificates are critical; valid ones are a warning once they expire within {{ $warningDays }} days or don't cover all their listed hostnames.
            @if ($auth->isAdmin())
                <a href="/admin/settings">Change the warning period</a>.
            @endif
        </p>

        @if (count($certificates) === 0)
            <p class="muted">No certificates yet.{{ $auth->isAdmin() ? ' Add one below.' : '' }}</p>
        @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Certificate</th><th>Status</th><th>Hostnames</th><th>Served on</th><th>Last checked</th></tr>
                </thead>
                <tbody>
                    @foreach ($certificates as $certificate)
                        <tr>
                            <td><a href="/ssl/{{ $certificate->id }}">{{ $certificate->name }}</a></td>
                            <td>
                                @if ($certificate->last_status)
                                    <span class="badge {{ $certificate->last_status }}">{{ ucfirst($certificate->last_status) }}</span>
                                @else
                                    <span class="muted">Not checked</span>
                                @endif
                            </td>
                            <td class="muted">{{ implode(', ', array_slice($certificate->hostnameList(), 0, 4)) }}{{ count($certificate->hostnameList()) > 4 ? ' +' . (count($certificate->hostnameList()) - 4) : '' }}</td>
                            <td class="row-actions">
                                @forelse ($certificate->bindings as $binding)
                                    <span class="badge {{ $binding->last_status ?? 'unknown' }}" title="{{ $binding->last_summary }}">{{ $binding->label() }}</span>
                                @empty
                                    <span class="muted">Nowhere yet</span>
                                @endforelse
                            </td>
                            <td class="muted">{{ \App\Utils\LocalTime::format($certificate->last_checked_at) ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    @if ($auth->isAdmin())
        <div class="card">
            <h2>Add a certificate</h2>
            <form method="post" action="/admin/ssl">
                @csrf
                <div class="grid-2">
                    <div>
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" placeholder="e.g. shop.example.com or Shop wildcard 2026" maxlength="100" required>
                    </div>
                    <div>
                        <label for="port">Port</label>
                        <input type="text" id="port" name="port" value="443" inputmode="numeric" required>
                    </div>
                </div>
                <label for="hostnames">Hostnames it covers</label>
                <textarea id="hostnames" name="hostnames" rows="3" spellcheck="false" placeholder="shop.example.com&#10;www.shop.example.com&#10;*.shop.example.com"></textarea>
                <p class="hint">One per line. The first is sent to the server to ask for this certificate (SNI), so it must be a real hostname, not a wildcard. Leave empty to use the name as the hostname. Either way, the other hostnames the served certificate lists (SAN) are added automatically.</p>
                <label for="server_id">Served on</label>
                <select id="server_id" name="server_id">
                    <option value="">Directly, via DNS (not on a tracked server)</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}">{{ $server->name }} ({{ $server->hostname }})</option>
                    @endforeach
                </select>
                <p class="hint">More servers and ports can be added on the certificate's page.</p>
                <div class="actions"><button type="submit">Add and check</button></div>
            </form>
        </div>
    @endif
@endsection
