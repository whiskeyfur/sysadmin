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
<ol class="sim-steps">
    @foreach ($trace['steps'] as $step)
        <li class="sim-{{ $step['phase'] }}"><span class="muted">{{ $step['phase'] }}</span> {{ $step['text'] }}</li>
    @endforeach
</ol>
<style>
    .sim-result { display: flex; gap: 12px; align-items: center; margin: 12px 0; overflow-wrap: anywhere; }
    .sim-steps { font-size: 13px; padding-left: 22px; }
    .sim-steps li { padding: 3px 0; overflow-wrap: anywhere; }
    .sim-steps li > span:first-child { display: inline-block; min-width: 70px; }
    .sim-cond { padding-left: 16px !important; }
</style>
