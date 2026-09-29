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
        <p class="muted">New registrations can't sign in until you approve them.</p>

        <table>
            <thead>
                <tr><th>Username</th><th>Status</th><th>Registered</th><th><span class="muted">Actions</span></th></tr>
            </thead>
            <tbody>
                @foreach ($users as $row)
                    <tr>
                        <td>{{ $row['user']->username }}</td>
                        <td><span class="badge {{ $row['role'] }}">{{ $row['role'] === 'pending' ? 'Waiting for approval' : ucfirst($row['role']) }}</span></td>
                        <td class="muted">{{ $row['user']->created_at?->format('Y-m-d H:i') }}</td>
                        <td>
                            @if ($row['role'] === 'pending')
                                <form method="post" action="/admin/users/{{ $row['user']->id }}/approve">
                                    @csrf
                                    <input type="hidden" name="role" value="user">
                                    <button type="submit">Approve</button>
                                </form>
                                <form method="post" action="/admin/users/{{ $row['user']->id }}/approve">
                                    @csrf
                                    <input type="hidden" name="role" value="admin">
                                    <button type="submit" class="secondary">Approve as admin</button>
                                </form>
                                <form method="post" action="/admin/users/{{ $row['user']->id }}/reject">
                                    @csrf
                                    <button type="submit" class="danger">Reject</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
