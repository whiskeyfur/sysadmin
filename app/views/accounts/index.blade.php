@extends('layouts.app')

@php($database = $area === 'mysql')
@section('title', $database ? 'Database accounts' : 'SSH accounts')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>{{ $database ? 'Database accounts' : 'SSH accounts' }}</h1>
            <a class="button" href="/admin/accounts/new?service={{ $area }}">Add account</a>
        </div>
        @if ($database && count($mysqlServers))
            <form method="post" action="/admin/accounts/mariadb/import" class="row-actions" style="margin-bottom: 12px">
                @csrf
                <label for="import_server" class="visually-hidden">Server to import users from</label>
                <select id="import_server" name="server_id" style="width: auto">
                    @foreach ($mysqlServers as $mysqlServer)
                        <option value="{{ $mysqlServer->id }}">{{ $mysqlServer->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="secondary">Import users</button>
            </form>
            <p class="hint">Reads the server's MariaDB users ('name'@'%' only; other hosts and roles are ignored) as local database accounts. No passwords are imported: an imported account can't be used to log in until you record its password.</p>
        @endif
        <p class="muted">
            @if ($database)
                Logins the app uses for MariaDB/MySQL: local database users belong to one server, LDAP and shared accounts can be used on several.
            @else
                Logins the app uses for SSH: local system users belong to one server, LDAP and shared accounts can be used on several.
            @endif
            SSH and database accounts are kept apart{{ $database ? '' : '' }}; see <a href="{{ $database ? '/admin/accounts/ssh' : '/admin/accounts/mariadb' }}">{{ $database ? 'SSH accounts' : 'database accounts' }}</a>. Recording a password here doesn't change it anywhere else.
        </p>

        @if (count($accounts) === 0)
            <p class="muted">No {{ $database ? 'database' : 'SSH' }} accounts yet. Servers with {{ $database ? 'MariaDB' : 'SSH' }} monitoring add theirs automatically.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Account</th><th>Type</th><th>Origin</th><th>Used on</th><th>Password</th><th>Last reset</th><th>Due</th></tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $account)
                        @php($status = $service->status($account))
                        <tr>
                            <td><a href="/admin/accounts/{{ $account->id }}">{{ $account->username }}</a></td>
                            <td>{{ $account->typeLabel() }}{{ $account->homeServer ? ' on ' . $account->homeServer->name : '' }}</td>
                            <td class="muted" title="{{ $account->originLabel() }}">{{ $account->originShort() }}</td>
                            <td class="muted">{{ $account->servers->pluck('name')->implode(', ') ?: '—' }}</td>
                            <td>
                                @if ($account->hasPassword())
                                    Stored
                                @elseif (!$account->canLogIn())
                                    <span class="badge warning" title="Imported without a password: record one before it can be used to log in">None: can't log in yet</span>
                                @else
                                    <span class="muted">None</span>
                                @endif
                            </td>
                            <td class="muted">{{ \App\Utils\LocalTime::format($account->password_changed_at, 'Y-m-d') ?: '—' }}</td>
                            <td data-sort="{{ $service->dueAt($account)?->getTimestamp() ?? '' }}">
                                @include('accounts.due', ['account' => $account, 'status' => $status])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
@endsection
