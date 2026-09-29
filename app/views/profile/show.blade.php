@extends('layouts.app')

@section('title', 'Profile')


@section('content')
    @php($label = fn (string $m) => \App\Services\LoginMethodService::LABELS[$m])
    @php($level = fn (string $m) => $levels[$m] ?? 0)
    @php($required = fn (string $m) => $level($m) === \App\Services\LoginMethodService::REQUIRED)
    @php($methods = new \App\Services\LoginMethodService())

    <div class="card">
        <h1>How you sign in</h1>
        <p class="muted">Signed in as {{ $user->username }}. You sign in with your username and any one of the methods below that you've set up.</p>

        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif
        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif

        @if ($user->must_change_password)
            <div class="alert notice" role="status">
                Welcome! Set up how you'll sign in from now on{{ $missing ? ': ' . implode(' and ', array_map(fn ($m) => strtolower($label($m)), $missing)) . ' ' . (count($missing) === 1 ? 'is' : 'are') . ' required' : '' }}.
                Then finish setup; your one-time password stops working.
            </div>
        @elseif ($missing)
            <div class="alert notice" role="status">An admin made {{ implode(' and ', array_map(fn ($m) => strtolower($label($m)), $missing)) }} required: set it up to continue.</div>
        @endif
    </div>

    @unless ($confirmed)
        <div class="card">
            <h2>Confirm it's you</h2>
            <p class="muted">To change how you sign in, prove it's you again (you signed in more than {{ intdiv(\App\Services\AuthSessionService::CONFIRMED_SECONDS, 60) }} minutes ago).</p>
            @include('partials.confirm', ['formId' => 'confirm', 'action' => '/profile/confirm', 'what' => 'Confirm'])
        </div>
    @endunless

    @if ($level('password'))
        <div class="card" id="password">
            <h2>{{ $label('password') }} <span class="badge {{ $required('password') ? 'warning' : 'unknown' }}">{{ $required('password') ? 'Required' : 'Optional' }}</span></h2>
            @if ($user->hasPassword())
                <p>Set{{ $user->password_changed_at ? ' ' . \App\Utils\LocalTime::format($user->password_changed_at) : '' }}.</p>
            @else
                <p class="muted">Not set up.</p>
            @endif
            @if ($confirmed)
                <form method="post" action="/profile/password">
                    @csrf
                    <input type="text" name="username" value="{{ $user->username }}" autocomplete="username" hidden>
                    <label for="new_password">{{ $user->hasPassword() ? 'New password' : 'Password' }}</label>
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="{{ \App\Services\PasswordService::USER_MIN_LENGTH }}" required>
                    <label for="new_password_again">Again</label>
                    <input type="password" id="new_password_again" name="new_password_again" autocomplete="new-password" required>
                    <p class="hint">At least {{ \App\Services\PasswordService::USER_MIN_LENGTH }} characters; not a commonly used password. A few unrelated words work well.</p>
                    <div class="actions">
                        <button type="submit">{{ $user->hasPassword() ? 'Change password' : 'Set password' }}</button>
                    </div>
                </form>
                @if ($user->hasPassword() && $methods->canRemove($user, 'password'))
                    <form method="post" action="/profile/password/remove" class="inline-form">
                        @csrf
                        <button type="submit" class="secondary danger">Remove password</button>
                    </form>
                @endif
            @endif
        </div>
    @endif

    @if ($level('authenticator'))
        <div class="card" id="authenticator">
            <h2>{{ $label('authenticator') }} <span class="badge {{ $required('authenticator') ? 'warning' : 'unknown' }}">{{ $required('authenticator') ? 'Required' : 'Optional' }}</span></h2>
            @if ($pendingSecret !== null && $confirmed)
                <form method="post" action="/profile/authenticator">
                    @csrf
                    <p>Scan this code with Google Authenticator or another authenticator app, then enter the 6-digit code it shows.</p>
                    <img class="qr" src="{{ $qr }}" alt="QR code for your authenticator app">
                    <p class="hint">Can't scan it? Enter this key manually: <code>{{ trim(chunk_split($pendingSecret, 4, ' ')) }}</code></p>
                    <label for="code">Code from the app</label>
                    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
                    <div class="actions">
                        <button type="submit">{{ $user->hasAuthenticator() ? 'Replace authenticator' : 'Set up authenticator' }}</button>
                    </div>
                </form>
            @else
                <p class="{{ $user->hasAuthenticator() ? '' : 'muted' }}">{{ $user->hasAuthenticator() ? 'Set up.' : 'Not set up.' }}</p>
                @if ($confirmed)
                    <div class="row-actions">
                        <form method="post" action="/profile/authenticator/start" class="inline-form">
                            @csrf
                            <button type="submit" class="{{ $user->hasAuthenticator() ? 'secondary' : '' }}">{{ $user->hasAuthenticator() ? 'Replace (new phone)' : 'Set up authenticator' }}</button>
                        </form>
                        @if ($user->hasAuthenticator() && $methods->canRemove($user, 'authenticator'))
                            <form method="post" action="/profile/authenticator/remove" class="inline-form">
                                @csrf
                                <button type="submit" class="secondary danger">Remove</button>
                            </form>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    @endif

    @if ($level('passkey'))
        <div class="card" id="passkeys">
            <h2>{{ $label('passkey') }} <span class="badge {{ $required('passkey') ? 'warning' : 'unknown' }}">{{ $required('passkey') ? 'Required' : 'Optional' }}</span></h2>
            <p class="muted">A passkey on your phone or computer, or a hardware security key (YubiKey and the like). Signing in with one checks your PIN, fingerprint or face.</p>
            @if (count($passkeys))
                <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Kind</th><th>Added</th><th>Last used</th><th data-nosort></th></tr></thead>
                    <tbody>
                        @foreach ($passkeys as $passkey)
                            <tr>
                                <td>{{ $passkey->name }}<div class="muted">{{ $passkey->rp_id }}</div></td>
                                <td>{{ $passkey->backup_eligible ? 'Synced passkey' : 'On one device or key' }}</td>
                                <td data-sort="{{ $passkey->created_at->getTimestamp() }}">{{ \App\Utils\LocalTime::format($passkey->created_at, 'Y-m-d') }}</td>
                                <td data-sort="{{ $passkey->last_used_at?->getTimestamp() ?? 0 }}">{{ $passkey->last_used_at ? \App\Utils\LocalTime::format($passkey->last_used_at) : 'Never' }}</td>
                                <td class="row-actions">
                                    @if ($confirmed && (count($passkeys) > 1 || $methods->canRemove($user, 'passkey')))
                                        <form method="post" action="/profile/passkeys/{{ $passkey->id }}/remove" class="inline-form">
                                            @csrf
                                            <button type="submit" class="secondary danger">Remove</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @else
                <p class="muted">None yet.</p>
            @endif
            @if ($confirmed)
                @if ($passkeysHere)
                    <form>
                        @csrf
                        <label for="passkey_name">Name for the new one</label>
                        <input type="text" id="passkey_name" maxlength="100" placeholder="e.g. Work laptop, YubiKey">
                        <div class="actions">
                            <button type="button" data-passkey-register>Add a passkey or security key</button>
                        </div>
                        <p class="passkey-message alert error" role="alert" hidden></p>
                    </form>
                @else
                    <p class="hint">Passkeys can only be added over https (or on localhost).</p>
                @endif
            @endif
        </div>
    @endif

    @if ($user->must_change_password)
        <div class="card">
            <form method="post" action="/profile/finish">
                @csrf
                <button type="submit" {{ $setupDone ? '' : 'disabled' }}>Finish setup</button>
                @unless ($setupDone)
                    <p class="hint">Set up {{ $missing ? implode(' and ', array_map(fn ($m) => strtolower($label($m)), $missing)) : 'at least one way to sign in' }} first.</p>
                @endunless
            </form>
        </div>
    @endif

    <script src="/assets/js/passkeys.js"></script>
@endsection
