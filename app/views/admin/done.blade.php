@extends('layouts.app')

@section('title', $title)
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        @if ($temporaryPassword)
            <label for="temporary_password">Temporary password</label>
            <input type="text" id="temporary_password" value="{{ $temporaryPassword }}" readonly onfocus="this.select()">
        @endif

        <div class="actions">
            @if ($offerKeyFile)
                <form method="post" action="/admin/key-file">
                    @csrf
                    <button type="submit">Download new key file</button>
                </form>
            @endif
            <a href="/admin/users">Back to users</a>
        </div>
    </div>
@endsection
