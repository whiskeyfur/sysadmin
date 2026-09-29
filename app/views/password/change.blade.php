@extends('layouts.app')

@section('title', 'Change password')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Change password</h1>
        @if ($expired)
            <div class="alert warn" role="status">Your password has expired. Passwords must be changed every {{ $maxAgeDays }} days; choose a new one to continue.</div>
        @else
            <p class="muted">Passwords expire every {{ $maxAgeDays }} days.</p>
        @endif

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif
        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif

        <form method="post" action="/password">
            @csrf
            <label for="password">Current password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>

            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="{{ $minLength }}" required>
            <p class="hint">At least {{ $minLength }} characters, and different from your current password.</p>

            <label for="new_password_confirmation">Confirm new password</label>
            <input type="password" id="new_password_confirmation" name="new_password_confirmation" autocomplete="new-password" minlength="{{ $minLength }}" required>

            <div class="actions">
                <button type="submit">Change password</button>
                @unless ($expired)
                    <a href="/">Cancel</a>
                @endunless
            </div>
        </form>
    </div>
@endsection
