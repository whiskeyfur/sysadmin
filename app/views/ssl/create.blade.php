@extends('layouts.app')

@section('title', 'Add a certificate')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Add a certificate</h1>

        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        <form method="post" action="/admin/ssl">
            @csrf
            <div class="grid-2">
                <div>
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" value="{{ $old['name'] ?? '' }}" placeholder="e.g. shop.example.com or Shop wildcard 2026" maxlength="100" required autofocus>
                </div>
                <div>
                    <label for="port">Port</label>
                    <input type="text" id="port" name="port" value="{{ $old['port'] ?? '443' }}" inputmode="numeric" required>
                </div>
            </div>
            <label for="hostnames">Hostnames it covers <span class="muted">(optional)</span></label>
            <textarea id="hostnames" name="hostnames" rows="3" spellcheck="false" placeholder="shop.example.com&#10;www.shop.example.com&#10;*.shop.example.com">{{ $old['hostnames'] ?? '' }}</textarea>
            <p class="hint">One per line. The first is sent to the server to ask for this certificate (SNI), so it must be a real hostname, not a wildcard. Leave empty to use the name as the hostname. Either way, the other hostnames the served certificate lists (SAN) are added automatically.</p>
            <label for="server_id">Served on</label>
            <select id="server_id" name="server_id">
                <option value="">Directly, via DNS (not on a tracked server)</option>
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}" {{ (string) ($old['server_id'] ?? '') === (string) $server->id ? 'selected' : '' }}>{{ $server->name }} ({{ $server->hostname }})</option>
                @endforeach
            </select>
            <p class="hint">More servers and ports can be added on the certificate's page.</p>
            <div class="actions">
                <button type="submit">Add and check</button>
                <a href="/ssl">Cancel</a>
            </div>
        </form>
    </div>
@endsection
