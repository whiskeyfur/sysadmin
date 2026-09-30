@extends('layouts.app')

@section('title', "'$user'@'$host'")
@section('width', 'wide')

@section('content')
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <h1><code>{{ "'$user'@'$host'" }}</code></h1>
        <p class="muted"><a href="/mariadb/users">All database users</a>. Changes are made on the servers you tick, each on its own. Removing a grant (its ✕), setting access and changing the password are queued and run together with one confirmation (your password or a passkey); dropping asks on its own.</p>
    </div>

    @foreach ($outcomes ?? [] as $outcome)
        <div class="card">
            <h2>{{ $outcome['what'] }}</h2>
            @if ($outcome['error'] !== null)
                <p class="alert error" role="alert">Not done: {{ $outcome['error'] }}</p>
            @endif
            <ul class="results">
                @foreach ($outcome['results'] as $result)
                    <li><span class="badge {{ $result['ok'] ? 'ok' : 'critical' }}">{{ $result['ok'] ? 'Done' : 'Failed' }}</span> <strong>{{ $result['server']->name }}</strong>: {{ $result['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endforeach

    @php($here = array_values(array_filter($servers, fn ($s) => $grants[$s->id] !== null)))
    <div class="card">
        <h2>Grants</h2>
        @if ($servers === [])
            <p class="muted">No server's monitoring account can manage accounts.</p>
        @endif
        <div class="table-wrap">
        <table class="top grants">
            <thead><tr><th>Server</th><th>Grants</th></tr></thead>
            <tbody>
                @foreach ($servers as $server)
                    <tr>
                        <td>{{ $server->name }}</td>
                        <td>
                            @if ($grants[$server->id] === null)
                                <span class="muted">Not on this server.</span>
                            @else
                                {{-- Each line's ✕ queues its removal (a + undoes it); queued new grants are added below in green. --}}
                                <div class="grant-lines" data-server="{{ $server->id }}" data-server-name="{{ $server->name }}">
                                    @foreach ($grants[$server->id] as $grant)
                                        @php($target = \App\Services\DbUserManagerService::grantTarget($grant))
                                        <div class="grant-line" @if ($target !== null) data-target="{{ $target }}" @endif>
                                            @if ($target !== null)
                                                <button type="button" class="grant-x" data-grant-toggle title="Remove this access" aria-label="Remove this access">✕</button>
                                            @else
                                                <span class="grant-x-space"></span>
                                            @endif
                                            {{-- Password hashes aren't shown. --}}
                                            <code>{{ preg_replace("/(IDENTIFIED (?:BY PASSWORD|VIA \\S+ USING)\\s+)'[^']*'/i", "\$1'…'", $grant) }}</code>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    @if ($here !== [])
        {{-- Changes below are queued here (script at the end) and run together after one confirmation. --}}
        <form method="post" action="/mariadb/users/account" class="card" id="change-queue" data-confirm-dialog="Run the queued changes?">
            @csrf
            <input type="hidden" name="user" value="{{ $user }}">
            <input type="hidden" name="host" value="{{ $host }}">
            <input type="hidden" name="action" value="queue">
            <h2>Queued changes</h2>
            <p class="muted" data-queue-empty>Nothing queued yet: remove access with a grant's ✕ above, add access or a new password with the forms below, then run them all here with one confirmation.</p>
            <ol class="queue" data-queue-list></ol>
            <div data-queue-inputs hidden></div>
            <div class="actions">
                <button type="submit" data-queue-run disabled>Execute</button>
                <button type="button" class="secondary" data-queue-clear disabled>Clear</button>
            </div>
        </form>
    @endif

    @if ($here !== [])
        @php($ids = array_map(fn ($s) => $s->id, $here))
        @foreach ([
            'grant' => ['Set access to a database', 'Set access', 'What it had on that database is replaced by the level chosen.'],
            'password' => ['Change the password', 'Change the password', null],
            'drop' => ['Drop the account', 'Drop', 'The account is removed from the servers ticked; its entry in Accounts goes too once no account of that name is left on a server.'],
        ] as $action => [$heading, $button, $about])
            <form method="post" action="/mariadb/users/account" class="card" id="action-{{ $action }}" @if ($action === 'drop') data-confirm-dialog="Drop {{ "'$user'@'$host'" }} on the servers ticked?" @else data-queue="{{ $action }}" @endif>
                @csrf
                <input type="hidden" name="user" value="{{ $user }}">
                <input type="hidden" name="host" value="{{ $host }}">
                <input type="hidden" name="action" value="{{ $action }}">
                <h2>{{ $heading }}</h2>
                @if ($about)
                    <p class="hint">{{ $about }}</p>
                @endif
                @include('mariadb.user-servers', ['servers' => $here, 'chosen' => $ids, 'legend' => 'On these servers'])
                @if ($action === 'grant')
                    <div class="field-row">
                        <div>
                            <label for="{{ $action }}_database">Database</label>
                            <input type="text" id="{{ $action }}_database" name="database" spellcheck="false" placeholder="a database, database.table, or * for all" required>
                        </div>
                        @if ($action === 'grant')
                            <div>
                                <label for="grant_level">Access</label>
                                <select id="grant_level" name="level">
                                    @foreach (\App\Services\DbUserManagerService::LEVELS as $key => [$label, $privileges])
                                        <option value="{{ $key }}">{{ $label }} ({{ $privileges }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                @elseif ($action === 'password')
                    @include('mariadb.user-password', ['id' => 'change_password'])
                    <label class="check"><input type="checkbox" name="track" value="1" checked> Keep the new password in Accounts</label>
                @endif
                <div class="actions"><button type="submit" @if ($action === 'drop') class="danger" @endif>{{ $action === 'drop' ? $button : 'Add to queue' }}</button></div>
            </form>
        @endforeach
    @endif

    @include('mariadb.user-changes', ['changes' => $changes])
    @include('mariadb.user-styles')
    @include('partials.confirm-dialog')
    <script src="{{ \App\Utils\Asset::url('/assets/js/passkeys.js') }}"></script>
    <style>
        ol.queue { margin: 0 0 8px; padding-left: 22px; }
        ol.queue li { padding: 4px 0; }
        ol.queue li button { margin-left: 8px; }
        .grant-line { display: flex; gap: 8px; align-items: flex-start; margin: 2px 0; }
        .grant-line code { flex: 1; margin: 0; }
        .grant-x, .grant-x-space { flex: none; width: 24px; height: 22px; }
        button.grant-x { padding: 0; border-radius: 4px; font-size: 13px; line-height: 20px; text-align: center; background: var(--error-bg); color: var(--error); border: 1px solid var(--error); }
        button.grant-x:hover { background: var(--error); color: var(--panel); }
        button.grant-x.undo { background: var(--notice-bg); color: var(--notice); border-color: var(--notice); font-size: 16px; font-weight: 700; }
        button.grant-x.undo:hover { background: var(--notice); color: var(--panel); }
        .grant-line.struck code { text-decoration: line-through; opacity: .6; }
        .grant-line.queued code { background: var(--notice-bg); color: var(--notice); }
    </style>
    <script>
        (function () {
            var queueForm = document.getElementById('change-queue');
            if (!queueForm) { return; }
            var list = queueForm.querySelector('[data-queue-list]'), inputs = queueForm.querySelector('[data-queue-inputs]');
            var empty = queueForm.querySelector('[data-queue-empty]'), run = queueForm.querySelector('[data-queue-run]'), clear = queueForm.querySelector('[data-queue-clear]');
            var account = @json('`' . str_replace('`', '``', $user) . '`@`' . str_replace('`', '``', $host) . '`');
            var privileges = @json(array_map(fn ($level) => $level[1], \App\Services\DbUserManagerService::LEVELS));
            var queue = [];

            // A database field's value as the grant lines' data-target has it: "*", "db" or "db.table".
            var norm = function (value) {
                value = value.trim();
                if (value === '*' || value === '*.*') { return '*'; }
                return value.slice(-2) === '.*' ? value.slice(0, -2) : value;
            };
            var shown = function (target) {
                if (target === '*') { return '*.*'; }
                var dot = target.indexOf('.');
                return dot < 0 ? '`' + target + '`.*' : '`' + target.slice(0, dot) + '`.`' + target.slice(dot + 1) + '`';
            };
            var hidden = function (name, value) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = name; input.value = value;
                inputs.appendChild(input);
            };
            var removal = function (server, target) {
                for (var i = 0; i < queue.length; i++) {
                    var f = queue[i].fields;
                    if (f.action === 'revoke' && f.servers.length === 1 && f.servers[0] === server && norm(f.database) === target) { return i; }
                }
                return -1;
            };

            // The grants box shows what the queue would do: removals struck through (a green + undoes them),
            // lines a queued grant replaces struck through, and the queued grants in green (✕ takes them out).
            var renderGrants = function () {
                document.querySelectorAll('.grant-lines').forEach(function (box) {
                    var server = box.getAttribute('data-server');
                    box.querySelectorAll('.grant-line.queued').forEach(function (line) { line.remove(); });
                    box.querySelectorAll('.grant-line[data-target]').forEach(function (line) {
                        var button = line.querySelector('[data-grant-toggle]');
                        var removed = removal(server, line.getAttribute('data-target')) >= 0;
                        var replaced = !removed && queue.some(function (c) { return c.fields.action === 'grant' && c.fields.servers.indexOf(server) >= 0 && norm(c.fields.database) === line.getAttribute('data-target'); });
                        line.classList.toggle('struck', removed || replaced);
                        button.style.visibility = replaced ? 'hidden' : '';
                        button.classList.toggle('undo', removed);
                        button.textContent = removed ? '+' : '✕';
                        button.title = removed ? 'Keep this access (take the removal out of the queue)' : 'Remove this access';
                        button.setAttribute('aria-label', button.title);
                    });
                    queue.forEach(function (change) {
                        if (change.fields.action !== 'grant' || change.fields.servers.indexOf(server) < 0) { return; }
                        var line = document.createElement('div'), button = document.createElement('button'), code = document.createElement('code');
                        line.className = 'grant-line queued';
                        button.type = 'button'; button.className = 'grant-x'; button.textContent = '✕';
                        button.title = 'Take this out of the queue'; button.setAttribute('aria-label', button.title);
                        button.addEventListener('click', function () {
                            // Only this server: the change stays queued for the others.
                            var at = change.fields.servers.indexOf(server);
                            change.fields.servers.splice(at, 1); change.names.splice(at, 1);
                            if (change.fields.servers.length === 0) { queue.splice(queue.indexOf(change), 1); }
                            render();
                        });
                        code.textContent = 'GRANT ' + privileges[change.fields.level] + ' ON ' + shown(norm(change.fields.database)) + ' TO ' + account;
                        line.appendChild(button); line.appendChild(code);
                        box.appendChild(line);
                    });
                });
            };

            var render = function () {
                list.textContent = ''; inputs.textContent = '';
                queue.forEach(function (change, i) {
                    var item = document.createElement('li');
                    item.textContent = change.label + ' — on ' + change.names.join(', ');
                    var remove = document.createElement('button');
                    remove.type = 'button'; remove.className = 'link'; remove.style.color = 'var(--error)'; remove.textContent = 'Remove';
                    remove.addEventListener('click', function () { queue.splice(i, 1); render(); });
                    item.appendChild(remove);
                    list.appendChild(item);
                    Object.keys(change.fields).forEach(function (key) {
                        [].concat(change.fields[key]).forEach(function (value) {
                            hidden('changes[' + i + '][' + key + ']' + (Array.isArray(change.fields[key]) ? '[]' : ''), value);
                        });
                    });
                });
                empty.hidden = queue.length > 0;
                run.disabled = clear.disabled = queue.length === 0;
                run.textContent = queue.length > 1 ? 'Execute all ' + queue.length : 'Execute';
                queueForm.setAttribute('data-confirm-dialog', 'Run ' + (queue.length === 1 ? 'this change' : 'these ' + queue.length + ' changes') + ' on ' + account.replace(/`/g, "'") + '?');
                renderGrants();
            };

            clear.addEventListener('click', function () { queue = []; render(); });

            // A grant line's ✕ queues removing that access on that server; its + (once queued) takes it out again.
            document.querySelectorAll('.grant-line[data-target] [data-grant-toggle]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var line = button.closest('.grant-line'), box = button.closest('.grant-lines');
                    var server = box.getAttribute('data-server'), target = line.getAttribute('data-target');
                    var at = removal(server, target);
                    if (at >= 0) {
                        queue.splice(at, 1);
                    } else {
                        queue.push({ label: 'Remove access on ' + (target === '*' ? 'everything (*.*)' : target), names: [box.getAttribute('data-server-name')], fields: { action: 'revoke', servers: [server], database: target } });
                    }
                    render();
                });
            });

            // The forms' own checks (required, minlength) have passed by the time they're submitted.
            document.querySelectorAll('form[data-queue]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    var action = form.getAttribute('data-queue');
                    var boxes = [].slice.call(form.querySelectorAll('input[name="servers[]"]:checked'));
                    if (boxes.length === 0) { window.alert('Tick at least one server.'); return; }
                    var fields = { action: action, servers: boxes.map(function (b) { return b.value; }) };
                    var names = boxes.map(function (b) { return b.parentNode.textContent.trim(); });
                    var database = form.querySelector('input[name=database]'), level = form.querySelector('select[name=level]');
                    var password = form.querySelector('input[name=db_password]'), track = form.querySelector('input[name=track]');
                    var label;
                    if (action === 'grant') {
                        fields.database = database.value.trim(); fields.level = level.value;
                        label = 'Set ' + level.options[level.selectedIndex].text.replace(/ \(.*\)$/, '').toLowerCase() + ' access on ' + fields.database;
                    } else {
                        fields.db_password = password.value; fields.track = track.checked ? '1' : '0';
                        label = 'Change the password' + (track.checked ? ' (kept in Accounts)' : '');
                    }
                    queue.push({ label: label, names: names, fields: fields });
                    if (database) { database.value = ''; }
                    if (password) { password.value = ''; password.type = 'password'; }
                    render();
                    queueForm.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                });
            });
            render();
        })();
    </script>
@endsection
