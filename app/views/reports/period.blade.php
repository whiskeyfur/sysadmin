{{-- The period part of a report picker, inside its GET form: a preset, or a start and end (in the app's
     time zone; dragging across a chart fills them in too). Needs $range. --}}
@php($custom = \App\Services\HistoryReport::custom($range))
@php($periodTo = $custom ? $custom[1] : time())
@php($periodFrom = $custom ? $custom[0] : $periodTo - (\App\Services\HistoryReport::RANGES[$range] ?? \App\Services\HistoryReport::RANGES[\App\Services\HistoryReport::DEFAULT_RANGE])[1] * 3600)
<label for="range" class="visually-hidden">Period</label>
<select id="range" name="range" data-period-preset>
    @if ($custom)
        <option value="{{ $range }}" selected>Chosen period</option>
    @endif
    @foreach (\App\Services\HistoryReport::RANGES as $key => [$label])
        <option value="{{ $key }}" {{ $range === $key ? 'selected' : '' }}>{{ $label }}</option>
    @endforeach
</select>
<span class="period-dates">
    <label for="start" class="visually-hidden">Start</label>
    <input type="datetime-local" id="start" name="start" value="{{ \App\Utils\LocalTime::format((new \DateTimeImmutable())->setTimestamp($periodFrom), 'Y-m-d\TH:i') }}" title="Start">
    <span class="muted">to</span>
    <label for="end" class="visually-hidden">End</label>
    <input type="datetime-local" id="end" name="end" value="{{ \App\Utils\LocalTime::format((new \DateTimeImmutable())->setTimestamp($periodTo), 'Y-m-d\TH:i') }}" title="End">
    <button type="submit" class="secondary">Show</button>
</span>
