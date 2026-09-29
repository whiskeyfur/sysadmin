@extends('layouts.app')

@section('title', 'Upload the current key file')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Upload the current key file</h1>
        <p class="muted">An admin replaced the master key. Get the new key file from them, then sign in again with it.</p>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="/key/replace">
            @csrf
            <input type="hidden" name="username" value="{{ $username }}">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>

            <label for="code">Authenticator code</label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            <p class="hint">Use a new code, not the one you just entered.</p>

            @include('partials.key-file-input')

            <div class="actions">
                <button type="submit">Continue</button>
            </div>
        </form>
    </div>
@endsection
