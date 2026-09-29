@extends('layouts.app')

@section('title', 'Accounts')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>Accounts</h1>
            <a class="button" href="/admin/accounts/new">Add account</a>
        </div>
        <p class="muted">Logins on your servers and in your directory: local accounts belong to one server, LDAP and shared accounts can be used on several. Recording a password here doesn't change it anywhere else.</p>

        @if (count($accounts) === 0)
            <p class="muted">No accounts yet. Servers set up with SSH add theirs automatically.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Account</th><th>Type</th><th>Used on</th><th>Password</th><th>Last reset</th><th>Due</th></tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $account)
                        @php($status = $service->status($account))
                        <tr>
                            <td><a href="/admin/accounts/{{ $account->id }}">{{ $account->username }}</a></td>
                            <td>{{ $account->typeLabel() }}{{ $account->homeServer ? ' on ' . $account->homeServer->name : '' }}</td>
                            <td class="muted">{{ $account->servers->pluck('name')->implode(', ') ?: '—' }}</td>
                            <td>{!! $account->hasPassword() ? 'Stored' : '<span class="muted">None</span>' !!}</td>
                            <td class="muted">{{ \App\Utils\LocalTime::format($account->password_changed_at, 'Y-m-d') ?: '—' }}</td>
                            <td>
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
