@extends('layouts.app')

@section('title', 'MariaDB query')
@section('width', 'wide')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <h1>MariaDB query</h1>
        <p class="muted">Run one statement on several MariaDB/MySQL servers at once and see the results side by side, with the server each row came from. It logs in with your own database login, so the database's own privileges decide what you may do; any statement is allowed, but one that isn't a plain read (SELECT, SHOW, DESCRIBE, EXPLAIN) has to be confirmed. Each statement may run {{ \App\Services\MariadbQueryService::TIMEOUT_SECONDS }} seconds on each server. If the first server refuses your login, the others aren't tried.</p>
    </div>

    <div class="card">
        <h2>Database login</h2>
        @if ($login)
            <p>
                @if ($login['stored'])
                    Using <strong>each server's stored account</strong> (the one monitoring logs in with)
                @else
                    Logged in as <strong>{{ $login['username'] }}</strong>
                @endif
                since {{ \App\Utils\LocalTime::format(\Carbon\Carbon::createFromTimestamp($login['since'])) }}. It's kept, encrypted, in this session until you log out of it or of sys.
            </p>
            <form method="post" action="/mariadb/query/logout">
                @csrf
                <button type="submit" class="secondary">Log out of MariaDB</button>
            </form>
        @else
            <p class="muted">Your own database username and password: kept, encrypted, in this session only (never in sys's database), until you log out of MariaDB or of sys.</p>
            <form method="post" action="/mariadb/query/login" class="db-login">
                @csrf
                <label for="db_username">Username</label>
                <input type="text" id="db_username" name="username" autocomplete="off" spellcheck="false" required>
                <label for="db_password">Password</label>
                <input type="password" id="db_password" name="password" autocomplete="new-password">
                <div class="actions">
                    <button type="submit">Log in to MariaDB</button>
                </div>
            </form>
            @if ($auth->isAdmin())
                <form method="post" action="/mariadb/query/login">
                    @csrf
                    <input type="hidden" name="stored" value="1">
                    <p class="hint">Admins only: query with each server's stored account instead (the monitoring login, which is often far more privileged than a developer should have).</p>
                    <button type="submit" class="secondary">Use each server's stored account</button>
                </form>
            @endif
        @endif
    </div>

    @if ($login)
        <form method="post" action="/mariadb/query" class="card" id="query-form">
            @csrf
            <h2>Query</h2>
            @if (count($servers) === 0)
                <p class="muted">No server has MariaDB/MySQL monitoring set up.</p>
            @else
                @php($chosen = $input['servers'] ?? array_map(fn ($s) => $s->id, $servers))
                <fieldset class="query-servers">
                    <legend>Servers <button type="button" class="link" data-all-servers>all</button> · <button type="button" class="link" data-no-servers>none</button></legend>
                    @foreach ($servers as $server)
                        <label class="check"><input type="checkbox" name="servers[]" value="{{ $server->id }}" {{ in_array($server->id, $chosen, true) ? 'checked' : '' }}> {{ $server->name }} <span class="muted">{{ $server->mysqlHost() }}:{{ $server->mysql_port }}</span></label>
                    @endforeach
                </fieldset>
                <div class="query-options">
                    <div>
                        <label for="database">Database <span class="muted">(optional)</span></label>
                        <input type="text" id="database" name="database" value="{{ $input['database'] ?? '' }}" spellcheck="false" placeholder="e.g. shop">
                    </div>
                    <div>
                        <label for="limit">Rows per server, at most</label>
                        <input type="number" id="limit" name="limit" min="1" max="{{ \App\Services\MariadbQueryService::MAX_LIMIT }}" value="{{ $input['limit'] ?? \App\Services\MariadbQueryService::DEFAULT_LIMIT }}">
                    </div>
                </div>
                <label for="sql">Statement</label>
                <textarea id="sql" name="sql" rows="6" spellcheck="false" class="sql" required placeholder="SELECT @@hostname, @@version, NOW()">{{ $input['sql'] ?? '' }}</textarea>
                <label class="check query-confirm" data-write-confirm hidden><input type="checkbox" name="confirm" value="1"> This statement may change data: run it on every server ticked above.</label>
                <div class="actions">
                    <button type="submit">Run</button>
                </div>
            @endif
        </form>
    @endif

    @if ($result)
        <div class="card">
            <h2>Servers</h2>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Server</th><th>Outcome</th><th>Time</th></tr></thead>
                <tbody>
                    @foreach ($result['servers'] as $server)
                        <tr>
                            <td>{{ $server['name'] }}</td>
                            <td><span class="badge {{ $server['ok'] ? 'ok' : 'critical' }}">{{ $server['ok'] ? 'OK' : 'Failed' }}</span> {{ $server['message'] }}</td>
                            <td data-sort="{{ $server['ms'] }}">{{ $server['ms'] }} ms</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        @if ($result['columns'])
            <div class="card">
                <div class="actions" style="margin-top: 0; justify-content: space-between">
                    <h2>Results <span class="muted">({{ number_format(count($result['rows'])) }} {{ count($result['rows']) === 1 ? 'row' : 'rows' }})</span></h2>
                    <button type="button" class="secondary" data-csv="query-results">Download CSV</button>
                </div>
                <div class="table-wrap">
                <table class="top query-results" id="query-results">
                    <thead><tr><th>Server</th>@foreach ($result['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($result['rows'] as $row)
                            <tr>
                                <td style="white-space: nowrap">{{ $row['server'] }}</td>
                                @foreach ($result['columns'] as $column)
                                    @if (!array_key_exists($column, $row['values']))
                                        <td class="muted" data-absent></td>
                                    @elseif ($row['values'][$column] === null)
                                        <td class="muted" data-null>NULL</td>
                                    @else
                                        <td><code>{{ $row['values'][$column] }}</code></td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif
    @endif

    @if ($recent)
        <details class="card">
            <summary><h2 style="display: inline">Your recent queries</h2></summary>
            <div class="table-wrap">
            <table class="top">
                <thead><tr><th>When</th><th>As</th><th>Servers</th><th>Outcome</th><th>Statement</th><th data-nosort></th></tr></thead>
                <tbody>
                    @foreach ($recent as $query)
                        <tr>
                            <td data-sort="{{ $query->created_at->getTimestamp() }}" style="white-space: nowrap">{{ \App\Utils\LocalTime::format($query->created_at) }}</td>
                            <td>{{ $query->db_user }}</td>
                            <td>{{ count($query->servers) }}</td>
                            <td>{{ $query->outcome }}@if ($query->auth_failed) <span class="badge critical">login refused</span>@endif @if ($query->writes) <span class="badge warning">may write</span>@endif</td>
                            <td><code class="query-text">{{ \Illuminate\Support\Str::limit($query->statement, 300) }}</code></td>
                            <td>@if ($login)<button type="button" class="link" data-reuse="{{ $query->statement }}">Use</button>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </details>
    @endif

    <style>
        .db-login { max-width: 360px; }
        .query-servers { display: flex; flex-wrap: wrap; gap: 6px 18px; border: 1px solid var(--line); border-radius: 8px; padding: 10px 14px; margin: 0 0 12px; }
        .query-servers legend { font-weight: 600; padding: 0 6px; }
        .query-servers label.check, label.check { display: inline-flex; gap: 6px; align-items: center; font-weight: normal; margin: 0; }
        .query-options { display: flex; flex-wrap: wrap; gap: 0 16px; }
        .query-options > div { flex: 1 1 200px; }
        .query-options input { width: 100%; box-sizing: border-box; }
        textarea.sql { width: 100%; box-sizing: border-box; font-family: ui-monospace, monospace; font-size: 13px; }
        .query-confirm { margin-top: 8px; color: var(--warn); font-weight: 600; }
        table.query-results td code { background: none; padding: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
        code.query-text { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 12px; }
    </style>
    <script>
        (function () {
            var form = document.getElementById('query-form');
            if (form) {
                var boxes = form.querySelectorAll('input[name="servers[]"]');
                form.querySelector('[data-all-servers]')?.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = true; }); });
                form.querySelector('[data-no-servers]')?.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); });

                // A statement that isn't a plain read has to be confirmed (MariadbQueryService::isRead, checked again on the server).
                var sql = form.querySelector('#sql'), confirm = form.querySelector('[data-write-confirm]');
                var isRead = function (text) {
                    text = text.replace(/^(?:\s+|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\/\*[\s\S]*?\*\/)*/, '');
                    return /^(select|show|describe|desc|explain|with|values|table)\b/i.test(text);
                };
                var check = function () { confirm.hidden = sql.value.trim() === '' || isRead(sql.value); };
                sql?.addEventListener('input', check);
                if (sql) { check(); }
            }

            // Reuse a recent statement.
            document.querySelectorAll('[data-reuse]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var sql = document.getElementById('sql');
                    if (!sql) { return; }
                    sql.value = button.getAttribute('data-reuse');
                    sql.dispatchEvent(new Event('input'));
                    sql.scrollIntoView({ block: 'center' });
                    sql.focus();
                });
            });

            // The collated results as CSV (made here from the table: nothing is kept on the server).
            document.querySelectorAll('[data-csv]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var table = document.getElementById(button.getAttribute('data-csv'));
                    var quote = function (value) { return /[",\n\r]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value; };
                    var lines = [];
                    table.querySelectorAll('tr').forEach(function (tr) {
                        if (tr.hidden) { return; } // left out by the table filter
                        lines.push(Array.prototype.map.call(tr.children, function (cell) {
                            return cell.hasAttribute('data-null') ? 'NULL' : quote(cell.textContent.trim());
                        }).join(','));
                    });
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(new Blob([lines.join('\r\n') + '\r\n'], { type: 'text/csv' }));
                    link.download = 'mariadb-query.csv';
                    link.click();
                    setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
                });
            });
        })();
    </script>
@endsection
