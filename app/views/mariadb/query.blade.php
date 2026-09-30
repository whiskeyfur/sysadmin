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
        <p class="muted">Run one statement on several MariaDB/MySQL servers at once and see the results side by side, with the server each row came from. It logs in with your own database logins, kept in your private list below (only you see or use them; passwords are stored encrypted and never shown again), so the database's own privileges decide what you may do. Any statement is allowed, but one that isn't a plain read (SELECT, SHOW, DESCRIBE, EXPLAIN) has to be confirmed. Each statement may run {{ \App\Services\MariadbQueryService::TIMEOUT_SECONDS }} seconds on each server. When a server refuses one of your logins, that login isn't tried on the rest of the servers in that run.</p>
    </div>

    <div class="card" id="accounts">
        <h2>Your MariaDB accounts</h2>
        <p class="muted">Your own logins, private to you, each for the servers it works on. Before an account is saved (added or changed) its login is tried on every server chosen, and it's only kept if all of them accept it. Test checks it again, e.g. after a password change on the servers.</p>
        @if ($accounts)
            <div class="table-wrap">
            <table class="top">
                <thead><tr><th>Name</th><th>Username</th><th>Servers</th><th>Checked</th><th>Last used</th><th data-nosort></th></tr></thead>
                <tbody>
                    @foreach ($accounts as $account)
                        <tr>
                            <td><strong>{{ $account->label }}</strong></td>
                            <td><code>{{ $account->username }}</code></td>
                            <td>
                                @foreach ($servers as $server)
                                    @if ($account->isFor($server))
                                        @php($why = $account->refusedBy($server))
                                        <span class="badge {{ $why === null ? 'ok' : 'critical' }}" title="{{ $why ?? 'Works' }}">{{ $server->name }}</span>
                                    @endif
                                @endforeach
                            </td>
                            <td data-sort="{{ $account->tested_at?->getTimestamp() ?? 0 }}" class="muted">{{ \App\Utils\LocalTime::format($account->tested_at) ?: '—' }}</td>
                            <td data-sort="{{ $account->last_used_at?->getTimestamp() ?? 0 }}" class="muted">{{ \App\Utils\LocalTime::format($account->last_used_at) ?: 'never' }}</td>
                            <td class="row-actions">
                                <a href="/mariadb/query/accounts/{{ $account->id }}">Edit</a>
                                <form method="post" action="/mariadb/query/accounts/{{ $account->id }}/test">
                                    @csrf
                                    <button type="submit" class="link">Test</button>
                                </form>
                                <form method="post" action="/mariadb/query/accounts/{{ $account->id }}/delete" data-confirm="Delete {{ $account->label }} from your accounts?">
                                    @csrf
                                    <button type="submit" class="link danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="muted">None yet: add the database logins you use below.</p>
        @endif

        <details class="add-account" @if ($accountError || !$accounts) open @endif>
            <summary><strong>Add an account</strong></summary>
            @if ($accountError)
                <div class="alert error" role="alert">{{ $accountError }}</div>
            @endif
            @include('mariadb.account-fields', ['values' => $accountInput, 'chosen' => $accountInput['servers'] ?? [], 'action' => '/mariadb/query/accounts', 'button' => 'Check and save', 'passwordHint' => null, 'tested' => $tested])
        </details>
    </div>

    @php($choices = collect($servers)->mapWithKeys(fn ($server) => [$server->id => array_values(array_filter($accounts, fn ($a) => $a->isFor($server)))])->all())
    @if ($accounts || $auth->isAdmin())
        <form method="post" action="/mariadb/query" class="card" id="query-form">
            @csrf
            <h2>Query</h2>
            @if (count($servers) === 0)
                <p class="muted">No server has MariaDB/MySQL monitoring set up.</p>
            @else
                @php($plan = $input['plan'] ?? null)
                <fieldset class="query-servers">
                    <legend>Servers, and the login for each <button type="button" class="link" data-all-servers>all</button> · <button type="button" class="link" data-no-servers>none</button></legend>
                    <table class="query-plan">
                        @foreach ($servers as $server)
                            @php($own = $choices[$server->id])
                            @php($usable = $own !== [] || $auth->isAdmin())
                            @php($pick = $plan !== null ? ($plan[$server->id] ?? null) : null)
                            @php($default = collect($own)->first(fn ($a) => $a->refusedBy($server) === null)?->id ?? ($auth->isAdmin() && $own === [] ? 'stored' : null))
                            <tr @unless ($usable) class="muted" @endunless>
                                <td><label class="check"><input type="checkbox" name="servers[]" value="{{ $server->id }}" {{ !$usable ? 'disabled' : '' }} {{ $usable && ($plan !== null ? array_key_exists($server->id, $plan) : $default !== null) ? 'checked' : '' }}> {{ $server->name }}</label> <span class="muted">{{ $server->mysqlHost() }}:{{ $server->mysql_port }}</span></td>
                                <td>
                                    @if ($usable)
                                        <select name="account[{{ $server->id }}]" aria-label="Login for {{ $server->name }}">
                                            @foreach ($own as $account)
                                                @php($why = $account->refusedBy($server))
                                                <option value="{{ $account->id }}" {{ (string) ($pick ?? $default) === (string) $account->id ? 'selected' : '' }}>{{ $account->label }} ({{ $account->username }}){{ $why !== null ? ' — refused at its last check' : '' }}</option>
                                            @endforeach
                                            @if ($auth->isAdmin())
                                                <option value="stored" {{ ($pick ?? $default) === 'stored' ? 'selected' : '' }}>Server's stored account (admins)</option>
                                            @endif
                                        </select>
                                    @else
                                        <span class="muted">None of your accounts is for this server.</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
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
                <thead><tr><th>Server</th><th>Login</th><th>Outcome</th><th>Time</th></tr></thead>
                <tbody>
                    @foreach ($result['servers'] as $server)
                        <tr>
                            <td>{{ $server['name'] }}</td>
                            <td class="muted">{{ $server['login'] }}</td>
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
                            <td>@if (($accounts || $auth->isAdmin()) && !str_starts_with($query->statement, '(login'))<button type="button" class="link" data-reuse="{{ $query->statement }}">Use</button>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </details>
    @endif

    <style>
        .add-account { margin-top: 12px; }
        button.link.danger { border: 0; background: none; padding: 0; color: var(--error); }
        .add-account summary { cursor: pointer; }
        table.query-plan { width: 100%; border-collapse: collapse; }
        table.query-plan td { padding: 4px 8px 4px 0; vertical-align: middle; }
        table.query-plan select { max-width: 360px; }
        .query-servers { border: 1px solid var(--line); border-radius: 8px; padding: 10px 14px; margin: 0 0 12px; }
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
                form.querySelector('[data-all-servers]')?.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = !b.disabled; }); });
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
