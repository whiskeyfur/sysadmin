@extends('layouts.app')

@section('title', 'Test ' . $server->name)
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Test {{ $server->name }}</h1>

        <h2>SSH <span class="badge {{ $result->sshOk ? 'ok' : 'failed' }}">{{ $result->sshOk ? 'OK' : 'Failed' }}</span></h2>
        <p>{{ ucfirst($result->sshMessage) }}</p>

        @if ($result->untrustedHostKey)
            <div class="alert warn" role="status">
                <p>First connection: {{ $server->hostname }} presented this host key. Check it on the server before trusting it, or you could be trusting an impostor.</p>
                <p><code>{{ $result->untrustedHostKey->type }} {{ $result->untrustedHostKey->fingerprint() }}</code></p>
                <p>On the server, run <code>ssh-keygen -lf /etc/ssh/ssh_host_{{ str_contains($result->untrustedHostKey->type, 'rsa') ? 'rsa' : (str_contains($result->untrustedHostKey->type, 'ecdsa') ? 'ecdsa' : 'ed25519') }}_key.pub</code> and compare the SHA256 value.</p>
            </div>
            <form method="post" action="/admin/servers/{{ $server->id }}/trust">
                @csrf
                <input type="hidden" name="fingerprint" value="{{ $result->untrustedHostKey->fingerprint() }}">
                <button type="submit">It matches: trust this key and test again</button>
            </form>
        @endif

        @if ($result->mysqlMessage !== null)
            <h2 style="margin-top: 20px">MySQL <span class="badge {{ $result->mysqlOk ? 'ok' : 'failed' }}">{{ $result->mysqlOk ? 'OK' : 'Failed' }}</span></h2>
            <p>{{ ucfirst($result->mysqlMessage) }}</p>
        @endif

        <div class="actions">
            <a href="/servers">Back to servers</a>
        </div>
    </div>
@endsection
