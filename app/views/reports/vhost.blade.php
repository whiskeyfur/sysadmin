@extends('layouts.app')

@section('title', 'Vhost reports')
@section('width', 'wide')

@section('content')
    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>Vhost reports</h1>
            @if (count($vhosts))
                <form method="get" action="/vhosts/reports" class="row-actions">
                    <label for="vhost" class="visually-hidden">Virtual host</label>
                    <select id="vhost" name="vhost" onchange="this.form.submit()">
                        @foreach (collect($vhosts)->groupBy(fn ($v) => $v->server->name ?? '') as $serverName => $group)
                            <optgroup label="{{ $serverName }}">
                                @foreach ($group as $option)
                                    <option value="{{ $option->id }}" {{ $vhost && $option->id === $vhost->id ? 'selected' : '' }}>{{ $option->label() }}{{ $option->port ? ':' . $option->port : '' }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @include('reports.period', ['range' => $range])
                </form>
            @endif
        </div>

        @if ($vhost === null)
            <p class="muted">No virtual hosts found yet. They're read from each server's Apache configuration: turn on Apache monitoring and run its checks. See the <a href="/vhosts">list</a>.</p>
        @else
            <p class="muted">
                {{ $vhost->label() }} on <a href="/servers/{{ $vhost->server->id }}">{{ $vhost->server->name }}</a>, <code>{{ $vhost->address }}</code>{{ $vhost->ssl ? ', SSL' : '' }}.
                From {{ count($logs['access']) === 1 ? 'the access log' : 'the access logs' }} {{ implode(', ', $logs['access']) ?: 'none' }} and error log {{ implode(', ', $logs['error']) ?: 'none' }}.
            </p>
            @if ($logs['shared'])
                <p class="hint">Shared with other hosts, so their requests and errors are counted here too: {{ implode(', ', $logs['shared']) }}. Give the vhost its own CustomLog / ErrorLog for figures of its own.</p>
            @endif
            @if ($report['rows'] === [] && $report['log'] === [] && $report['access'] === [])
                <p class="muted">Nothing from these logs in this period yet.</p>
            @endif
        @endif
    </div>

    @if ($vhost !== null && ($report['rows'] !== [] || $report['log'] !== [] || $report['access'] !== []))
        @include('reports.apache-data', ['scope' => 'its access logs', 'banServer' => $vhost->server])
    @endif
@endsection
