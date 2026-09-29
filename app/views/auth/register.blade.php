@extends('layouts.app')

@section('title', 'Register')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Register</h1>
        <p class="muted">You need the key file from an admin and an authenticator app. An admin must approve your account before you can sign in.</p>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="/register">
            @csrf
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="{{ $username }}" autocomplete="username" autocapitalize="none" pattern="[A-Za-z0-9_]{3,32}" required autofocus>
            <p class="hint">3–32 letters, numbers or underscores.</p>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required>
            <p class="hint">At least 12 characters.</p>

            <label for="password_confirmation">Confirm password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" required>

            @include('partials.key-file-input')

            <div class="card" style="margin-top: 20px">
                @include('partials.authenticator')
            </div>

            <div class="actions">
                <button type="submit">Register</button>
                <a href="/login">Back to sign in</a>
            </div>
        </form>
    </div>
@endsection
