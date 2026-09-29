@extends('layouts.app')

@section('title', 'Set up SSH for ' . $server->name)
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>Set up SSH for {{ $server->name }}</h1>
        <p class="muted"><code>{{ $server->ssh_username . '@' . $server->hostname . ':' . $server->ssh_port }}</code></p>

        @if ($result)
            <ul class="steps">
                @foreach ($result->steps as $step)
                    <li class="{{ $step['ok'] ? 'ok' : 'failed' }}">{{ $step['message'] }}</li>
                @endforeach
            </ul>
            @if ($result->ok)
                <div class="alert notice" role="status">SSH is set up{{ $server->ssh_auth === 'password' ? ' (password login)' : ' (key login)' }}.</div>
                <form method="post" action="/admin/servers/{{ $server->id }}/test">
                    @csrf
                    <button type="submit">Test the connection</button>
                    <a href="/servers">Back to servers</a>
                </form>
            @endif
        @endif

        @if (!$result || !$result->ok)
            <form method="post" action="/admin/servers/{{ $server->id }}/ssh-setup">
                @csrf

                @if ($server->ssh_host_key === null)
                    <h2 style="margin-top: 20px">1. Check the host key</h2>
                    @if ($hostKey)
                        <p>{{ $server->hostname }} presents this host key. Check it on the server before trusting it, or you could be trusting an impostor:</p>
                        <p><code>{{ $hostKey->type }} {{ $hostKey->fingerprint() }}</code></p>
                        <p class="hint">On the server: <code>ssh-keygen -lf /etc/ssh/ssh_host_{{ str_contains($hostKey->type, 'rsa') ? 'rsa' : (str_contains($hostKey->type, 'ecdsa') ? 'ecdsa' : 'ed25519') }}_key.pub</code></p>
                        <label class="check"><input type="checkbox" name="fingerprint" value="{{ $hostKey->fingerprint() }}" required> The fingerprint matches</label>
                    @else
                        <div class="alert error" role="alert">{{ $hostKeyError }}</div>
                    @endif
                @else
                    <p class="muted">Host key trusted.</p>
                @endif

                <h2 style="margin-top: 20px">{{ $server->ssh_host_key === null ? '2. ' : '' }}Install the app's key</h2>
                <p>Add this line to {{ $server->ssh_username }}'s <code>~/.ssh/authorized_keys</code> on the server, then continue:</p>
                <code class="pubkey">{{ $publicKey }}</code>

                @if ($server->ssh_password_allowed)
                    <label for="password">Or let the app install it: {{ $server->ssh_username }}'s SSH password <span class="muted">(optional)</span></label>
                    <input type="password" id="password" name="password" autocomplete="off">
                    <p class="hint">Used once to log in and install the key; not stored unless the server refuses key login. Only sent after the host key is verified.</p>
                @else
                    <p class="hint">Password login is not allowed for this server, so the app will never try a password. (Change this in the server's settings.)</p>
                @endif

                <div class="actions">
                    <button type="submit" @if (!$hostKey && $server->ssh_host_key === null) disabled @endif>Continue</button>
                    <a href="/servers">Cancel</a>
                </div>
            </form>
        @endif
    </div>
@endsection
