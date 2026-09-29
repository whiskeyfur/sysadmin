{{-- One kind of health result in the servers overview. Needs $enabled, $result (HealthCheckService::summary() entry or null) and $pending. --}}
@if (!$enabled)
    <span class="muted">—</span>
@elseif ($result === null)
    <span class="muted">{{ $pending }}</span>
@else
    <span class="badge {{ $result['status']->value }}">{{ $result['status']->label() }}</span>
    @if ($result['issues'] === [])
        <div class="hint">All {{ $result['count'] }} checks OK</div>
    @else
        @foreach ($result['issues'] as $issue)
            <div class="hint">{{ \Illuminate\Support\Str::limit($issue, 120) }}</div>
        @endforeach
    @endif
@endif
