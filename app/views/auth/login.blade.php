@extends('layouts.app')

@section('title', 'Sign in')
@section('width', 'narrow')

@section('content')
    @php($password = in_array('password', $methods, true))
    @php($authenticator = in_array('authenticator', $methods, true))
    @php($passkey = in_array('passkey', $methods, true))
    <div class="card">
        <h1>Sign in</h1>
        <p class="muted">Your username, then {{ implode(', or ', array_filter([$password ? 'your password' : null, $authenticator ? 'a code from your authenticator app' : null, $passkey ? 'a passkey or security key' : null])) }}.</p>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif
        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif

        <form method="post" action="/login">
            @csrf
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username webauthn" autocapitalize="none" required autofocus>

            @if ($password)
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password">
            @endif

            @if ($authenticator)
                <label for="code">{{ $password ? 'Or a code' : 'Code' }} from your authenticator app</label>
                <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6">
            @endif

            <details style="margin-top: 16px">
                <summary>First sign-in? Use your one-time password</summary>
                <label for="one_time_password">One-time password from your admin</label>
                <input type="password" id="one_time_password" name="one_time_password" autocomplete="off">
                <p class="hint">Leave the other fields blank. Next you'll set up how you sign in from now on.</p>
            </details>

            <div class="actions">
                {{-- Also what submits a one-time password, so it's there even when only passkeys are on. --}}
                <button type="submit" class="{{ $password || $authenticator ? '' : 'secondary' }}">Sign in</button>
                @if ($passkey && $passkeysHere)
                    <button type="button" class="{{ $password || $authenticator ? 'secondary' : '' }}" data-passkey-login>Sign in with a passkey</button>
                @endif
            </div>
            @if ($passkey)
                @if ($passkeysHere)
                    <p class="hint">For a security key, enter your username first.</p>
                @else
                    <p class="hint">Passkeys only work over https (or on localhost).</p>
                @endif
            @endif
            <p class="passkey-message alert error" role="alert" hidden></p>
        </form>
    </div>
    <script src="/assets/js/passkeys.js"></script>
@endsection
