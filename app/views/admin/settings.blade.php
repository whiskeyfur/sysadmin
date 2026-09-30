@extends('layouts.app')

@section('title', $title)
@section('width', 'narrow')

@php($value = fn (string $key) => $settings->integer($key))
@php($default = fn (string $key) => \App\Services\SettingsService::DEFAULTS[$key])

@section('content')
    <div class="card">
        <h1>{{ $title }}</h1>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif
        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif

        <form method="post" action="/admin/settings/{{ $section }}">
            @csrf
            @if ($section === 'app')
                <fieldset>
                    <legend>Sign-in</legend>
                    <label for="session_timeout_minutes">Sign users out after (minutes without activity)</label>
                    <input type="text" id="session_timeout_minutes" name="session_timeout_minutes" value="{{ $value('session_timeout_minutes') }}" inputmode="numeric" required>
                    <p class="hint">Anyone idle this long signs in again (5–{{ \App\Services\AuthSessionService::MAX_TIMEOUT_MINUTES }}, default {{ $default('session_timeout_minutes') }}). Applies to sessions already open, from their next request.</p>
                </fieldset>

                <fieldset>
                    <legend>Ways to sign in</legend>
                    <p class="hint">Users sign in with their username and <strong>any one</strong> method they've set up that's on here. <strong>Required</strong>: everyone must set it up (whoever hasn't is sent to their profile after their next sign-in). <strong>Optional</strong>: users may set it up. A method alone signs someone in, so with passwords on, a password alone is enough; passkeys and authenticator codes resist phishing and guessing better.</p>
                    @foreach (\App\Services\LoginMethodService::SETTINGS as $method => $key)
                        <label for="{{ $key }}">{{ \App\Services\LoginMethodService::LABELS[$method] }}</label>
                        <select id="{{ $key }}" name="{{ $key }}">
                            @foreach (\App\Services\LoginMethodService::LEVELS as $level => $name)
                                <option value="{{ $level }}" {{ $value($key) === $level ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    @endforeach
                    <p class="hint">Default: authenticator required, the others off. A change that would leave an admin unable to sign in is refused. Passkeys work over https, or on localhost.</p>
                </fieldset>
            @elseif ($section === 'ssl')
                <fieldset>
                    <legend>Certificates</legend>
                    <label for="ssl_warning_days">Warning period (days)</label>
                    <input type="text" id="ssl_warning_days" name="ssl_warning_days" value="{{ $value('ssl_warning_days') }}" inputmode="numeric" required>
                    <p class="hint">A valid certificate shows as a warning once it expires within this many days (1–365, default {{ $default('ssl_warning_days') }}). Expired or otherwise invalid certificates are always critical.</p>

                    <label for="ssl_import_names">Names found on certificates</label>
                    <select id="ssl_import_names" name="ssl_import_names">
                        <option value="1" {{ $value('ssl_import_names') === 1 ? 'selected' : '' }}>Give each one an entry of its own</option>
                        <option value="0" {{ $value('ssl_import_names') === 0 ? 'selected' : '' }}>Only check the entries I add</option>
                    </select>
                    <p class="hint">Every check reads the names a certificate lists (SAN); each one without an entry, e.g. from a renewal, gets its own, checked directly through DNS at the same port (a name on a certificate may not resolve or answer). Only from certificates that check out as valid; wildcards are skipped.</p>

                    <label for="ssl_missing_intermediate">Server doesn't send its intermediate certificate</label>
                    <select id="ssl_missing_intermediate" name="ssl_missing_intermediate">
                        @foreach ([0 => 'Info (noted; the certificate stays OK)', 1 => 'Warning', 2 => 'Danger (critical)'] as $level => $name)
                            <option value="{{ $level }}" {{ $value('ssl_missing_intermediate') === $level ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="hint">Browsers fetch a missing intermediate from the address in the certificate and show the site fine; curl, PHP, Java and some phones don't, and reject it. The check does what browsers do (from a public address only) and notes it in the result as info; choose whether that should instead be a warning or danger (critical).</p>

                    <label for="ssl_check_hours">Check certificates every (hours)</label>
                    <input type="text" id="ssl_check_hours" name="ssl_check_hours" value="{{ $value('ssl_check_hours') }}" inputmode="numeric" required>
                    <p class="hint">Scheduled checks of every certificate, on servers and served directly (1–168, default {{ $default('ssl_check_hours') }}: once a day). Check now and Check all now still work any time.</p>
                </fieldset>
            @elseif ($section === 'ssh')
                <fieldset>
                    <legend>Disk space</legend>
                    <label for="disk_warning_percent">Warning at (% used)</label>
                    <input type="text" id="disk_warning_percent" name="disk_warning_percent" value="{{ $value('disk_warning_percent') }}" inputmode="numeric" required>
                    <label for="disk_critical_percent">Critical at (% used)</label>
                    <input type="text" id="disk_critical_percent" name="disk_critical_percent" value="{{ $value('disk_critical_percent') }}" inputmode="numeric" required>
                    <p class="hint">The disk check flags a filesystem once this much of its space, or of its inodes, is used (1–100, warning below critical; defaults {{ $default('disk_warning_percent') }} and {{ $default('disk_critical_percent') }}).</p>
                </fieldset>

                <fieldset>
                    <legend>Accounts</legend>
                    <label for="account_warning_days">Rotation warning period (days)</label>
                    <input type="text" id="account_warning_days" name="account_warning_days" value="{{ $value('account_warning_days') }}" inputmode="numeric" required>
                    <p class="hint">An account with a rotation period shows as "due soon" this many days before its password is due (1–365, default {{ $default('account_warning_days') }}).</p>
                </fieldset>
            @elseif ($section === 'apache')
                <fieldset>
                    <legend>Server errors (5xx)</legend>
                    <label for="apache_5xx_warning_percent">Warning at (% of requests in the last hour)</label>
                    <input type="text" id="apache_5xx_warning_percent" name="apache_5xx_warning_percent" value="{{ $value('apache_5xx_warning_percent') }}" inputmode="numeric" required>
                    <label for="apache_5xx_critical_percent">Critical at (% of requests in the last hour)</label>
                    <input type="text" id="apache_5xx_critical_percent" name="apache_5xx_critical_percent" value="{{ $value('apache_5xx_critical_percent') }}" inputmode="numeric" required>
                    <p class="hint">From the access logs; only judged with at least 20 requests in the hour (1–100, warning below critical; defaults {{ $default('apache_5xx_warning_percent') }} and {{ $default('apache_5xx_critical_percent') }}).</p>
                </fieldset>

                <fieldset>
                    <legend>Error log</legend>
                    <label for="apache_errors_warning">Warn at (errors in the last hour)</label>
                    <input type="text" id="apache_errors_warning" name="apache_errors_warning" value="{{ $value('apache_errors_warning') }}" inputmode="numeric" required>
                    <p class="hint">A crashed child process (e.g. a segfault) is always critical (default {{ $default('apache_errors_warning') }}).</p>
                </fieldset>

                <fieldset>
                    <legend>Workers</legend>
                    <label for="apache_workers_warning_percent">Warning at (% of workers busy)</label>
                    <input type="text" id="apache_workers_warning_percent" name="apache_workers_warning_percent" value="{{ $value('apache_workers_warning_percent') }}" inputmode="numeric" required>
                    <label for="apache_workers_critical_percent">Critical at (% of workers busy)</label>
                    <input type="text" id="apache_workers_critical_percent" name="apache_workers_critical_percent" value="{{ $value('apache_workers_critical_percent') }}" inputmode="numeric" required>
                    <p class="hint">Live figures need mod_status in the configuration; without it, "reached MaxRequestWorkers" in the error log is a warning (defaults {{ $default('apache_workers_warning_percent') }} and {{ $default('apache_workers_critical_percent') }}).</p>
                </fieldset>

                <fieldset>
                    <legend>Restarts</legend>
                    <label for="apache_restart_warning_minutes">Warn after a restart for (minutes)</label>
                    <input type="text" id="apache_restart_warning_minutes" name="apache_restart_warning_minutes" value="{{ $value('apache_restart_warning_minutes') }}" inputmode="numeric" required>
                    <p class="hint">1–10080, default {{ $default('apache_restart_warning_minutes') }}.</p>
                </fieldset>
                <fieldset>
                    <legend>Access log</legend>
                    <label for="apache_access_keep_days">Keep each request for (days)</label>
                    <input type="text" id="apache_access_keep_days" name="apache_access_keep_days" value="{{ $value('apache_access_keep_days') }}" inputmode="numeric" required>
                    <p class="hint">0–30, default {{ $default('apache_access_keep_days') }}. Every request in the access logs is stored (client, host, request, status, size, referer, user agent) and listed in the Apache report; a busy site logs a lot, so they're kept for less time than the rest. 0 stores none. The per-minute request and traffic figures are kept {{ \App\Services\HealthCheckService::RETENTION_DAYS }} days either way.</p>
                </fieldset>
            @else
                <fieldset>
                    <legend>Connections</legend>
                    <label for="mysql_connections_warning_percent">Warning at (% of max_connections)</label>
                    <input type="text" id="mysql_connections_warning_percent" name="mysql_connections_warning_percent" value="{{ $value('mysql_connections_warning_percent') }}" inputmode="numeric" required>
                    <label for="mysql_connections_critical_percent">Critical at (% of max_connections)</label>
                    <input type="text" id="mysql_connections_critical_percent" name="mysql_connections_critical_percent" value="{{ $value('mysql_connections_critical_percent') }}" inputmode="numeric" required>
                    <p class="hint">1–100, warning below critical; defaults {{ $default('mysql_connections_warning_percent') }} and {{ $default('mysql_connections_critical_percent') }}.</p>
                </fieldset>

                <fieldset>
                    <legend>Replication</legend>
                    <label for="mysql_lag_warning_seconds">Lag warning at (seconds behind)</label>
                    <input type="text" id="mysql_lag_warning_seconds" name="mysql_lag_warning_seconds" value="{{ $value('mysql_lag_warning_seconds') }}" inputmode="numeric" required>
                    <label for="mysql_lag_critical_seconds">Lag critical at (seconds behind)</label>
                    <input type="text" id="mysql_lag_critical_seconds" name="mysql_lag_critical_seconds" value="{{ $value('mysql_lag_critical_seconds') }}" inputmode="numeric" required>
                    <p class="hint">1–86400, warning below critical; defaults {{ $default('mysql_lag_warning_seconds') }} and {{ $default('mysql_lag_critical_seconds') }}. A stopped replica is always critical.</p>
                </fieldset>

                <fieldset>
                    <legend>InnoDB buffer pool</legend>
                    <label for="mysql_buffer_pool_warning_percent">Warn below (% of reads from memory)</label>
                    <input type="text" id="mysql_buffer_pool_warning_percent" name="mysql_buffer_pool_warning_percent" value="{{ $value('mysql_buffer_pool_warning_percent') }}" inputmode="numeric" required>
                    <p class="hint">1–100, default {{ $default('mysql_buffer_pool_warning_percent') }}.</p>
                </fieldset>

                <fieldset>
                    <legend>Server status</legend>
                    <label for="mysql_restart_warning_minutes">Warn after a restart for (minutes)</label>
                    <input type="text" id="mysql_restart_warning_minutes" name="mysql_restart_warning_minutes" value="{{ $value('mysql_restart_warning_minutes') }}" inputmode="numeric" required>
                    <p class="hint">A restart this recent is a warning, as it may have been a crash (1–10080, default {{ $default('mysql_restart_warning_minutes') }}).</p>
                </fieldset>

                <fieldset>
                    <legend>Log import</legend>
                    <label for="mysql_log_import_minutes">Import logs every (minutes)</label>
                    <input type="text" id="mysql_log_import_minutes" name="mysql_log_import_minutes" value="{{ $value('mysql_log_import_minutes') }}" inputmode="numeric" required>
                    <p class="hint">Scheduled checks also import the MariaDB logs of servers with SSH set up, reading only what's new since the last import. 0 turns it off; admins can still use Import log (0–1440, default {{ $default('mysql_log_import_minutes') }}).</p>
                </fieldset>
            @endif

            <div class="actions">
                <button type="submit">Save</button>
            </div>
        </form>
    </div>
@endsection
