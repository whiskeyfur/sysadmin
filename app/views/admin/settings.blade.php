@extends('layouts.app')

@section('title', 'Settings')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Settings</h1>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif
        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif

        <form method="post" action="/admin/settings">
            @csrf
            <fieldset>
                <legend>SSL monitoring</legend>
                <label for="ssl_warning_days">Warning period (days)</label>
                <input type="text" id="ssl_warning_days" name="ssl_warning_days" value="{{ $settings->sslWarningDays() }}" inputmode="numeric" required>
                <p class="hint">A valid certificate shows as a warning once it expires within this many days (1–365, default {{ \App\Services\SettingsService::DEFAULTS['ssl_warning_days'] }}). Expired or otherwise invalid certificates are always critical.</p>
            </fieldset>

            <fieldset>
                <legend>Accounts</legend>
                <label for="account_warning_days">Rotation warning period (days)</label>
                <input type="text" id="account_warning_days" name="account_warning_days" value="{{ $settings->accountWarningDays() }}" inputmode="numeric" required>
                <p class="hint">An account with a rotation period shows as "due soon" this many days before its password is due (1–365, default {{ \App\Services\SettingsService::DEFAULTS['account_warning_days'] }}).</p>
            </fieldset>

            <div class="actions">
                <button type="submit">Save</button>
            </div>
        </form>
    </div>
@endsection
