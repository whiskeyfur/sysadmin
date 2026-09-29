@extends('layouts.app')

@section('title', 'Set up your account')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Set up your account</h1>
        <p class="muted">Before you can use sys, choose a new password and connect an authenticator app.</p>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="/setup">
            @csrf
            <input type="hidden" name="username" value="{{ $username }}">

            <label for="password">Current password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>

            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="12" required>
            <p class="hint">At least 12 characters.</p>

            <label for="new_password_confirmation">Confirm new password</label>
            <input type="password" id="new_password_confirmation" name="new_password_confirmation" autocomplete="new-password" minlength="12" required>

            <div class="card" style="margin-top: 20px">
                @include('partials.authenticator')
            </div>

            <div class="actions">
                <button type="submit">Finish setup</button>
            </div>
        </form>
    </div>
@endsection
