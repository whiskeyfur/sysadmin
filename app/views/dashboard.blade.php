@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif

    <div class="card">
        <h1>Welcome, {{ $auth->user->username }}</h1>
        <p class="muted">Signed in as {{ $auth->isAdmin() ? 'an admin' : 'a user' }}. Monitoring comes next.</p>
    </div>
@endsection
