{{-- One page of the Apache report's requests table (ApacheReportService::dailyPage()): a row per day, and
     under it, folded, a row per hour (a click on the day's row opens it: data-fold-row). Needs $rows. --}}
@php($size = fn ($bytes) => \App\Services\Checks\FileIoCheck::size($bytes))
@forelse ($rows as $day)
    <tr data-fold-row class="day-row">
        <td style="white-space: nowrap">
            <button type="button" class="link day-toggle" data-fold-toggle aria-expanded="false" title="Show each hour">▸ {{ $day['date'] }}</button>
            <span class="muted">{{ $day['day']->format('D') }}</span>
        </td>
        <td>{{ number_format($day['requests']) }}</td>
        <td>{{ number_format($day['status_2xx']) }}</td>
        <td>{{ number_format($day['status_3xx']) }}</td>
        <td>{{ number_format($day['status_4xx']) }}</td>
        <td>{{ number_format($day['status_5xx']) }}</td>
        <td>{{ $size($day['bytes']) }}</td>
    </tr>
    <tr class="fold-row hour-fold" hidden>
        <td colspan="7">
            <table class="hour-table">
                <thead><tr><th>Hour</th><th>Requests</th><th>2xx</th><th>3xx</th><th>4xx</th><th>5xx</th><th>Served</th></tr></thead>
                <tbody>
                    @foreach ($day['hours'] as $hour)
                        <tr @if ($hour['requests'] === 0) class="muted" @endif>
                            <td style="white-space: nowrap">{{ $hour['hour']->format('H:00') }}–{{ $hour['hour']->copy()->addHour()->format('H:00') }}</td>
                            <td>{{ number_format($hour['requests']) }}</td>
                            <td>{{ number_format($hour['status_2xx']) }}</td>
                            <td>{{ number_format($hour['status_3xx']) }}</td>
                            <td>{{ number_format($hour['status_4xx']) }}</td>
                            <td>{{ number_format($hour['status_5xx']) }}</td>
                            <td>{{ $size($hour['bytes']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </td>
    </tr>
@empty
    <tr><td colspan="7" class="muted">No days match.</td></tr>
@endforelse
