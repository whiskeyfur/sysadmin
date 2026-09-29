@extends('layouts.app')

@section('title', $title)

@section('content')
    <div class="card">
        <h1>{{ $title }}</h1>
        <p class="muted">Reports are coming soon. For now, the latest results are under <a href="{{ $test }}">Test</a>.</p>
    </div>
@endsection
