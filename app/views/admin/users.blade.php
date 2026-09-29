@extends('layouts.app')

@section('title', 'Users')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <h2>Add a user</h2>
        <form method="post" action="/admin/users" class="inline-form">
            @csrf
            <div>
                <label for="new_username">Username</label>
                <input type="text" id="new_username" name="username" autocapitalize="none" pattern="[A-Za-z0-9_]{3,32}" required>
            </div>
            <div>
                <label for="new_one_time_password">One-time password</label>
                <input type="text" id="new_one_time_password" name="one_time_password" autocomplete="off" minlength="{{ $minLength }}" required>
            </div>
            <div>
                <label for="new_role">Role</label>
                <select id="new_role" name="role">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <button type="submit">Create</button>
        </form>
        <p class="hint">Users have no passwords: they sign in with their username and an authenticator code. The one-time password (at least {{ $minLength }} characters) is only for their first sign-in, where they set up an authenticator app. Hand it over outside this website.</p>
    </div>

    <div class="card">
        <h1>Users</h1>

        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Username</th><th>Role</th><th>Status</th><th>Signs in with</th><th data-nosort><span class="muted">Actions</span></th></tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->username }}</td>
                        <td><span class="badge {{ $user->role }}">{{ ucfirst($user->role) }}</span></td>
                        <td class="muted">
                            @if ($user->must_change_password)
                                Waiting for first sign-in
                            @elseif ($methods->missingRequired($user))
                                Must set up {{ implode(', ', array_map(fn ($m) => strtolower(\App\Services\LoginMethodService::LABELS[$m]), $methods->missingRequired($user))) }}
                            @else
                                Active
                            @endif
                        </td>
                        <td>
                            @php($enrolled = $methods->enrolled($user))
                            @foreach ($enrolled as $m)
                                <span class="badge {{ in_array($m, $methods->enabled(), true) ? 'user' : 'unknown' }}" title="{{ in_array($m, $methods->enabled(), true) ? '' : 'Turned off' }}">{{ \App\Services\LoginMethodService::LABELS[$m] }}{{ $m === 'passkey' ? ' (' . $user->passkeys()->count() . ')' : '' }}</span>
                            @endforeach
                            @unless ($enrolled)
                                <span class="muted">Nothing yet</span>
                            @endunless
                        </td>
                        <td class="row-actions">
                            @if ($user->id === $auth->user->id)
                                <span class="muted">You</span>
                            @else
                                @if ($user->isAdmin())
                                    <form method="post" action="/admin/users/{{ $user->id }}/demote" data-confirm="Remove admin rights from {{ $user->username }}?">
                                        @csrf
                                        <button type="submit" class="secondary">Remove admin</button>
                                    </form>
                                @else
                                    <form method="post" action="/admin/users/{{ $user->id }}/promote" data-confirm="Make {{ $user->username }} an admin?">
                                        @csrf
                                        <button type="submit" class="secondary">Make admin</button>
                                    </form>
                                @endif
                                <form method="post" action="/admin/users/{{ $user->id }}/reset-password" class="inline-form" data-confirm="Reset {{ $user->username }}? They'll be signed out, their password, authenticator and passkeys removed, and they must set up again with this one-time password.">
                                    @csrf
                                    <input type="text" name="one_time_password" aria-label="New one-time password for {{ $user->username }}" placeholder="One-time password" autocomplete="off" minlength="{{ $minLength }}" required>
                                    <button type="submit" class="secondary">Reset sign-in</button>
                                </form>
                                <form method="post" action="/admin/users/{{ $user->id }}/delete" data-confirm="Delete {{ $user->username }}? This can't be undone.">
                                    @csrf
                                    <button type="submit" class="danger">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    <script>
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); }
            });
        });
    </script>
@endsection
