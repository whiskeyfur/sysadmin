@extends('layouts.app')

@section('title', 'MariaDB reports')
@section('width', 'wide')

@section('content')
    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>MariaDB reports</h1>
            @include('reports.picker', ['action' => '/mariadb/reports'])
        </div>
        <p class="muted">Database health from each MariaDB check run: every {{ \App\Services\ScheduledCheckService::DEFAULT_INTERVAL_MINUTES }} minutes when checks are scheduled, and whenever someone runs them. Results are kept {{ \App\Services\HealthCheckService::RETENTION_DAYS }} days. Hover a point for its value.</p>

        @if ($server === null)
            <p class="muted">No server has MariaDB monitoring turned on.</p>
        @elseif ($report['rows'] === [])
            <p class="muted">No MariaDB checks ran on {{ $server->name }} in this period. <a href="/servers/{{ $server->id }}">Run checks</a> to start collecting data.</p>
        @endif
    </div>

    @if ($server !== null && $report['rows'] !== [])
        @php($from = $report['from']->getTimestamp())
        @php($to = $report['to']->getTimestamp())
        @php($max = collect($report['rows'])->pluck('max_connections')->filter()->last())
        <div class="card">
            {!! \App\Utils\LineChart::render('Connections in use', $report['connections'], $from, $to, '%', 100) !!}
            @if ($max)
                <p class="hint">Percent of max_connections ({{ $max }}). The counts are in the table below.</p>
            @endif
        </div>
        <div class="card">
            @php($hitRatios = array_column($report['buffer_pool'] ? reset($report['buffer_pool']) : [], 1))
            {!! \App\Utils\LineChart::render('InnoDB buffer pool: reads served from memory', $report['buffer_pool'], $from, $to, '%', 100, $hitRatios ? max(0, floor(min($hitRatios) / 5) * 5 - 5) : 0) !!}
            <p class="hint">Since the server started. Low values mean many reads go to disk: the buffer pool may be too small.</p>
        </div>
        @if ($report['lag'])
            <div class="card">
                {!! \App\Utils\LineChart::render('Replication lag', $report['lag'], $from, $to, ' s') !!}
            </div>
        @endif
        @if (collect($report['crashed'] ? reset($report['crashed']) : [])->contains(fn ($p) => $p[1] > 0))
            <div class="card">
                {!! \App\Utils\LineChart::render('Crashed tables', $report['crashed'], $from, $to) !!}
            </div>
        @endif
        <div class="card">
            {!! \App\Utils\LineChart::render('Uptime', $report['uptime'], $from, $to, ' d') !!}
            <p class="hint">A drop to zero is a restart.</p>
        </div>

        <div class="card">
            <h2>Data</h2>
            @if ($report['bucket_minutes'])
                <p class="hint">{{ count($report['rows']) }} intervals: each chart point and row is the average of the runs in {{ $report['bucket_minutes'] >= 60 ? ($report['bucket_minutes'] / 60) . ' hour' . ($report['bucket_minutes'] > 60 ? 's' : '') : $report['bucket_minutes'] . ' minutes' }} (crashed tables: the most in each). Pick a shorter period for every run.</p>
            @endif
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Checked</th><th>Connections</th><th>Peak</th><th>Buffer pool</th><th>Replication lag</th><th>Crashed tables</th><th>Uptime</th></tr>
                </thead>
                <tbody>
                    @foreach (array_reverse($report['rows']) as $row)
                        <tr>
                            <td data-sort="{{ $row['time']->getTimestamp() }}">{{ \App\Utils\LocalTime::format($row['time']) }}</td>
                            <td data-sort="{{ $row['connections'] ?? '' }}">
                                @if ($row['connections'] === null)
                                    —
                                @else
                                    {{ $row['connected'] !== null ? $row['connected'] . ' of ' . $row['max_connections'] : '' }} <span class="hint">{{ $row['connections'] }}%</span>
                                @endif
                            </td>
                            <td data-sort="{{ $row['peak'] ?? '' }}">{{ $row['peak'] ?? '—' }}</td>
                            <td data-sort="{{ $row['buffer_pool'] ?? '' }}">{{ $row['buffer_pool'] === null ? '—' : $row['buffer_pool'] . '%' }}</td>
                            <td data-sort="{{ $row['lag'] ?? '' }}">{{ $row['lag'] === null ? '—' : $row['lag'] . ' s' }}</td>
                            <td data-sort="{{ $row['crashed'] ?? '' }}">{{ $row['crashed'] ?? '—' }}</td>
                            <td data-sort="{{ $row['uptime_days'] ?? '' }}">{{ $row['uptime_days'] === null ? '—' : $row['uptime_days'] . ' d' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
@endsection
