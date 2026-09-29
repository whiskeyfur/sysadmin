@extends('layouts.app')

@section('title', $account->username)

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <div>
                <h1>{{ $account->username }}</h1>
                <p class="muted">{{ $account->serviceLabel() }} · {{ $account->typeLabel() }} account{{ $account->homeServer ? ' on ' . $account->homeServer->name : '' }}</p>
            </div>
            <a class="button secondary-link" href="/admin/accounts/{{ $account->id }}/edit">Edit</a>
        </div>

        <table>
            <tbody>
                <tr><th>Origin</th><td>{{ $account->originLabel() }}</td></tr>
                <tr><th>Password</th><td>
                    {{ $account->hasPassword() ? 'Stored (encrypted)' : 'None recorded' }}
                    @if (!$account->canLogIn())
                        <div class="hint">Imported without a password, so it can't be used to log into a server until you record one below.</div>
                    @endif
                </td></tr>
                <tr><th>Last reset</th><td>{{ \App\Utils\LocalTime::format($account->password_changed_at, 'Y-m-d') ?: '—' }}</td></tr>
                <tr><th>Rotation</th><td>{{ $account->rotation_days ? 'Every ' . $account->rotation_days . ' days' : 'None' }}</td></tr>
                <tr><th>Due</th><td>@include('accounts.due', ['status' => $service->status($account)])</td></tr>
                @if ($account->notes)
                    <tr><th>Notes</th><td style="white-space: pre-line">{{ $account->notes }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Used on</h2>
        @if ($account->servers->isEmpty())
            <p class="muted">No servers use this account yet. Choose it as a server's SSH or database login when editing the server.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead><tr><th>Server</th><th>Used for</th><th>App last logged in</th></tr></thead>
                <tbody>
                    @foreach ($account->servers as $server)
                        <tr>
                            @php($uses = array_keys(array_filter(['SSH' => $server->ssh_account_id === $account->id, 'Database' => $server->mysql_account_id === $account->id])))
                            <td><a href="/servers/{{ $server->id }}">{{ $server->name }}</a></td>
                            <td>{{ $uses ? implode(', ', $uses) : 'No longer used' }}</td>
                            <td class="muted">{{ $server->pivot->last_used_at ? \App\Utils\LocalTime::format(\Carbon\Carbon::parse($server->pivot->last_used_at, 'UTC')) : 'Not yet' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Current password</h2>
        @if ($revealed !== null)
            <label for="revealed">Password</label>
            <input type="text" id="revealed" value="{{ $revealed }}" readonly onfocus="this.select()">
            <p class="hint">This reveal was logged. Leave the page when you're done.</p>
        @elseif ($account->hasPassword())
            <p class="muted">Confirm it's you to reveal it (an authenticator code works once: wait for the next one after signing in).</p>
            @include('partials.confirm', ['formId' => 'reveal', 'action' => '/admin/accounts/' . $account->id . '/reveal', 'what' => 'Reveal'])
            <p class="hint">Every reveal is logged with who and when.</p>
            <script src="/assets/js/passkeys.js"></script>
        @else
            <p class="muted">No password recorded.</p>
        @endif

        <h2 style="margin-top: 24px">Record a new password</h2>
        <form method="post" action="/admin/accounts/{{ $account->id }}/password">
            @csrf
            <div class="grid-2">
                <div>
                    <label for="password">New password</label>
                    <input type="password" id="password" name="password" autocomplete="new-password" required>
                </div>
                <div>
                    <label for="password_changed_at">Reset on <span class="muted">(default today)</span></label>
                    <input type="text" id="password_changed_at" name="password_changed_at" placeholder="YYYY-MM-DD">
                </div>
            </div>
            <p class="hint">After you've changed it on the server or in the directory. This only records it; it doesn't change it anywhere.</p>
            <div class="actions"><button type="submit">Record</button></div>
        </form>
    </div>

    <div class="card">
        <h2>Reveal history</h2>
        @if (count($reveals) === 0)
            <p class="muted">Never revealed.</p>
        @else
            <ul>
                @foreach ($reveals as $reveal)
                    <li>{{ \App\Utils\LocalTime::format($reveal->revealed_at) }}: {{ $reveal->user?->username ?? 'deleted user' }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <form method="post" action="/admin/accounts/{{ $account->id }}/delete" data-confirm="Stop tracking {{ $account->username }}? Its stored password and reveal history are deleted.">
        @csrf
        <button type="submit" class="danger">Delete account</button>
        <a href="{{ $account->serviceName() === 'mysql' ? '/admin/accounts/mariadb' : '/admin/accounts/ssh' }}">Back to {{ $account->serviceName() === 'mysql' ? 'database' : 'SSH' }} accounts</a>
    </form>

    <script>
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); }
            });
        });
    </script>
@endsection
