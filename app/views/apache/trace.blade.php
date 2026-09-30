{{-- A simulator result: the outcome, then every step. Needs $trace (ApacheSimulator::simulate()). --}}
@php($status = $trace['status'])
@php($badge = $status >= 500 ? 'critical' : ($status >= 400 ? 'warning' : ($status >= 300 ? 'unknown' : 'ok')))
<div class="sim-result">
    <span class="badge {{ $badge }}" style="font-size: 15px">{{ $status }}</span>
    <strong>
        @switch($trace['kind'])
            @case('file') Serves <code>{{ $trace['file'] }}</code>@if ($trace['path_info']) with PATH_INFO <code>{{ $trace['path_info'] }}</code>@endif @if ($trace['handler']) (handler {{ $trace['handler'] }})@endif @break
            @case('redirect') Redirects to <code>{{ $trace['location'] }}</code> @break
            @case('proxy') Proxied to <code>{{ $trace['location'] }}</code> @break
            @case('handler') Answered by {{ $trace['handler'] }} @break
            @case('listing') Lists the directory <code>{{ $trace['file'] }}</code> @break
            @case('forbidden') Forbidden @break
            @case('gone') Gone @break
            @case('not_found') Not found{{ $trace['file'] ? ': ' . $trace['file'] : '' }} @break
            @default Error
        @endswitch
    </strong>
</div>
@foreach ($trace['warnings'] as $warning)
    <div class="alert error" role="alert">{{ $warning }}</div>
@endforeach
<div class="sim-wrap">
    <table class="sim-steps top">
        <thead><tr><th>#</th><th>Directive</th><th>File</th><th>Line</th><th>Explanation</th></tr></thead>
        <tbody>
            @foreach ($trace['steps'] as $i => $step)
                <tr class="sim-{{ $step['phase'] }}">
                    <td class="muted">{{ $i + 1 }}</td>
                    <td>@if ($step['directive'] !== null)<code>{{ $step['directive'] }}</code>@else<span class="muted">—</span>@endif</td>
                    <td>@if ($step['file'] !== null)<code>{{ $step['file'] }}</code>@endif</td>
                    <td>{{ $step['line'] }}</td>
                    <td><span class="sim-phase">{{ $step['phase'] }}</span> {{ $step['text'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<style>
    .sim-result { display: flex; gap: 12px; align-items: center; margin: 12px 0; overflow-wrap: anywhere; }
    .sim-wrap { overflow-x: auto; }
    .sim-steps { font-size: 13px; width: 100%; border-collapse: collapse; }
    .sim-steps th, .sim-steps td { padding: 4px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
    .sim-steps td { overflow-wrap: anywhere; }
    .sim-steps td:nth-child(1), .sim-steps td:nth-child(4) { white-space: nowrap; }
    .sim-steps td:nth-child(2) { min-width: 14em; max-width: 32em; }
    .sim-steps td:nth-child(2) code { white-space: pre-wrap; }
    .sim-steps td:nth-child(3) { white-space: nowrap; }
    .sim-phase { display: inline-block; min-width: 62px; color: var(--muted); font-size: 12px; }
    .sim-cond td:nth-child(2) { padding-left: 24px; }
</style>
