@extends('layouts.app')

@section('title', 'Sign in')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Sign in</h1>
        <p class="muted">You need your password and a code from your authenticator app.</p>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif
        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif

        <form method="post" action="/login">
            @csrf
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username" autocapitalize="none" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>

            <label for="code">Authenticator code</label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6">
            <p class="hint">Leave blank on your first sign-in with the temporary password from your admin.</p>

            <div class="actions">
                <button type="submit">Sign in</button>
            </div>
        </form>
    </div>
@endsection
