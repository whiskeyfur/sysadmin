@extends('layouts.app')

@section('title', 'Apache on this server')
@section('width', 'wide')

@section('content')
    <div class="card">
        <h1>Apache on this server</h1>
        <p class="muted">This machine's own Apache (<code>/etc/apache2</code>), through the root helper. Every change is checked with <code>apache2ctl configtest</code> first and undone if Apache refuses it; files are backed up to <code>/var/backups/sys-apache</code>. Changes apply when Apache reloads. Everything done here is logged below.</p>

        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif
        @if ($error)
            <div class="alert error" role="alert"><pre class="log-message">{{ $error }}</pre></div>
        @endif
        @if ($output)
            <pre class="log-message">{{ $output }}</pre>
        @endif

        @unless ($status['ready'])
            <div class="alert error" role="alert">{{ $status['problem'] }}</div>
            <p class="hint">The helper is the only part of sys that runs as root; it does only the Apache actions on this page. Installing it adds one sudoers rule letting <code>www-data</code> run it (<code>/etc/sudoers.d/sys-apache-helper</code>).</p>
        @endunless
    </div>

    @if ($overview)
        <form method="post" action="/admin/apache/local/change" id="change">
            @csrf
            <div class="card">
                <h2>Confirm, then choose a change</h2>
                <p class="muted">Each change needs a fresh check with one of your sign-in methods (an authenticator code works once: wait for the next one between changes).</p>
                @include('partials.confirm-fields', ['formId' => 'change', 'what' => 'Confirm', 'passkeyTarget' => ''])
            </div>

            <div class="card">
                <div class="actions" style="margin-top: 0; justify-content: space-between">
                    <h2>{{ $overview['version'] ?? 'Apache' }} <span class="badge {{ ($overview['state'] ?? '') === 'active' ? 'ok' : 'critical' }}">{{ $overview['state'] ?? 'unknown' }}</span></h2>
                    <div class="row-actions">
                        <button type="submit" name="change" value="test" class="secondary" formnovalidate>Test configuration</button>
                        <button type="submit" name="change" value="reload" data-confirm="Reload Apache (graceful: open requests finish)?">Reload</button>
                        <button type="submit" name="change" value="restart" class="secondary danger" data-confirm="Restart Apache? Every open connection is dropped, including this page's.">Restart</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="actions" style="margin-top: 0; justify-content: space-between">
                    <h2>Sites</h2>
                    <div class="row-actions">
                        <a class="button" href="/admin/apache/local/new">New site</a>
                        <a class="button secondary-link" href="/admin/apache/local/edit?kind=main">Edit apache2.conf</a>
                        <a class="button secondary-link" href="/admin/apache/local/edit?kind=ports">Edit ports.conf</a>
                    </div>
                </div>
                @include('apache.local-items', ['items' => $overview['sites'] ?? [], 'kind' => 'site', 'editable' => true])
            </div>

            <div class="card">
                <h2>Configuration snippets</h2>
                @include('apache.local-items', ['items' => $overview['confs'] ?? [], 'kind' => 'conf', 'editable' => true])
            </div>

            <div class="card">
                <h2>Modules</h2>
                @include('apache.local-items', ['items' => $overview['mods'] ?? [], 'kind' => 'mod', 'editable' => false])
            </div>
        </form>

        <div class="card">
            <h2>Error log</h2>
            @if ($errorLog)
                <p class="hint">The last {{ count($errorLog) }} lines, newest first. Long lines are cut at 300 characters: click one for all of it.</p>
                <div class="log-box">
                    @foreach (array_reverse($errorLog) as $line)
                        @if (mb_strlen($line) > 300)
                            <details class="log-line"><summary><code>{{ mb_substr($line, 0, 300) }}…</code> <span class="muted">({{ number_format(mb_strlen($line)) }} characters)</span></summary><pre class="log-message">{{ $line }}</pre></details>
                        @else
                            <div class="log-line"><code>{{ $line }}</code></div>
                        @endif
                    @endforeach
                </div>
            @else
                <p class="muted">Empty.</p>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>Changes made here</h2>
        @if (count($changes) === 0)
            <p class="muted">None yet.</p>
        @else
            <div class="table-wrap">
            <table class="top">
                <thead><tr><th>When</th><th>Who</th><th>Change</th><th>Outcome</th></tr></thead>
                <tbody>
                    @foreach ($changes as $change)
                        <tr>
                            <td data-sort="{{ $change->created_at->getTimestamp() }}" style="white-space: nowrap">{{ \App\Utils\LocalTime::format($change->created_at, 'Y-m-d H:i:s') }}</td>
                            <td>{{ $change->user->username ?? '?' }}</td>
                            <td>{{ $change->action }} {{ $change->target }}</td>
                            <td><span class="badge {{ $change->ok ? 'ok' : 'critical' }}">{{ $change->ok ? 'Done' : 'Refused' }}</span>
                                @if ($change->output)
                                    <pre class="log-message">{{ \Illuminate\Support\Str::limit($change->output, 600) }}</pre>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <style>
        .log-box { max-height: 420px; overflow: auto; border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; }
        .log-line { padding: 3px 0; border-bottom: 1px solid var(--line); font-size: 12px; overflow-wrap: anywhere; }
        .log-line:last-child { border-bottom: 0; }
        .log-line code { background: none; padding: 0; white-space: pre-wrap; }
        .log-line summary { cursor: pointer; }
    </style>
    <script src="/assets/js/passkeys.js"></script>
@endsection
