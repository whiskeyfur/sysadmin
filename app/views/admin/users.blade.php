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
        <h1>Users</h1>
        <p class="muted">New registrations can't sign in until you approve them. There must always be at least 2 admins.</p>

        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Username</th><th>Status</th><th>Registered</th><th><span class="muted">Actions</span></th></tr>
            </thead>
            <tbody>
                @foreach ($users as $row)
                    @php($user = $row['user'])
                    @php($role = $row['role'])
                    <tr>
                        <td>{{ $user->username }}</td>
                        <td><span class="badge {{ $role }}">{{ $role === 'pending' ? 'Waiting for approval' : ucfirst($role) }}</span></td>
                        <td class="muted">{{ $user->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="row-actions">
                            @if ($user->id === $auth->user->id)
                                <span class="muted">You</span>
                            @elseif ($role === 'pending')
                                <form method="post" action="/admin/users/{{ $user->id }}/approve">
                                    @csrf
                                    <input type="hidden" name="role" value="user">
                                    <button type="submit">Approve</button>
                                </form>
                                <form method="post" action="/admin/users/{{ $user->id }}/approve">
                                    @csrf
                                    <input type="hidden" name="role" value="admin">
                                    <button type="submit" class="secondary">Approve as admin</button>
                                </form>
                                <form method="post" action="/admin/users/{{ $user->id }}/reject" data-confirm="Reject {{ $user->username }}'s registration? Their account will be deleted.">
                                    @csrf
                                    <button type="submit" class="danger">Reject</button>
                                </form>
                            @else
                                @if ($role === 'user')
                                    <form method="post" action="/admin/users/{{ $user->id }}/promote" data-confirm="Make {{ $user->username }} an admin?">
                                        @csrf
                                        <button type="submit" class="secondary">Make admin</button>
                                    </form>
                                @else
                                    <form method="post" action="/admin/users/{{ $user->id }}/demote" data-confirm="Remove admin rights from {{ $user->username }}?">
                                        @csrf
                                        <button type="submit" class="secondary">Remove admin</button>
                                    </form>
                                @endif
                                <form method="post" action="/admin/users/{{ $user->id }}/reset-password" data-confirm="Reset {{ $user->username }}'s password? They'll be signed out and must set a new password and authenticator.">
                                    @csrf
                                    <button type="submit" class="secondary">Reset password</button>
                                </form>
                                <a class="button danger-link" href="/admin/users/{{ $user->id }}/delete">Delete…</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <h2>Master key</h2>
        <p>Replace the master key if you think the key file has leaked, or after rejecting someone who had it. Everyone else will need the new key file. Deleting a user replaces it automatically.</p>
        <a class="button secondary-link" href="/admin/key/rotate">Replace master key…</a>
    </div>

    <script>
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); }
            });
        });
    </script>
@endsection
