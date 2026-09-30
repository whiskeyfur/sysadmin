@extends('layouts.app')

@section('title', 'Database users')
@section('width', 'wide')

@section('content')
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <h1>Database users</h1>
        <p class="muted">Create, change and drop MariaDB/MySQL accounts on several servers at once. It works through each server's monitoring account, so only on servers where that account may create users and grant privileges. Access is given per database as a preset: <strong>read only</strong> (SELECT), <strong>read/write</strong> (SELECT, INSERT, UPDATE, DELETE) or <strong>full</strong> (ALL). The monitoring account itself and system accounts (root and the like) are left alone. Every change asks for your password or a passkey and is logged; new accounts and passwords are kept in <a href="/admin/accounts/mariadb">Accounts</a>.</p>
    </div>

    <div class="card">
        <h2>Servers</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Server</th><th>Can manage accounts?</th></tr></thead>
            <tbody>
                @foreach ($servers as $server)
                    <tr>
                        <td>{{ $server->name }} <span class="muted">{{ $server->mysqlHost() }}:{{ $server->mysql_port }}</span></td>
                        <td data-sort="{{ $eligibility[$server->id]['ok'] ? 1 : 0 }}"><span class="badge {{ $eligibility[$server->id]['ok'] ? 'ok' : 'unknown' }}">{{ $eligibility[$server->id]['ok'] ? 'Yes' : 'No' }}</span> <span class="muted">{{ $eligibility[$server->id]['reason'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    @php($eligible = array_values(array_filter($servers, fn ($s) => $eligibility[$s->id]['ok'])))
    @if ($eligible === [])
        <div class="card">
            <p class="muted">No server's monitoring account can manage accounts yet. To allow it on a server, give that account CREATE USER (or ALL) on *.* WITH GRANT OPTION, e.g. <code>GRANT ALL PRIVILEGES ON *.* TO 'monitor'@'…' WITH GRANT OPTION;</code></p>
        </div>
    @else
        <div class="card">
            <h2>Accounts</h2>
            <p class="hint">Every account on the servers above (roles left out). Click one for its grants and to change it.</p>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Account</th>@foreach ($eligible as $server)<th>{{ $server->name }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($accounts as $name => $account)
                        <tr>
                            <td><a href="/mariadb/users/account?{{ http_build_query(['user' => $account['user'], 'host' => $account['host']]) }}"><code>{{ $name }}</code></a></td>
                            @foreach ($eligible as $server)
                                <td data-sort="{{ isset($account['on'][$server->id]) ? 1 : 0 }}">@if (isset($account['on'][$server->id]))<span class="badge ok">✓</span>@else<span class="muted">—</span>@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        <form method="post" action="/mariadb/users" class="card" id="create-account" data-confirm-dialog="Create this account on the servers ticked?">
            @csrf
            <h2>Create an account</h2>
            @include('mariadb.user-servers', ['servers' => $eligible, 'chosen' => $input['servers'] ?? [], 'legend' => 'On these servers'])
            <div class="field-row">
                <div>
                    <label for="new_username">Username</label>
                    <input type="text" id="new_username" name="db_username" value="{{ $input['username'] ?? '' }}" maxlength="80" spellcheck="false" autocomplete="off" required>
                </div>
                <div>
                    <label for="new_host">Host</label>
                    <input type="text" id="new_host" name="host" value="{{ $input['host'] ?? '%' }}" spellcheck="false" required>
                    <p class="hint">% for anywhere, localhost, an address or pattern (10.0.0.%), or a hostname.</p>
                </div>
            </div>
            @include('mariadb.user-password', ['id' => 'new_password'])
            <div class="field-row">
                <div>
                    <label for="new_database">Database</label>
                    <input type="text" id="new_database" name="database" value="{{ $input['database'] ?? '' }}" spellcheck="false" placeholder="a database, database.table, or * for all" required>
                </div>
                <div>
                    <label for="new_level">Access</label>
                    <select id="new_level" name="level">
                        @foreach (\App\Services\DbUserManagerService::LEVELS as $key => [$label, $privileges])
                            <option value="{{ $key }}" {{ ($input['level'] ?? 'read') === $key ? 'selected' : '' }}>{{ $label }} ({{ $privileges }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <label class="check"><input type="checkbox" name="track" value="1" {{ ($input['track'] ?? true) ? 'checked' : '' }}> Keep it and its password in Accounts</label>
            <div class="actions"><button type="submit">Create</button></div>
        </form>
    @endif

    @include('mariadb.user-changes', ['changes' => $changes])
    @include('mariadb.user-styles')
    @include('partials.confirm-dialog')
    <script src="{{ \App\Utils\Asset::url('/assets/js/passkeys.js') }}"></script>
@endsection
