@extends('layouts.app')

@section('title', $certificate->name)

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
                <h1>{{ $certificate->name }}</h1>
                <p class="muted">Covers {{ implode(', ', $certificate->hostnameList()) }}</p>
            </div>
            @if ($certificate->bindings->isNotEmpty())
                <form method="post" action="/ssl/{{ $certificate->id }}/check">
                    @csrf
                    <button type="submit">Check now</button>
                </form>
            @endif
            @if ($auth->isAdmin() && $certificate->bindings->isNotEmpty())
                <form method="post" action="/admin/ssl/{{ $certificate->id }}/names">
                    @csrf
                    <button type="submit" class="secondary">Get hostnames from the certificate</button>
                </form>
            @endif
        </div>

        <h2>Served on</h2>
        @if ($certificate->bindings->isEmpty())
            <p class="muted">Not attached to any server yet.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead><tr><th>Where</th><th>Status</th><th>Result</th><th data-nosort>Recent runs</th>@if ($auth->isAdmin())<th data-nosort></th>@endif</tr></thead>
                <tbody>
                    @foreach ($certificate->bindings as $binding)
                        <tr>
                            <td>
                                @if ($binding->server)
                                    <a href="/servers/{{ $binding->server->id }}">{{ $binding->server->name }}</a> <span class="muted">{{ $binding->server->hostname . ':' . $binding->port }}</span>
                                @else
                                    Direct <span class="muted">{{ $certificate->primaryHostname() . ':' . $binding->port }} via DNS</span>
                                @endif
                            </td>
                            <td data-sort="{{ \App\Enums\HealthStatus::tryFrom((string) $binding->last_status)?->severity() ?? '' }}">
                                @if ($binding->last_status)
                                    <span class="badge {{ $binding->last_status }}">{{ ucfirst($binding->last_status) }}</span>
                                @else
                                    <span class="muted">Not checked</span>
                                @endif
                            </td>
                            <td>
                                {{ $binding->last_summary }}
                                @if (!empty($binding->details['names']))
                                    <br><span class="hint">Certificate names: {{ implode(', ', array_slice($binding->details['names'], 0, 8)) }}{{ count($binding->details['names']) > 8 ? ' and ' . (count($binding->details['names']) - 8) . ' more' : '' }}.{{ !empty($binding->details['protocol']) ? ' ' . $binding->details['protocol'] . '.' : '' }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="history" aria-label="Recent runs, oldest first">
                                    @foreach ($ssl->history($binding) as $past)
                                        <span class="dot {{ $past->status->value }}" title="{{ \App\Utils\LocalTime::format($past->checked_at) }}: {{ $past->status->label() }}. {{ $past->summary }}"></span>
                                    @endforeach
                                </span>
                            </td>
                            @if ($auth->isAdmin())
                                <td>
                                    <form method="post" action="/admin/ssl/{{ $certificate->id }}/bindings/{{ $binding->id }}/delete" data-confirm="Stop checking this certificate on {{ $binding->label() }}?">
                                        @csrf
                                        <button type="submit" class="danger">Remove</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif

        @if ($auth->isAdmin())
            <h2 style="margin-top: 20px">Add a server</h2>
            <form method="post" action="/admin/ssl/{{ $certificate->id }}/bindings" class="inline-form">
                @csrf
                <div>
                    <label for="binding_server">Server</label>
                    <select id="binding_server" name="server_id">
                        <option value="">Directly, via DNS</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }} ({{ $server->hostname }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="binding_port">Port</label>
                    <input type="text" id="binding_port" name="port" value="443" inputmode="numeric" size="6" required>
                </div>
                <button type="submit">Add and check</button>
            </form>
        @endif
    </div>

    @if ($auth->isAdmin())
        <div class="card">
            <h2>Certificate details</h2>
            <form method="post" action="/admin/ssl/{{ $certificate->id }}">
                @csrf
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="{{ $certificate->name }}" maxlength="100" required>
                <label for="hostnames">Hostnames it covers</label>
                <textarea id="hostnames" name="hostnames" rows="4" spellcheck="false" required>{{ $certificate->hostnames }}</textarea>
                <p class="hint">One per line; the first is asked for by name (SNI) and can't be a wildcard.</p>
                <label for="notes">Notes <span class="muted">(optional)</span></label>
                <textarea id="notes" name="notes" rows="2">{{ $certificate->notes }}</textarea>
                <div class="actions"><button type="submit">Save</button></div>
            </form>
        </div>

        <form method="post" action="/admin/ssl/{{ $certificate->id }}/delete" data-confirm="Stop monitoring {{ $certificate->name }}? Its results are deleted.">
            @csrf
            <button type="submit" class="danger">Delete certificate</button>
            <a href="/ssl">Back to certificates</a>
        </form>
    @else
        <p><a href="/ssl">Back to certificates</a></p>
    @endif

    <script>
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); }
            });
        });
    </script>
@endsection
