@extends('layouts.app')

@section('title', 'Sign in')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Sign in</h1>
        <p class="muted">You need your username and a code from your authenticator app.</p>

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

            <label for="code">Authenticator code</label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6">

            <details style="margin-top: 16px">
                <summary>First sign-in? Use your one-time password</summary>
                <label for="one_time_password">One-time password from your admin</label>
                <input type="password" id="one_time_password" name="one_time_password" autocomplete="off">
                <p class="hint">Leave the code blank. Next you'll connect an authenticator app; after that you sign in with codes only.</p>
            </details>

            <div class="actions">
                <button type="submit">Sign in</button>
            </div>
        </form>
    </div>
@endsection
