{{-- Server and period picker for a report. Needs $servers, $server, $range and $action. --}}
@if (count($servers))
    <form method="get" action="{{ $action }}" class="row-actions">
        <label for="server" class="visually-hidden">Server</label>
        <select id="server" name="server" onchange="this.form.submit()">
            @foreach ($servers as $option)
                <option value="{{ $option->id }}" {{ $server && $option->id === $server->id ? 'selected' : '' }}>{{ $option->name }}</option>
            @endforeach
        </select>
        <label for="range" class="visually-hidden">Period</label>
        <select id="range" name="range" onchange="this.form.submit()">
            @foreach (\App\Services\HistoryReport::RANGES as $key => [$label])
                <option value="{{ $key }}" {{ $range === $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <noscript><button type="submit">Show</button></noscript>
    </form>
@endif
