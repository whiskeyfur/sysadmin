@extends('layouts.app')

@section('title', 'SSH reports')
@section('width', 'wide')

@section('content')
    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>SSH reports</h1>
            @if (count($servers))
                <form method="get" action="/ssh/reports" class="row-actions">
                    <label for="server" class="visually-hidden">Server</label>
                    <select id="server" name="server" onchange="this.form.submit()">
                        @foreach ($servers as $option)
                            <option value="{{ $option->id }}" {{ $server && $option->id === $server->id ? 'selected' : '' }}>{{ $option->name }}</option>
                        @endforeach
                    </select>
                    <label for="range" class="visually-hidden">Period</label>
                    <select id="range" name="range" onchange="this.form.submit()">
                        @foreach (\App\Services\SshReportService::RANGES as $key => [$label])
                            <option value="{{ $key }}" {{ $range === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit">Show</button></noscript>
                </form>
            @endif
        </div>
        <p class="muted">Disk, load and memory from each SSH check run. Runs happen when someone runs checks (SSH › Test); results are kept {{ \App\Services\HealthCheckService::RETENTION_DAYS }} days. Hover a point for its value.</p>

        @if ($server === null)
            <p class="muted">No server has SSH monitoring turned on.</p>
        @elseif ($report['rows'] === [])
            <p class="muted">No SSH checks ran on {{ $server->name }} in this period. <a href="/servers/{{ $server->id }}">Run checks</a> to start collecting data.</p>
        @endif
    </div>

    @if ($server !== null && $report['rows'] !== [])
        @php($from = $report['from']->getTimestamp())
        @php($to = $report['to']->getTimestamp())
        <div class="card">
            {!! \App\Utils\LineChart::render('Disk space used, per filesystem', $report['disk'], $from, $to, '%', 100) !!}
        </div>
        <div class="card">
            {!! \App\Utils\LineChart::render('Load average', $report['load'], $from, $to) !!}
            @php($cores = collect($report['rows'])->pluck('cores')->filter()->last())
            @if ($cores)
                <p class="hint">{{ $server->name }} has {{ $cores }} core{{ $cores === 1 ? '' : 's' }}: a load of {{ $cores }} keeps every core busy.</p>
            @endif
        </div>
        <div class="card">
            {!! \App\Utils\LineChart::render('Memory and swap used', $report['memory'], $from, $to, '%', 100) !!}
        </div>

        <div class="card">
            <h2>Data</h2>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Checked</th><th>Fullest disk</th><th>Load 1 / 5 / 15 min</th><th>Load per core</th><th>Memory</th><th>Swap</th></tr>
                </thead>
                <tbody>
                    @foreach (array_reverse($report['rows']) as $row)
                        <tr>
                            <td data-sort="{{ $row['time']->getTimestamp() }}">{{ \App\Utils\LocalTime::format($row['time']) }}</td>
                            <td data-sort="{{ $row['disk'] ?? '' }}">{{ $row['disk'] === null ? '—' : $row['disk'] . '%' }}@if ($row['disk_mount']) <span class="hint">{{ $row['disk_mount'] }}</span>@endif</td>
                            <td data-sort="{{ $row['load5'] ?? '' }}">{{ $row['load1'] === null ? '—' : $row['load1'] . ' / ' . $row['load5'] . ' / ' . $row['load15'] }}</td>
                            <td data-sort="{{ $row['per_core'] ?? '' }}">{{ $row['per_core'] ?? '—' }}</td>
                            <td data-sort="{{ $row['memory'] ?? '' }}">{{ $row['memory'] === null ? '—' : $row['memory'] . '%' }}</td>
                            <td data-sort="{{ $row['swap'] ?? '' }}">{{ $row['swap'] === null ? '—' : $row['swap'] . '%' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
@endsection
