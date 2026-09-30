@extends('layouts.app')

@section('title', 'Apache reports')
@section('width', 'wide')

@section('content')
    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>Apache reports</h1>
            @include('reports.picker', ['action' => '/apache/reports'])
        </div>
        <p class="muted">From each server's Apache access and error logs, read over SSH every {{ \App\Services\ScheduledCheckService::DEFAULT_INTERVAL_MINUTES }} minutes when checks are scheduled. Access logs are kept as 5-minute totals for {{ \App\Services\HealthCheckService::RETENTION_DAYS }} days, and request by request for {{ (new \App\Services\SettingsService())->integer(\App\Services\SettingsService::APACHE_ACCESS_KEEP_DAYS) }} days (Apache settings). Hover a point for its value.</p>

        @if ($server === null)
            <p class="muted">No server has Apache monitoring turned on.</p>
        @elseif ($report['rows'] === [] && $report['log'] === [] && $report['access'] === [])
            <p class="muted">Nothing from {{ $server->name }}'s Apache logs in this period yet. <a href="/servers/{{ $server->id }}">Run checks</a> to read them.</p>
        @endif
    </div>

    @if ($server !== null && ($report['rows'] !== [] || $report['log'] !== [] || $report['access'] !== []))
        @include('reports.apache-data', ['scope' => "all of {$server->name}'s access logs", 'banServer' => $server])
    @endif
@endsection
