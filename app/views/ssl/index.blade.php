@extends('layouts.app')

@section('width', 'wide')
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
            <div class="row-actions">
                <form method="post" action="/ssl/check-all">
                    @csrf
                    <button type="submit">Check all now</button>
                </form>
                @if ($auth->isAdmin())
                    <a class="button" href="/admin/ssl/new">Add certificate</a>
                @endif
            </div>
        </div>
        <p class="muted">Each certificate is checked on every server that serves it: the app connects to the server's own address and port and asks for the certificate's first hostname, then checks it like a browser would (trusted issuer, matching names, not expired). Invalid certificates are critical; valid ones are a warning once they expire within {{ $warningDays }} days or don't cover all their listed hostnames.
            @if ($auth->isAdmin())
                <a href="/admin/settings/ssl">Change the warning period</a>.
            @endif
        </p>

        @if (count($certificates) === 0)
            <p class="muted">No certificates yet.@if ($auth->isAdmin()) <a href="/admin/ssl/new">Add one</a>.@endif</p>
        @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Certificate</th><th>Status</th><th>Expires</th><th>Served on</th><th>Last checked</th></tr>
                </thead>
                <tbody>
                    @foreach ($certificates as $certificate)
                        @php($hostnames = $certificate->hostnameList())
                        <tr>
                            <td>
                                <a href="/ssl/{{ $certificate->id }}">{{ $certificate->name }}</a>
                                <div><button type="button" class="link fold" aria-expanded="false" aria-controls="hostnames-{{ $certificate->id }}">{{ count($hostnames) }} {{ count($hostnames) === 1 ? 'hostname' : 'hostnames' }}</button></div>
                            </td>
                            <td data-sort="{{ \App\Enums\HealthStatus::tryFrom((string) $certificate->last_status)?->severity() ?? -1 }}">
                                @if ($certificate->last_status)
                                    <span class="badge {{ $certificate->last_status }}">{{ ucfirst($certificate->last_status) }}</span>
                                @else
                                    <span class="muted">Not checked</span>
                                @endif
                            </td>
                            @php($expires = $certificate->expiresAt())
                            <td data-sort="{{ $expires?->getTimestamp() ?? '' }}">
                                @if ($expires)
                                    {{ \App\Utils\LocalTime::format($expires, 'Y-m-d') }}
                                    @php($days = (int) floor(\Carbon\Carbon::now()->diffInDays($expires, false)))
                                    <div class="hint">{{ $days < 0 ? 'expired ' . abs($days) . (abs($days) === 1 ? ' day' : ' days') . ' ago' : ($days === 0 ? 'today' : 'in ' . $days . ($days === 1 ? ' day' : ' days')) }}</div>
                                @else
                                    <span class="muted">Not checked</span>
                                @endif
                            </td>
                            <td class="row-actions">
                                @forelse ($certificate->bindings as $binding)
                                    <span class="badge {{ $binding->last_status ?? 'unknown' }}" title="{{ $binding->last_summary }}">{{ $binding->label() }}</span>
                                @empty
                                    <span class="muted">Nowhere yet</span>
                                @endforelse
                            </td>
                            <td class="muted">{{ \App\Utils\LocalTime::format($certificate->last_checked_at) ?: '—' }}</td>
                        </tr>
                        <tr id="hostnames-{{ $certificate->id }}" class="fold-row" hidden>
                            <td colspan="5">
                                <ul class="hostnames">
                                    @foreach ($hostnames as $hostname)
                                        <li><code>{{ $hostname }}</code>@if ($loop->first) <span class="hint">sent as SNI</span>@endif</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <script>
        // Hostnames fold out in a row under their certificate.
        document.querySelectorAll('button.fold').forEach(function (button) {
            var row = document.getElementById(button.getAttribute('aria-controls'));
            button.addEventListener('click', function () {
                var open = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                row.hidden = !open;
            });
        });
    </script>
@endsection
