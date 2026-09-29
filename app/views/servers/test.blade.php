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
                <p>SSH isn't set up yet: the server's host key ({{ $result->untrustedHostKey->fingerprint() }}) has to be checked and trusted first.</p>
            </div>
            <a class="button" href="/admin/servers/{{ $server->id }}/ssh-setup">Set up SSH</a>
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
