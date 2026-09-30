@extends('layouts.app')

@php($editing = $server->exists)
@php($module = ['ssh' => 'SSH', 'mysql' => 'MariaDB', 'apache' => 'Apache'][$kind ?? ''] ?? null)
@php($heading = $editing ? 'Edit ' . $server->name . ($module ? ": $module" : '') : ($module ? "Add a server to $module" : 'Add server'))
@section('title', $heading)
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>{{ $heading }}</h1>
        @if ($module)
            <p class="muted">Only {{ $module }} settings; the server's other monitoring isn't changed here.</p>
        @endif

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="{{ $editing ? '/admin/servers/' . $server->id : '/admin/servers' }}">
            <input type="hidden" name="back" value="{{ $back }}">
            @if ($kind)
                <input type="hidden" name="kind" value="{{ $kind }}">
            @endif
            @csrf
            @if (count($candidates))
                <label for="existing_id">Server</label>
                <select id="existing_id" name="existing_id">
                    <option value="">A new server</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->hostname }}), add {{ $module }} to it</option>
                    @endforeach
                </select>
            @endif
            <div class="grid-2" id="identity_fields">
                <div>
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" value="{{ $server->name }}" maxlength="64" required autofocus>
                </div>
                <div>
                    <label for="hostname">Hostname or IP</label>
                    <input type="text" id="hostname" name="hostname" value="{{ $server->hostname }}" autocapitalize="none" required>
                </div>
            </div>
            @unless ($kind)
                <p class="hint">Turn on SSH, MariaDB and/or Apache below; SSL certificates are attached on the SSL page.</p>
            @endunless

            {{-- SSH: its own form, the full form, and a new Apache server (Apache is read over SSH). --}}
            @if ($kind === null || $kind === 'ssh' || ($kind === 'apache' && !$editing))
            <fieldset id="ssh_fieldset">
                <legend>SSH</legend>
                @if ($kind === 'apache')
                    <p class="hint">Apache is monitored from its configuration and log files, read over SSH.</p>
                @endif
                @if ($kind === 'ssh' || $kind === 'apache')
                    <input type="checkbox" id="ssh_enabled" name="ssh_enabled" value="1" checked hidden>
                @else
                    <label class="check"><input type="checkbox" id="ssh_enabled" name="ssh_enabled" value="1" {{ $server->ssh_enabled ? 'checked' : '' }}> Monitor disk, load and memory over SSH</label>
                @endif
                <div id="ssh_fields">
                @php($sharedSelected = collect($sharedAccounts)->firstWhere('id', $server->ssh_account_id))
                <label for="ssh_account_id">Log in as</label>
                <select id="ssh_account_id" name="ssh_account_id">
                    <option value="">A local account on this server (username below)</option>
                    @foreach ($sharedAccounts as $shared)
                        <option value="{{ $shared->id }}" {{ $sharedSelected && $sharedSelected->id === $shared->id ? 'selected' : '' }}>{{ $shared->username }} ({{ $shared->typeLabel() }})</option>
                    @endforeach
                </select>
                <p class="hint">The account is tracked under <a href="/admin/accounts/ssh">SSH accounts</a>, with where it's used and its password if one is set up.</p>
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
            @endif

            @if ($kind === null || $kind === 'mysql')
            <fieldset>
                <legend>MariaDB / MySQL</legend>
                @if ($kind === 'mysql')
                    <input type="checkbox" id="mysql_enabled" name="mysql_enabled" value="1" checked hidden>
                @else
                    <label class="check"><input type="checkbox" id="mysql_enabled" name="mysql_enabled" value="1" {{ $server->mysql_enabled ? 'checked' : '' }}> Monitor this server's database</label>
                @endif
                <div id="mysql_fields">
                    @php($mysqlShared = collect($mysqlAccounts)->firstWhere('id', $server->mysql_account_id))
                    <label for="mysql_account_id">Log in as</label>
                    <select id="mysql_account_id" name="mysql_account_id">
                        <option value="">A database user on this server (username below)</option>
                        @foreach ($mysqlAccounts as $shared)
                            <option value="{{ $shared->id }}" {{ $mysqlShared && $mysqlShared->id === $shared->id ? 'selected' : '' }}>{{ $shared->username }} ({{ $shared->typeLabel() }})</option>
                        @endforeach
                    </select>
                    <p class="hint">Tracked under <a href="/admin/accounts/mariadb">database accounts</a>, with its password, rotation and where it's used.</p>
                    <div class="grid-2">
                        <div>
                            <label for="mysql_host">Host</label>
                            <input type="text" id="mysql_host" name="mysql_host" value="{{ $server->mysql_host }}" autocapitalize="none" placeholder="Same as the server's hostname">
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

            @endif

            @if ($kind === null || $kind === 'apache')
            <fieldset>
                <legend>Apache</legend>
                @if ($kind === 'apache')
                    <input type="checkbox" id="apache_enabled" name="apache_enabled" value="1" checked hidden>
                    <p>Apache will be monitored from its own configuration and log files, over SSH.</p>
                @else
                    <label class="check"><input type="checkbox" id="apache_enabled" name="apache_enabled" value="1" {{ $server->apache_enabled ? 'checked' : '' }}> Monitor Apache from its configuration and log files (needs SSH)</label>
                @endif
                <p class="hint">Nothing about locations is assumed: Apache's control program (apache2ctl, apachectl or httpd) lists its configuration files, which say where the error and access logs are. mod_status adds live worker figures if the configuration has it; without it, the error log is used. The SSH user needs read access to the logs (e.g. the adm group).</p>
                <label for="apache_config_file">Configuration file (optional)</label>
                <input type="text" id="apache_config_file" name="apache_config_file" value="{{ $server->apache_config_file }}" autocapitalize="none" placeholder="/etc/httpd/conf/httpd.conf" spellcheck="false">
                <p class="hint">Only needed when Apache can't be found: its control program is looked for on the server, then in the Docker/Podman containers the SSH user can see (rootless containers only for the user that runs them; the one found is remembered). Failing both, Apache's main configuration file (httpd.conf or apache2.conf) is read directly with the files it includes.</p>
                <label for="apache_error_logs">Error logs (optional)</label>
                <textarea id="apache_error_logs" name="apache_error_logs" rows="2" spellcheck="false" autocapitalize="none" placeholder="/var/log/httpd/error_log">{{ $server->apache_error_logs }}</textarea>
                <label for="apache_access_logs">Access logs (optional)</label>
                <textarea id="apache_access_logs" name="apache_access_logs" rows="2" spellcheck="false" autocapitalize="none" placeholder="/var/log/httpd/access_log">{{ $server->apache_access_logs }}</textarea>
                <p class="hint">One full path per line, up to {{ \App\Services\ServerService::MAX_APACHE_LOGS }} each. Read as well as the logs Apache's configuration names, and on their own if the configuration can't be found. In a container, paths are inside it.</p>
                @if ($server->exists && $server->apache_container)
                    <p class="hint">Apache runs in {{ str_replace(':', ' container ', $server->apache_container) }} (found automatically; tried first on each scan).</p>
                @endif
                @if ($server->exists && $server->apache_config)
                    <p class="hint">Last scan {{ \App\Utils\LocalTime::format($server->apache_scanned_at) }}: {{ $server->apache_config['version'] ?? 'Apache' }}, {{ count($server->apache_config['error_logs'] ?? []) }} error log(s), {{ count($server->apache_config['access_logs'] ?? []) }} access log(s){{ !empty($server->apache_config['status_url']) ? ', mod_status' : '' }}.</p>
                @endif
            </fieldset>
            @endif

            @unless ($kind)
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
            @endunless

            <div class="actions">
                <button type="submit">{{ $editing ? 'Save' : ($module ? "Add to $module" : 'Add server') }}</button>
                <a href="{{ $back }}">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var byId = function (id) { return document.getElementById(id); };
            var existing = byId('existing_id');
            function sync() {
                [['ssh_account_id', 'ssh_username_field'], ['mysql_account_id', 'mysql_username_field']].forEach(function (pair) {
                    if (byId(pair[0])) { byId(pair[1]).hidden = byId(pair[0]).value !== ''; }
                });
                [['ssh_enabled', 'ssh_fields'], ['mysql_enabled', 'mysql_fields']].forEach(function (pair) {
                    if (byId(pair[0])) { byId(pair[1]).hidden = !byId(pair[0]).checked; }
                });
                if (byId('mysql_tls')) {
                    document.querySelectorAll('[data-tls]').forEach(function (el) { el.hidden = el.dataset.tls !== byId('mysql_tls').value; });
                }
                if (existing) {
                    // An existing server keeps its name, hostname and (for Apache) SSH settings.
                    byId('identity_fields').hidden = existing.value !== '';
                    if (byId('ssh_fieldset') && byId('apache_enabled') && byId('apache_enabled').hidden) { byId('ssh_fieldset').hidden = existing.value !== ''; }
                    ['name', 'hostname'].forEach(function (id) { byId(id).required = existing.value === ''; });
                }
            }
            ['ssh_account_id', 'mysql_account_id', 'ssh_enabled', 'mysql_enabled', 'mysql_tls', 'existing_id'].forEach(function (id) {
                if (byId(id)) { byId(id).addEventListener('change', sync); }
            });
            sync();
        })();
    </script>
@endsection
