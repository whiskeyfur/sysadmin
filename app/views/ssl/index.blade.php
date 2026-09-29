@extends('layouts.app')

@section('title', 'SSL certificates')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
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
        <p class="muted">Certificates are downloaded straight from each site over HTTPS and checked like a browser would: trusted issuer, matching name, not expired. Invalid certificates are critical; valid ones are a warning once they expire within {{ $warningDays }} days.
            @if ($auth->isAdmin())
                <a href="/admin/settings">Change the warning period</a>.
            @endif
        </p>

        @if (count($certificates) === 0)
            <p class="muted">No certificates checked yet.
                @if ($auth->isAdmin())
                    Turn on SSL monitoring for a server under <a href="/admin/servers">Configure</a>, then check.
                @endif
            </p>
        @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Site</th><th>Status</th><th>Expires</th><th>Result</th><th>Server</th></tr>
                </thead>
                <tbody>
                    @foreach ($certificates as $certificate)
                        <tr>
                            <td><a href="https://{{ $certificate->label() }}/" rel="noopener noreferrer" target="_blank">{{ $certificate->label() }}</a></td>
                            <td><span class="badge {{ $certificate->status->value }}">{{ $certificate->status->label() }}</span></td>
                            <td class="muted">{{ $certificate->details['valid_to'] ?? '—' }}</td>
                            <td>{{ $certificate->summary }}</td>
                            <td><a href="/servers/{{ $certificate->server_id }}">{{ $certificate->server->name }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
@endsection
