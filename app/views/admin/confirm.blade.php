@extends('layouts.app')

@php($deleting = $action === 'delete')
@section('title', $deleting ? 'Delete ' . $target->username : 'Replace master key')
@section('width', 'narrow')

@section('content')
    <div class="card">
        @if ($deleting)
            <h1>Delete {{ $target->username }}?</h1>
            <p>{{ $target->username }} has seen the master key, so deleting them also replaces it. Afterwards you'll download a new key file and send it to every other user outside this website; each of them must upload it at their next sign-in.</p>
        @else
            <h1>Replace the master key?</h1>
            <p>The current key file stops working. Afterwards you'll download a new key file and send it to every other user outside this website; each of them must upload it at their next sign-in.</p>
        @endif

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="{{ $deleting ? '/admin/users/' . $target->id . '/delete' : '/admin/key/rotate' }}">
            @csrf
            <label for="password">Your password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
            <p class="hint">Needed to re-encrypt your own copy of the new master key.</p>

            <div class="actions">
                <button type="submit" class="{{ $deleting ? 'danger-solid' : '' }}">{{ $deleting ? 'Delete and replace key' : 'Replace master key' }}</button>
                <a href="/admin/users">Cancel</a>
            </div>
        </form>
    </div>
@endsection
