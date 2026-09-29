@extends('layouts.app')

@section('title', $title)
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        <label for="temporary_password">Temporary password</label>
        <input type="text" id="temporary_password" value="{{ $temporaryPassword }}" readonly onfocus="this.select()">
        <p class="hint">Shown only once.</p>

        <div class="actions">
            <a href="/admin/users">Back to users</a>
        </div>
    </div>
@endsection
