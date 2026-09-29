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
                <label for="new_role">Role</label>
                <select id="new_role" name="role">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <button type="submit">Create</button>
        </form>
        <p class="hint">You'll get a temporary password to hand out. At first sign-in the user sets their own password and an authenticator app.</p>
    </div>

    <div class="card">
        <h1>Users</h1>

        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Username</th><th>Role</th><th>Status</th><th><span class="muted">Actions</span></th></tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->username }}</td>
                        <td><span class="badge {{ $user->role }}">{{ ucfirst($user->role) }}</span></td>
                        <td class="muted">
                            @if ($user->must_change_password || !$user->hasAuthenticator())
                                Waiting for first sign-in
                            @elseif ($passwords->isExpired($user))
                                Password expired
                            @else
                                Active
                            @endif
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
                                <form method="post" action="/admin/users/{{ $user->id }}/reset-password" data-confirm="Reset {{ $user->username }}'s password? They'll be signed out and must set a new password and authenticator.">
                                    @csrf
                                    <button type="submit" class="secondary">Reset password</button>
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
