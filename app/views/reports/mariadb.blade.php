@extends('layouts.app')

@section('title', 'MariaDB reports')
@section('width', 'wide')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>MariaDB reports</h1>
            <div class="row-actions">
                @include('reports.picker', ['action' => '/mariadb/reports'])
                @if ($canImport)
                    <form method="post" action="/servers/{{ $server->id }}/import-log">
                        @csrf
                        <button type="submit" class="secondary" title="Read MariaDB's option files and logs on {{ $server->name }} over SSH">Import log</button>
                    </form>
                @endif
            </div>
        </div>
        <p class="muted">Database health from each MariaDB check run: every {{ \App\Services\ScheduledCheckService::DEFAULT_INTERVAL_MINUTES }} minutes when checks are scheduled, and whenever someone runs them. Results are kept {{ \App\Services\HealthCheckService::RETENTION_DAYS }} days. Hover a point for its value.</p>

        @if ($server === null)
            <p class="muted">No server has MariaDB monitoring turned on.</p>
        @elseif ($report['rows'] === [])
            <p class="muted">No MariaDB checks ran on {{ $server->name }} in this period. <a href="/servers/{{ $server->id }}">Run checks</a> to start collecting data.</p>
        @endif
    </div>

    @if (is_array($import))
        <div class="card">
            <h2>Log import</h2>
            <ul class="steps">
                @foreach ($import['configuration'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
                @foreach ($import['sources'] as $source)
                    <li class="{{ $source['problem'] ? 'failed' : 'ok' }}">{{ $source['label'] }} ({{ $source['where'] }}): {{ $source['read'] }} entr{{ $source['read'] === 1 ? 'y' : 'ies' }} in the last {{ \App\Services\HealthCheckService::RETENTION_DAYS }} days, {{ $source['imported'] }} new.{{ $source['problem'] ? ' ' . $source['problem'] : '' }}</li>
                @endforeach
            </ul>
        </div>
    @endif

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
        @if ($report['disk'])
            <div class="card">
                {!! \App\Utils\LineChart::render('Disk space used, per filesystem (read through MariaDB)', $report['disk'], $from, $to, '%', 100) !!}
                <p class="hint">From MariaDB's DISKS plugin: no SSH needed. Judged with the disk levels in SSH settings.</p>
            </div>
        @endif
        @if ($report['sizes'])
            <div class="card">
                {!! \App\Utils\LineChart::render('Database size (MB)', $report['sizes'], $from, $to, ' MB') !!}
                <p class="hint">Data and indexes, from information_schema; InnoDB updates these figures as tables change, so they trail recent writes.{{ count($report['sizes']) >= 6 ? ' The largest 6 databases; the table has the total.' : '' }}</p>
            </div>
        @endif
        @if ($report['io'])
            <div class="card">
                {!! \App\Utils\LineChart::render('File I/O (MB per hour)', $report['io'], $from, $to, ' MB/h') !!}
                <p class="hint">From performance_schema, between one check and the next.</p>
            </div>
        @endif

        <div class="card">
            <h2>Data</h2>
            @if ($report['bucket_minutes'])
                <p class="hint">{{ count($report['rows']) }} intervals: each chart point and row is the average of the runs in {{ $report['bucket_minutes'] >= 60 ? ($report['bucket_minutes'] / 60) . ' hour' . ($report['bucket_minutes'] > 60 ? 's' : '') : $report['bucket_minutes'] . ' minutes' }} (crashed tables: the most in each). Pick a shorter period for every run.</p>
            @endif
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Checked</th><th>Connections</th><th>Peak</th><th>Buffer pool</th><th>Replication lag</th><th>Crashed tables</th><th>Uptime</th><th>Fullest disk</th><th>Databases</th><th>I/O read / written</th></tr>
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
                            <td data-sort="{{ $row['disk'] ?? '' }}">{{ $row['disk'] === null ? '—' : $row['disk'] . '%' }}</td>
                            <td data-sort="{{ $row['db_size_mb'] ?? '' }}">{{ $row['db_size_mb'] === null ? '—' : $row['db_size_mb'] . ' MB' }}</td>
                            <td data-sort="{{ ($row['io_read'] ?? 0) + ($row['io_write'] ?? 0) }}">{{ $row['io_read'] === null ? '—' : $row['io_read'] . ' / ' . $row['io_write'] . ' MB/h' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif

    @if ($server !== null)
        <div class="card">
            <h2>Log</h2>
            @php($importEvery = (new \App\Services\SettingsService())->integer(\App\Services\SettingsService::MYSQL_LOG_IMPORT_MINUTES))
            @if ($server->mysql_enabled && $server->sshReady())
                <p class="hint">
                    {{ $importEvery > 0 ? "Imported automatically every $importEvery minutes by the scheduled checks." : 'Automatic imports are off (MariaDB settings).' }}
                    @if ($server->log_imported_at)
                        Last import {{ \App\Utils\LocalTime::format($server->log_imported_at) }}: {{ $server->log_import_message }}
                    @endif
                </p>
            @endif
            @if ($report['log_total'] === 0)
                <p class="muted">
                    No imported log entries in this period.
                    @if ($canImport && !$server->log_imported_at)
                        Use Import log to read {{ $server->name }}'s MariaDB logs.
                    @elseif (!$server->sshReady())
                        Importing the log needs SSH set up on {{ $server->name }}.
                    @endif
                </p>
            @else
                <p class="muted">From MariaDB's own logs on {{ $server->name }}: {{ number_format($report['log_total']) }} {{ $report['log_total'] === 1 ? 'entry' : 'entries' }} in this period, newest first, {{ \App\Services\HistoryReport::PAGE_SIZE }} a page. Search matches the message, level or source.</p>
                @if ($report['log_counts'])
                    {!! \App\Utils\LineChart::render('Log entries per ' . ($report['log_bucket_minutes'] >= 1440 ? 'day' : ($report['log_bucket_minutes'] / 60) . ' hour' . ($report['log_bucket_minutes'] > 60 ? 's' : '')), $report['log_counts'], $report['from']->getTimestamp(), $report['to']->getTimestamp()) !!}
                @endif
                {{-- Paged in the browser (public/assets/js/paged-table.js), from /mariadb/reports/entries. --}}
                <div class="paged" style="margin-top: 16px" data-paged="/mariadb/reports/entries?{{ http_build_query(['server' => $server->id, 'range' => $range]) }}">
                    @include('reports.paged-controls', ['label' => 'Search the log'])
                    <div class="paged-wrap">
                    <table class="top">
                        <thead>
                            <tr><th data-sort-key="time" aria-sort="descending">Logged</th><th data-sort-key="level">Level</th><th data-sort-key="source">Source</th><th>Message</th></tr>
                        </thead>
                        <tbody><tr><td colspan="4" class="muted">Loading…</td></tr></tbody>
                    </table>
                    </div>
                </div>
                <script src="{{ \App\Utils\Asset::url('/assets/js/paged-table.js') }}"></script>
            @endif
        </div>
    @endif
@endsection
