{{-- The account manager's log. Needs $changes (DbUserChange). --}}
<details class="card">
    <summary><h2 style="display: inline">Changes made here <span class="muted">({{ count($changes) }})</span></h2></summary>
    @if ($changes === [])
        <p class="muted">None yet.</p>
    @else
        <div class="table-wrap">
        <table class="top">
            <thead><tr><th>When</th><th>Who</th><th>Server</th><th>Change</th><th>Account</th><th>Outcome</th></tr></thead>
            <tbody>
                @foreach ($changes as $change)
                    <tr>
                        <td data-sort="{{ $change->created_at->getTimestamp() }}" style="white-space: nowrap">{{ \App\Utils\LocalTime::format($change->created_at) }}</td>
                        <td>{{ $change->user->username ?? '?' }}</td>
                        <td>{{ $change->server->name ?? '?' }}</td>
                        <td>{{ $change->action }}@if ($change->detail) <span class="muted">{{ $change->detail }}</span>@endif</td>
                        <td><code>{{ $change->account }}</code></td>
                        <td><span class="badge {{ $change->ok ? 'ok' : 'critical' }}">{{ $change->ok ? 'Done' : 'Failed' }}</span> <span class="muted">{{ $change->message }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</details>
