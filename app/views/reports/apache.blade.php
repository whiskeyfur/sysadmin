@extends('layouts.app')

@section('title', 'Apache reports')
@section('width', 'wide')

@section('content')
    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>Apache reports</h1>
            @include('reports.picker', ['action' => '/apache/reports'])
        </div>
        <p class="muted">From each server's Apache access and error logs, read over SSH every {{ \App\Services\ScheduledCheckService::DEFAULT_INTERVAL_MINUTES }} minutes when checks are scheduled. Access logs are kept as 5-minute totals, {{ \App\Services\HealthCheckService::RETENTION_DAYS }} days. Hover a point for its value.</p>

        @if ($server === null)
            <p class="muted">No server has Apache monitoring turned on.</p>
        @elseif ($report['rows'] === [] && $report['log'] === [])
            <p class="muted">Nothing from {{ $server->name }}'s Apache logs in this period yet. <a href="/servers/{{ $server->id }}">Run checks</a> to read them.</p>
        @endif
    </div>

    @if ($server !== null && ($report['rows'] !== [] || $report['log'] !== []))
        @php($from = $report['from']->getTimestamp())
        @php($to = $report['to']->getTimestamp())
        @php($per = $report['bucket_minutes'] === 5 ? '5-minute intervals' : ($report['bucket_minutes'] >= 60 ? ($report['bucket_minutes'] / 60) . '-hour intervals' : $report['bucket_minutes'] . '-minute intervals'))
        @if ($report['requests'])
            <div class="card">
                {!! \App\Utils\LineChart::render('Requests per minute', $report['requests'], $from, $to, '/min') !!}
                <p class="hint">Averaged over {{ $per }}, across all of {{ $server->name }}'s access logs.</p>
            </div>
            <div class="card">
                {!! \App\Utils\LineChart::render('Traffic (MB per minute)', $report['traffic'], $from, $to, ' MB') !!}
            </div>
        @endif
        @if ($report['workers'])
            <div class="card">
                {!! \App\Utils\LineChart::render('Busy workers', $report['workers'], $from, $to, '%', 100) !!}
                <p class="hint">From mod_status at each check.</p>
            </div>
        @endif
        @if ($report['log_counts'])
            <div class="card">
                {!! \App\Utils\LineChart::render('Error log entries per interval', $report['log_counts'], $from, $to) !!}
            </div>
        @endif

        @if ($report['rows'] !== [])
            <div class="card">
                <h2>Requests</h2>
                <p class="hint">Totals per {{ rtrim($per, 's') }}, newest first.</p>
                <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>From</th><th>Requests</th><th>2xx</th><th>3xx</th><th>4xx</th><th>5xx</th><th>Served</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $row)
                            <tr>
                                <td data-sort="{{ $row['time']->getTimestamp() }}">{{ \App\Utils\LocalTime::format($row['time']) }}</td>
                                <td>{{ $row['requests'] }}</td>
                                <td>{{ $row['status_2xx'] }}</td>
                                <td>{{ $row['status_3xx'] }}</td>
                                <td>{{ $row['status_4xx'] }}</td>
                                <td>{{ $row['status_5xx'] }}</td>
                                <td data-sort="{{ $row['bytes'] }}">{{ \App\Services\Checks\FileIoCheck::size($row['bytes']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif

        <div class="card">
            <h2>Error log</h2>
            @if ($report['log'] === [])
                <p class="muted">No error log entries in this period.</p>
            @else
                <p class="hint">Notices, warnings, errors and crashes (info and debug lines aren't kept).{{ count($report['log']) >= \App\Services\ApacheReportService::MAX_ENTRIES ? ' The newest ' . \App\Services\ApacheReportService::MAX_ENTRIES . '.' : '' }}</p>
                <div class="table-wrap">
                <table class="top">
                    <thead>
                        <tr><th>Logged</th><th>Level</th><th>Log</th><th>Message</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($report['log'] as $entry)
                            @php($badge = ['crash' => 'critical', 'error' => 'critical', 'warning' => 'warning', 'note' => 'unknown'][$entry->level] ?? 'unknown')
                            <tr>
                                <td data-sort="{{ $entry->logged_at->getTimestamp() }}" style="white-space: nowrap">{{ \App\Utils\LocalTime::format($entry->logged_at, 'Y-m-d H:i:s') }}</td>
                                <td data-sort="{{ ['note' => 0, 'warning' => 1, 'error' => 2, 'crash' => 3][$entry->level] ?? 0 }}"><span class="badge {{ $badge }}">{{ ucfirst($entry->level) }}</span></td>
                                <td class="muted">{{ basename($entry->source) }}</td>
                                <td><pre class="log-message">{{ $entry->message }}</pre></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    @endif
@endsection
