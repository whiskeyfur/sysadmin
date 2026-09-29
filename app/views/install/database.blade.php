@extends('layouts.app')

@section('title', 'Set up the database')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Set up the database</h1>
        <p class="muted">The app needs a database before anything else. Give an account on a MariaDB/MySQL or PostgreSQL server, or use an SQLite file.</p>
        <p class="muted">If the account may create databases and users (e.g. root, or <code>postgres</code>), the app creates the database and a user of its own that can only work with tables in it, with a generated password. It doesn't keep the account you type here. Otherwise it uses that account as it is, on a database that must already exist.</p>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="/install/database">
            @csrf
            <label for="install_code">Install code</label>
            <input type="text" id="install_code" name="install_code" autocomplete="off" autocapitalize="none" spellcheck="false" required autofocus>
            <p class="hint">Proves you run this server. It's in <code>{{ $codePath }}</code>; <code>php leaf app:install-code</code> prints it.</p>

            <fieldset>
                <legend>Database</legend>
                @php($chosen = $old['driver'] ?? collect($drivers)->filter(fn ($d) => $d['available'])->keys()->first())
                @foreach ($drivers as $driver => $info)
                    <label class="check">
                        <input type="radio" name="driver" value="{{ $driver }}" {{ $chosen === $driver ? 'checked' : '' }} {{ $info['available'] ? '' : 'disabled' }}>
                        {{ $info['label'] }}
                        @unless ($info['available'])
                            <span class="muted">(needs PHP's pdo_{{ $driver }} extension)</span>
                        @endunless
                    </label>
                @endforeach
            </fieldset>

            <fieldset id="server_fields">
                <legend>Server</legend>
                <label for="host">Host</label>
                <input type="text" id="host" name="host" value="{{ $old['host'] ?? 'localhost' }}" autocapitalize="none" spellcheck="false">
                <p class="hint"><code>localhost</code> uses the local socket for MariaDB/MySQL. For PostgreSQL's socket, give its directory (e.g. <code>/var/run/postgresql</code>).</p>
                <label for="port">Port</label>
                <input type="text" id="port" name="port" value="{{ $old['port'] ?? '' }}" inputmode="numeric" placeholder="{{ $ports['mysql'] }} (MariaDB/MySQL), {{ $ports['pgsql'] }} (PostgreSQL)">
                <label for="username">Account</label>
                <input type="text" id="username" name="username" value="{{ $old['username'] ?? '' }}" autocomplete="off" autocapitalize="none" spellcheck="false">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="off">
                <label for="database">Database name</label>
                <input type="text" id="database" name="database" value="{{ $old['database'] ?? 'sysadmin' }}" autocapitalize="none" spellcheck="false">
                <label for="app_username">The app's own user</label>
                <input type="text" id="app_username" name="app_username" value="{{ $old['app_username'] ?? 'sysadmin' }}" autocapitalize="none" spellcheck="false">
                <p class="hint">Created (or reset, with a new password and only these rights) when the account above may create users. Not used otherwise.</p>
            </fieldset>

            <fieldset id="sqlite_fields">
                <legend>SQLite</legend>
                <label for="sqlite_file">File</label>
                <input type="text" id="sqlite_file" name="sqlite_file" value="{{ $old['sqlite_file'] ?? $defaultSqlite }}" autocapitalize="none" spellcheck="false">
                <p class="hint">Created if missing. The web server must be able to write to its folder.</p>
            </fieldset>

            @if ($legacy)
                <fieldset>
                    <legend>Existing data</legend>
                    <label class="check"><input type="checkbox" name="copy_existing" value="1" checked> Copy the existing data</label>
                    <p class="hint">From <code>{{ $legacy }}</code>: accounts, servers, certificates, settings and history, with their ids. The old file is left as it is.</p>
                    <label class="check"><input type="checkbox" name="replace" value="1"> Replace rows already in the new database (when running this again)</label>
                </fieldset>
            @endif

            <p class="hint">The connection is saved in <code>{{ $configPath }}</code>, with the password encrypted by the app key.</p>

            <div class="actions">
                <button type="submit">Set up</button>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var radios = document.querySelectorAll('input[name="driver"]');
            function update() {
                var driver = (document.querySelector('input[name="driver"]:checked') || {}).value;
                document.getElementById('server_fields').hidden = driver === 'sqlite';
                document.getElementById('sqlite_fields').hidden = driver !== 'sqlite';
            }
            radios.forEach(function (r) { r.addEventListener('change', update); });
            update();
        })();
    </script>
@endsection
