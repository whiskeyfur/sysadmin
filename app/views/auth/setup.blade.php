@extends('layouts.app')

@section('title', 'Set up your account')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Set up your account</h1>
        <p class="muted">Connect an authenticator app for {{ $username }}. From now on you sign in with your username and a code from the app; the one-time password stops working.</p>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="/setup">
            @csrf
            @include('partials.authenticator')

            <div class="actions">
                <button type="submit">Finish setup</button>
            </div>
        </form>
    </div>
@endsection
