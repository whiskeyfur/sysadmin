@extends('layouts.app')

@section('title', 'SSL reports')
@section('width', 'wide')

@section('content')
    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>SSL reports</h1>
            @if (count($certificates))
                <form method="get" action="/ssl/reports" class="row-actions">
                    <label for="certificate" class="visually-hidden">Certificate</label>
                    <select id="certificate" name="certificate" onchange="this.form.submit()">
                        <option value="">All certificates</option>
                        @foreach ($certificates as $option)
                            <option value="{{ $option->id }}" {{ $certificate && $option->id === $certificate->id ? 'selected' : '' }}>{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @include('reports.period', ['range' => $range])
                </form>
            @endif
        </div>
        <p class="muted">
            Days until each certificate expires, from each check: every {{ (new \App\Services\SettingsService())->integer(\App\Services\SettingsService::SSL_CHECK_HOURS) }} hours when checks are scheduled, and whenever someone runs them. Results are kept {{ \App\Services\SslMonitorService::RETENTION_DAYS }} days. A renewal shows as a jump up.
            @if ($certificate)
                One line per place <a href="/ssl/{{ $certificate->id }}">{{ $certificate->name }}</a> is served: a server still serving an old copy stands out.
            @else
                Each certificate's line is its soonest-expiring place.
            @endif
        </p>

        @if (count($certificates) === 0)
            <p class="muted">No certificates yet.</p>
        @elseif ($report['row_total'] === 0)
            <p class="muted">No certificate checks in this period.</p>
        @endif
    </div>

    @if ($report['row_total'] > 0)
        <div class="card">
            @php($series = $report['days'])
            @if ($series)
                {{-- The warning period as a flat line to compare against. --}}
                @php($series["Warning at $warningDays days"] = [[$report['from']->getTimestamp(), (float) $warningDays], [$report['to']->getTimestamp(), (float) $warningDays]])
            @endif
            {!! \App\Utils\LineChart::render('Days until expiry', $series, $report['from']->getTimestamp(), $report['to']->getTimestamp(), ' d') !!}
            @if ($report['bucket_minutes'])
                <p class="hint">Each point is the lowest value in {{ $report['bucket_minutes'] >= 60 ? ($report['bucket_minutes'] / 60) . ' hour' . ($report['bucket_minutes'] > 60 ? 's' : '') : $report['bucket_minutes'] . ' minutes' }}. Pick a shorter period for every check.</p>
            @endif
            @if (!$report['days'])
                <p class="hint">No certificate could be read in this period; see the checks below.</p>
            @endif
        </div>

        <div class="card">
            <h2>Checks</h2>
            @if ($report['row_total'] > count($report['rows']))
                <p class="hint">The newest {{ count($report['rows']) }} of {{ $report['row_total'] }} checks in this period.</p>
            @endif
            <div class="table-wrap">
            <table class="top">
                <thead>
                    <tr><th>Checked</th><th>Certificate</th><th>Where</th><th>Status</th><th>Days left</th><th>Result</th></tr>
                </thead>
                <tbody>
                    @foreach ($report['rows'] as $check)
                        <tr>
                            <td data-sort="{{ $check->checked_at->getTimestamp() }}" style="white-space: nowrap">{{ \App\Utils\LocalTime::format($check->checked_at) }}</td>
                            <td>
                                @if ($check->binding?->certificate)
                                    <a href="/ssl/{{ $check->binding->certificate->id }}">{{ $check->binding->certificate->name }}</a>
                                @endif
                            </td>
                            <td class="muted">{{ $check->binding?->label() }}</td>
                            <td data-sort="{{ $check->status->severity() }}"><span class="badge {{ $check->status->value }}">{{ $check->status->label() }}</span></td>
                            <td data-sort="{{ $check->days_left ?? '' }}">{{ $check->days_left === null ? '—' : floor($check->days_left) }}</td>
                            <td>{{ $check->summary }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
@endsection
