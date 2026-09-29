@extends('layouts.app')

@php($editing = $server->exists)
@section('title', $editing ? 'Edit ' . $server->name : 'Add server')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>{{ $editing ? 'Edit ' . $server->name : 'Add server' }}</h1>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="{{ $editing ? '/admin/servers/' . $server->id : '/admin/servers' }}">
            @csrf
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="{{ $server->name }}" maxlength="64" required autofocus>

            <fieldset>
                <legend>SSH</legend>
                <div class="grid-2">
                    <div>
                        <label for="hostname">Hostname or IP</label>
                        <input type="text" id="hostname" name="hostname" value="{{ $server->hostname }}" autocapitalize="none" required>
                    </div>
                    <div>
                        <label for="ssh_port">Port</label>
                        <input type="text" id="ssh_port" name="ssh_port" value="{{ $server->ssh_port }}" inputmode="numeric" required>
                    </div>
                </div>
                <label for="ssh_username">Username</label>
                <input type="text" id="ssh_username" name="ssh_username" value="{{ $server->ssh_username }}" autocapitalize="none" required>
                <p class="hint">The app logs in with its own key, shown on the Servers page. Changing the hostname or port means the host key must be trusted again.</p>
            </fieldset>

            <fieldset>
                <legend>MySQL / MariaDB</legend>
                <label class="check"><input type="checkbox" id="mysql_enabled" name="mysql_enabled" value="1" {{ $server->mysql_enabled ? 'checked' : '' }}> Monitor this server's database</label>
                <div id="mysql_fields">
                    <div class="grid-2">
                        <div>
                            <label for="mysql_host">Host</label>
                            <input type="text" id="mysql_host" name="mysql_host" value="{{ $server->mysql_host }}" autocapitalize="none" placeholder="Same as SSH hostname">
                        </div>
                        <div>
                            <label for="mysql_port">Port</label>
                            <input type="text" id="mysql_port" name="mysql_port" value="{{ $server->mysql_port ?: 3306 }}" inputmode="numeric">
                        </div>
                    </div>
                    <label for="mysql_username">Username</label>
                    <input type="text" id="mysql_username" name="mysql_username" value="{{ $server->mysql_username }}" autocapitalize="none" autocomplete="off">
                    <label for="mysql_password">Password</label>
                    <input type="password" id="mysql_password" name="mysql_password" autocomplete="new-password" placeholder="{{ $server->mysql_password ? 'Unchanged' : '' }}">
                    <p class="hint">Connects directly over TCP, so the database must accept connections from this machine. Stored encrypted and never shown again{{ $server->mysql_password ? '; leave blank to keep the current password' : '' }}. A read-only monitoring user is enough.</p>
                </div>
            </fieldset>

            <div class="actions">
                <button type="submit">{{ $editing ? 'Save' : 'Add server' }}</button>
                <a href="/servers">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var toggle = document.getElementById('mysql_enabled');
            var fields = document.getElementById('mysql_fields');
            function sync() { fields.hidden = !toggle.checked; }
            toggle.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
