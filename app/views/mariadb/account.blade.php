@extends('layouts.app')

@section('title', 'MariaDB account')

@section('content')
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif
    <div class="card">
        <h1>{{ $account->label }}</h1>
        <p class="muted">One of your private logins for the <a href="/mariadb/query">MariaDB query tool</a>. Changes are saved only when the login works on every server ticked.
            @if ($account->tested_at)
                Last checked {{ \App\Utils\LocalTime::format($account->tested_at) }}.
            @endif
        </p>
        @include('mariadb.account-fields', [
            'values' => $input ?: ['label' => $account->label, 'username' => $account->username],
            'chosen' => $input['servers'] ?? $account->servers,
            'action' => '/mariadb/query/accounts/' . $account->id,
            'button' => 'Check and save',
            'passwordHint' => 'Leave blank to keep the stored password (it\'s never shown).',
        ])
    </div>
    <div class="card">
        <h2>Test or delete</h2>
        <div class="actions" style="margin-top: 0">
            <form method="post" action="/mariadb/query/accounts/{{ $account->id }}/test">
                @csrf
                <button type="submit" class="secondary">Test the stored login again</button>
            </form>
            <form method="post" action="/mariadb/query/accounts/{{ $account->id }}/delete" data-confirm="Delete {{ $account->label }} from your accounts?">
                @csrf
                <button type="submit" class="secondary danger">Delete</button>
            </form>
            <a class="button secondary" href="/mariadb/query">Back to the query tool</a>
        </div>
    </div>
@endsection
