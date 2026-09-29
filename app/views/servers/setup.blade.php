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
                <form method="post" action="/servers/{{ $server->id }}/test">
                    @csrf
                    <button type="submit">Test the connection</button>
                    <a href="/ssh">Back to SSH</a>
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
                        @php($keyFile = 'ssh_host_' . (str_contains($hostKey->type, 'rsa') ? 'rsa' : (str_contains($hostKey->type, 'ecdsa') ? 'ecdsa' : 'ed25519')) . '_key.pub')
                        @php($platform = $server->platform()->value)
                        <p class="hint">To see it, run this on the server itself (not over a new connection to it) and compare the <code>SHA256:</code> value:</p>
                        <ul class="hint">
                            @if ($platform !== 'windows')
                                <li>@if ($platform === 'unknown')Linux, macOS, NAS: @endif<code>ssh-keygen -lf /etc/ssh/{{ $keyFile }}</code></li>
                            @endif
                            @if ($platform !== 'unix')
                                <li>@if ($platform === 'unknown')Windows (OpenSSH Server), @endif in PowerShell: <code>ssh-keygen -lf $env:ProgramData\ssh\{{ $keyFile }}</code></li>
                            @endif
                        </ul>
                        @if ($platform !== 'unknown')
                            <p class="hint">Detected a {{ $server->platform()->label() }} server from its SSH banner.</p>
                        @endif
                        <input type="hidden" name="fingerprint" value="{{ $hostKey->fingerprint() }}">
                        <label class="check"><input type="radio" name="host_key_check" value="manual" required> I checked it on the server: the fingerprint matches</label>
                        <label class="check"><input type="radio" name="host_key_check" value="login" required> Verify it through the account: log in as {{ $server->ssh_username }} and look it up in the server's own host key files</label>
                        <p class="hint">Verifying through the account logs in before the key is trusted: with the app's key if it's already installed, otherwise with the password below (only if password login is allowed), which then also installs the key. It catches a wrong or changed key, but it's weaker than checking on the server yourself: an impostor in the middle would see the password and could fake the answer.</p>
                    @else
                        <div class="alert error" role="alert">{{ $hostKeyError }}</div>
                    @endif
                @else
                    <p class="muted">Host key trusted.</p>
                @endif

                <h2 style="margin-top: 20px">{{ $server->ssh_host_key === null ? '2. ' : '' }}Install the app's key</h2>
                @php($platform = $server->platform()->value)
                @if ($platform === 'windows')
                    <p>Add this line on the Windows server, then continue:</p>
                    <ul>
                        <li>if {{ $server->ssh_username }} is in the Administrators group: to <code>C:\ProgramData\ssh\administrators_authorized_keys</code></li>
                        <li>otherwise: to <code>C:\Users\{{ $server->ssh_username }}\.ssh\authorized_keys</code></li>
                    </ul>
                @else
                    <p>Add this line to {{ $server->ssh_username }}'s <code>~/.ssh/authorized_keys</code> on the server, then continue:</p>
                @endif
                <code class="pubkey">{{ $publicKey }}</code>
                @if ($platform === 'unknown')
                    <p class="hint">On a Windows server (OpenSSH Server), an administrator account uses <code>C:\ProgramData\ssh\administrators_authorized_keys</code> instead; other accounts use <code>C:\Users\{{ $server->ssh_username }}\.ssh\authorized_keys</code>.</p>
                @endif
                @if ($platform === 'windows')
                    <p class="hint">Disk, load and memory checks support Linux servers only so far; on this server they'll report Unknown. Installing the key with a password also works on Linux/Unix only.</p>
                @endif

                @if ($server->ssh_password_allowed)
                    <label for="password">Or let the app install it: {{ $server->ssh_username }}'s SSH password <span class="muted">({{ $storedPassword ? 'leave blank to use the stored one' : 'optional' }})</span></label>
                    <input type="password" id="password" name="password" autocomplete="off">
                    @if ($storedPassword)
                        <p class="hint">Left blank, the app logs in once with the password stored for {{ $server->ssh_username }} under Accounts to install the key. A password typed here is used instead and saved as the account's current password. Only sent after the host key is verified.</p>
                    @else
                        <p class="hint">Used to log in and install the key, then saved (encrypted) as the current password of {{ $server->ssh_username }} under Accounts. Only sent after the host key is verified.</p>
                    @endif
                @else
                    <p class="hint">Password login is not allowed for this server, so the app will never try a password. (Change this in the server's settings.)</p>
                @endif

                <div class="actions">
                    <button type="submit" @if (!$hostKey && $server->ssh_host_key === null) disabled @endif>Continue</button>
                    <a href="/servers/{{ $server->id }}">Cancel</a>
                </div>
            </form>
        @endif
    </div>
@endsection
