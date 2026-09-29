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
            <input type="hidden" name="back" value="{{ $back }}">
            @csrf
            <div class="grid-2">
                <div>
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" value="{{ $server->name }}" maxlength="64" required autofocus>
                </div>
                <div>
                    <label for="hostname">Hostname or IP</label>
                    <input type="text" id="hostname" name="hostname" value="{{ $server->hostname }}" autocapitalize="none" required>
                </div>
            </div>
            <p class="hint">Turn on SSH and/or MariaDB below; SSL certificates are attached on the SSL page.</p>

            <fieldset>
                <legend>SSH</legend>
                <label class="check"><input type="checkbox" id="ssh_enabled" name="ssh_enabled" value="1" {{ $server->ssh_enabled ? 'checked' : '' }}> Monitor disk, load and memory over SSH</label>
                <div id="ssh_fields">
                @php($sharedSelected = collect($sharedAccounts)->firstWhere('id', $server->ssh_account_id))
                <label for="ssh_account_id">Log in as</label>
                <select id="ssh_account_id" name="ssh_account_id">
                    <option value="">A local account on this server (username below)</option>
                    @foreach ($sharedAccounts as $shared)
                        <option value="{{ $shared->id }}" {{ $sharedSelected && $sharedSelected->id === $shared->id ? 'selected' : '' }}>{{ $shared->username }} ({{ $shared->typeLabel() }})</option>
                    @endforeach
                </select>
                <p class="hint">The account is tracked under <a href="/admin/accounts">Accounts</a>, with where it's used and its password if one is set up.</p>
                <div class="grid-2">
                    <div id="ssh_username_field">
                        <label for="ssh_username">Username</label>
                        <input type="text" id="ssh_username" name="ssh_username" value="{{ $sharedSelected ? '' : $server->ssh_username }}" autocapitalize="none">
                    </div>
                    <div>
                        <label for="ssh_port">Port</label>
                        <input type="text" id="ssh_port" name="ssh_port" value="{{ $server->ssh_port }}" inputmode="numeric">
                    </div>
                </div>
                <label class="check"><input type="checkbox" name="ssh_password_allowed" value="1" {{ $server->ssh_password_allowed ? 'checked' : '' }}> Allow password login</label>
                <p class="hint">Off by default: the app then never tries a password on this server, which matters for servers that ban password attempts (e.g. fail2ban). When on, a password can install the app's key during setup, and is kept (encrypted) only if the server refuses key login.</p>
                @if ($server->exists)
                    <p class="hint">Currently logs in with {{ $server->ssh_auth === 'password' ? 'a stored password' : "the app's key" }}. <a href="/admin/servers/{{ $server->id }}/ssh-setup">Set up SSH again</a>.</p>
                @endif
                <p class="hint">Changing the hostname, port or username means SSH must be set up again.</p>
                </div>
            </fieldset>

            <fieldset>
                <legend>MariaDB / MySQL</legend>
                <label class="check"><input type="checkbox" id="mysql_enabled" name="mysql_enabled" value="1" {{ $server->mysql_enabled ? 'checked' : '' }}> Monitor this server's database</label>
                <div id="mysql_fields">
                    @php($mysqlShared = collect($sharedAccounts)->firstWhere('id', $server->mysql_account_id))
                    <label for="mysql_account_id">Log in as</label>
                    <select id="mysql_account_id" name="mysql_account_id">
                        <option value="">A database user on this server (username below)</option>
                        @foreach ($sharedAccounts as $shared)
                            <option value="{{ $shared->id }}" {{ $mysqlShared && $mysqlShared->id === $shared->id ? 'selected' : '' }}>{{ $shared->username }} ({{ $shared->typeLabel() }})</option>
                        @endforeach
                    </select>
                    <p class="hint">Tracked under <a href="/admin/accounts">Accounts</a> like SSH logins, with its password, rotation and where it's used.</p>
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
                    <div id="mysql_username_field">
                        <label for="mysql_username">Username</label>
                        <input type="text" id="mysql_username" name="mysql_username" value="{{ $mysqlShared ? '' : $server->mysql_username }}" autocapitalize="none" autocomplete="off">
                    </div>
                    <label for="mysql_password">Password <span class="muted" id="mysql_password_optional">(leave blank to use the password stored in Accounts)</span></label>
                    <input type="password" id="mysql_password" name="mysql_password" autocomplete="new-password" placeholder="{{ $mysqlPasswordStored ? 'Unchanged' : '' }}">
                    <p class="hint">Saved (encrypted) as the account's current password under Accounts; admins can reveal it there. Blank keeps the stored one; a new database user needs one. A monitoring user with only SELECT, PROCESS and SLAVE MONITOR is enough.</p>

                    <label for="mysql_tls">Encryption (TLS)</label>
                    <select id="mysql_tls" name="mysql_tls">
                        <option value="verify" {{ $server->mysql_tls === 'verify' ? 'selected' : '' }}>Encrypt and verify the certificate (recommended)</option>
                        <option value="encrypt" {{ $server->mysql_tls === 'encrypt' ? 'selected' : '' }}>Encrypt, don't check the certificate</option>
                        <option value="off" {{ $server->mysql_tls === 'off' ? 'selected' : '' }}>Off: unencrypted</option>
                    </select>
                    <p class="hint" data-tls="encrypt">Stops eavesdropping, but not someone impersonating the server. Use only if you can't get the server's CA certificate.</p>
                    <p class="hint" data-tls="off">The password and all query results cross the network in plain text. Only for a trusted local network.</p>

                    <div data-tls="verify">
                        <label for="mysql_tls_ca">CA certificate <span class="muted">(optional)</span></label>
                        <textarea id="mysql_tls_ca" name="mysql_tls_ca" rows="5" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----">{{ $server->mysql_tls_ca }}</textarea>
                        <p class="hint">Leave blank if the database's certificate comes from a public CA. For an internal or self-signed setup, paste the CA certificate (PEM), e.g. the file MariaDB's <code>ssl_ca</code> points to. The certificate must be issued for the host above.</p>
                        @foreach ($caCertificates as $certificate)
                            <p class="hint">Current CA: <strong>{{ $certificate['subject'] }}</strong>, expires {{ \App\Utils\LocalTime::format($certificate['expires'], 'Y-m-d') }}{{ $certificate['expires']->isPast() ? ' (expired)' : '' }}.</p>
                        @endforeach
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>SSL</legend>
                @if (count($bindings))
                    <p>Serves:</p>
                    <ul>
                        @foreach ($bindings as $binding)
                            <li><a href="/ssl/{{ $binding->certificate->id }}">{{ $binding->certificate->name }}</a> on port {{ $binding->port }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted">No certificates attached.</p>
                @endif
                <p class="hint">Certificates and the ports they're served on are managed on the <a href="/ssl">SSL page</a>.</p>
            </fieldset>

            <div class="actions">
                <button type="submit">{{ $editing ? 'Save' : 'Add server' }}</button>
                <a href="{{ $back }}">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var toggle = document.getElementById('mysql_enabled');
            var fields = document.getElementById('mysql_fields');
            var tls = document.getElementById('mysql_tls');
            var sections = [['ssh_enabled', 'ssh_fields']];
            var account = document.getElementById('ssh_account_id');
            var mysqlAccount = document.getElementById('mysql_account_id');
            function sync() {
                document.getElementById('ssh_username_field').hidden = account.value !== '';
                document.getElementById('mysql_username_field').hidden = mysqlAccount.value !== '';
                fields.hidden = !toggle.checked;
                sections.forEach(function (pair) { document.getElementById(pair[1]).hidden = !document.getElementById(pair[0]).checked; });
                document.querySelectorAll('[data-tls]').forEach(function (el) { el.hidden = el.dataset.tls !== tls.value; });
            }
            toggle.addEventListener('change', sync);
            tls.addEventListener('change', sync);
            sections.forEach(function (pair) { document.getElementById(pair[0]).addEventListener('change', sync); });
            account.addEventListener('change', sync);
            mysqlAccount.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
