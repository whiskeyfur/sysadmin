{{-- Server and period picker for a report. Needs $servers, $server, $range and $action. --}}
@if (count($servers))
    <form method="get" action="{{ $action }}" class="row-actions">
        <label for="server" class="visually-hidden">Server</label>
        <select id="server" name="server" onchange="this.form.submit()">
            @foreach ($servers as $option)
                <option value="{{ $option->id }}" {{ $server && $option->id === $server->id ? 'selected' : '' }}>{{ $option->name }}</option>
            @endforeach
        </select>
        @include('reports.period', ['range' => $range])
    </form>
@endif
