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
        <p class="muted">Each certificate is checked on every server that serves it: the app connects to the server's own address and port and asks for the certificate's first hostname, then checks it like a browser would (trusted issuer, matching names, not expired). Invalid certificates are critical; valid ones are a warning once they expire within {{ $warningDays }} days or don't cover all their listed hostnames. Every other name a certificate lists gets an entry of its own, checked directly through DNS, so a listed name that doesn't resolve or answer shows up here. A certificate that isn't OK opens to show why.
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
                        @php($problems = $certificate->bindings->filter(fn ($b) => $b->last_status !== null && $b->last_status !== 'ok'))
                        @php($foldable = $certificate->last_status !== null && $certificate->last_status !== 'ok' && $problems->isNotEmpty())
                        <tr @if ($foldable) class="foldable" @endif>
                            <td>
                                @if ($foldable)
                                    <button type="button" class="link fold" aria-expanded="false" aria-controls="condition-{{ $certificate->id }}" title="Show what's wrong"></button>
                                @endif
                                <a href="/ssl/{{ $certificate->id }}">{{ $certificate->name }}</a>
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
                        @if ($foldable)
                            <tr id="condition-{{ $certificate->id }}" class="fold-row" hidden>
                                <td colspan="5">
                                    <ul class="conditions">
                                        @foreach ($problems as $binding)
                                            <li><span class="badge {{ $binding->last_status }}">{{ ucfirst($binding->last_status) }}</span> <strong>{{ $binding->label() }}</strong>: {{ $binding->last_summary }}
                                                <span class="hint">checked {{ \App\Utils\LocalTime::format($binding->last_checked_at) }}</span></li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <style>
        tr.foldable > td:first-child { white-space: nowrap; }
        tr.foldable button.fold { text-decoration: none; color: var(--muted); padding-right: 2px; }
        tr.foldable { cursor: pointer; }
        ul.conditions { margin: 0; padding-left: 18px; }
        ul.conditions li { padding: 3px 0; overflow-wrap: anywhere; }
    </style>
    <script>
        // A certificate that isn't OK folds out into what its checks found (click the row or the arrow).
        document.querySelectorAll('tr.foldable').forEach(function (tr) {
            var button = tr.querySelector('button.fold');
            var row = document.getElementById(button.getAttribute('aria-controls'));
            var toggle = function () {
                var open = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                row.hidden = !open;
            };
            tr.addEventListener('click', function (event) {
                if (event.target.closest('a, form, button:not(.fold)')) { return; }
                toggle();
            });
        });
    </script>
@endsection
