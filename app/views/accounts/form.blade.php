@extends('layouts.app')

@php($editing = $account->exists)
@section('title', $editing ? 'Edit ' . $account->username : 'Add account')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>{{ $editing ? 'Edit ' . $account->username : 'Add account' }}</h1>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="{{ $editing ? '/admin/accounts/' . $account->id : '/admin/accounts' }}">
            @csrf
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="{{ $account->username }}" autocapitalize="none" autocomplete="off" required autofocus>
            <p class="hint">As used to log in, e.g. <code>deploy</code>, <code>CORP\jsmith</code> or <code>jsmith@corp.example</code>.</p>

            <label for="service">Used for</label>
            <select id="service" name="service">
                <option value="ssh" {{ $account->serviceName() === 'ssh' ? 'selected' : '' }}>SSH logins</option>
                <option value="mysql" {{ $account->serviceName() === 'mysql' ? 'selected' : '' }}>Database logins (MariaDB / MySQL)</option>
            </select>
            <p class="hint">SSH and database accounts are kept apart: a directory user who logs into both needs one account for each.</p>

            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="shared" {{ $account->type === 'shared' ? 'selected' : '' }}>Shared</option>
                <option value="ldap" {{ $account->type === 'ldap' ? 'selected' : '' }}>LDAP / directory</option>
                <option value="local" {{ $account->type === 'local' ? 'selected' : '' }}>Local to one server</option>
            </select>
            <p class="hint">LDAP and shared accounts can be chosen on any server; a local one belongs to one server (a system user for SSH, a database user for MariaDB).</p>

            <div id="server_field">
                <label for="server_id">Server</label>
                <select id="server_id" name="server_id">
                    <option value="">Choose…</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" {{ $account->server_id === $server->id ? 'selected' : '' }}>{{ $server->name }}</option>
                    @endforeach
                </select>
            </div>

            <label for="rotation_days">Change the password every <span class="muted">(days, optional)</span></label>
            <input type="text" id="rotation_days" name="rotation_days" value="{{ $account->rotation_days }}" inputmode="numeric">
            <p class="hint">Due date = last reset + this many days. Leave empty for no rotation.</p>

            @unless ($editing)
                <label for="password">Current password <span class="muted">(optional)</span></label>
                <input type="password" id="password" name="password" autocomplete="new-password">
                <label for="password_changed_at">Last reset on <span class="muted">(optional, YYYY-MM-DD; default today)</span></label>
                <input type="text" id="password_changed_at" name="password_changed_at" placeholder="{{ \App\Utils\LocalTime::format(\Carbon\Carbon::now(), 'Y-m-d') }}">
                <p class="hint">Stored encrypted; admins can reveal it later with an authenticator code.</p>
            @endunless

            <label for="notes">Notes <span class="muted">(optional)</span></label>
            <textarea id="notes" name="notes" rows="3">{{ $account->notes }}</textarea>

            <div class="actions">
                <button type="submit">{{ $editing ? 'Save' : 'Add account' }}</button>
                <a href="{{ $editing ? '/admin/accounts/' . $account->id : '/admin/accounts' }}">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var type = document.getElementById('type');
            function sync() { document.getElementById('server_field').hidden = type.value !== 'local'; }
            type.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
