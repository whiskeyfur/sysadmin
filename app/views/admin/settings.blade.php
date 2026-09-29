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
                    <p class="hint">Anyone idle this long signs in again with their authenticator (5–{{ \App\Services\AuthSessionService::MAX_TIMEOUT_MINUTES }}, default {{ $default('session_timeout_minutes') }}). Applies to sessions already open, from their next request.</p>
                </fieldset>
            @elseif ($section === 'ssl')
                <fieldset>
                    <legend>Certificates</legend>
                    <label for="ssl_warning_days">Warning period (days)</label>
                    <input type="text" id="ssl_warning_days" name="ssl_warning_days" value="{{ $value('ssl_warning_days') }}" inputmode="numeric" required>
                    <p class="hint">A valid certificate shows as a warning once it expires within this many days (1–365, default {{ $default('ssl_warning_days') }}). Expired or otherwise invalid certificates are always critical.</p>

                    <label for="ssl_import_names">Hostnames found in certificates</label>
                    <select id="ssl_import_names" name="ssl_import_names">
                        <option value="1" {{ $value('ssl_import_names') === 1 ? 'selected' : '' }}>Add them to the certificate's list automatically</option>
                        <option value="0" {{ $value('ssl_import_names') === 0 ? 'selected' : '' }}>Leave the list as I set it</option>
                    </select>
                    <p class="hint">Every check (scheduled every {{ \App\Services\ScheduledCheckService::DEFAULT_INTERVAL_MINUTES }} minutes) reads the hostnames a certificate covers (SAN); new ones, e.g. from a renewal, are added. Only from certificates that check out as valid.</p>
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
