@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @if ($needsSecondAdmin)
        <div class="alert warn" role="status">
            Setup isn't finished: sys needs at least {{ $minimumAdmins }} admins so nobody gets locked out.
            @if ($auth->isAdmin())
                Share the key file with a co-worker, then approve their registration as an admin on the <a href="/admin/users">Users</a> page.
            @else
                Ask an admin to add a second admin.
            @endif
        </div>
    @endif

    <div class="card">
        <h1>Welcome, {{ $auth->user->username }}</h1>
        <p class="muted">Signed in as {{ $auth->isAdmin() ? 'an admin' : 'a user' }}. Monitoring comes next.</p>
    </div>

    @if ($auth->isAdmin())
        <div class="card">
            <h2>Key file</h2>
            <p>New users need the key file to register. Share it with co-workers directly, never through this website. Anyone with the key file and an approved account can read all data.</p>
            <form method="post" action="/admin/key-file">
                @csrf
                <button type="submit">Download key file</button>
            </form>
        </div>
    @endif
@endsection
