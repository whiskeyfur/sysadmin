{{-- Search box, optional filters and pager above a paged table (public/assets/js/paged-table.js).
     Needs $label; $accessFilters adds the access log's client box and tri-state status (2xx–5xx) and
     localhost filters (reports/tri; localhost starts excluded), and $banFilter (the server's fail2ban bans
     are known) the banned / protected ones; they're sent as the data-paged-param names. --}}
<div class="paged-controls">
    <div class="paged-filters">
        <input type="search" class="table-filter" data-paged-search placeholder="{{ $label }}…" aria-label="{{ $label }}">
        @if (!empty($accessFilters))
            <label for="{{ \Illuminate\Support\Str::slug($label) }}-client" class="visually-hidden">Client address</label>
            <input type="text" id="{{ \Illuminate\Support\Str::slug($label) }}-client" data-paged-param="client" placeholder="Client IP (or its start)" size="18" spellcheck="false" inputmode="decimal">
            {{-- Tri-state filters (click to cycle): any, only these (✓), not these (✗). --}}
            <span class="check-group" role="group" aria-label="Status">
                @foreach ([2 => 'ok', 3 => 'unknown', 4 => 'warning', 5 => 'critical'] as $class => $badge)
                    @include('reports.tri', ['param' => "s{$class}xx", 'label' => "<span class=\"badge $badge\">{$class}xx</span>", 'name' => "{$class}xx"])
                @endforeach
            </span>
            @if (!empty($banFilter))
                <span class="check-group" role="group" aria-label="fail2ban">
                    @include('reports.tri', ['param' => 'banned', 'label' => '<span class="ip-banned">Banned</span>', 'name' => 'Banned'])
                    @include('reports.tri', ['param' => 'protected', 'label' => '<span class="ip-protected">Protected</span>', 'name' => 'Protected'])
                </span>
            @endif
            @include('reports.tri', ['param' => 'listed', 'label' => '<span class="ip-listed">Listed</span>', 'name' => 'On a blocklist'])
            @include('reports.tri', ['param' => 'local', 'label' => 'Localhost', 'name' => 'Localhost', 'state' => 'out'])
        @endif
    </div>
    <div class="paged-pager">
        <button type="button" class="secondary" data-paged-prev disabled>‹ Previous</button>
        <span data-paged-status class="muted"></span>
        <button type="button" class="secondary" data-paged-next disabled>Next ›</button>
    </div>
</div>
<p class="alert error" data-paged-error role="alert" hidden></p>
