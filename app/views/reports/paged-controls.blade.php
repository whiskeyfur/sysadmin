{{-- Search box, optional filters and pager above a paged table (public/assets/js/paged-table.js).
     Needs $label; $accessFilters adds the access log's client, status (2xx–5xx) and "hide localhost"
     filters, and $banFilter (the server's fail2ban bans are known) the banned / protected ones; they're
     sent as the data-paged-param names. --}}
<div class="paged-controls">
    <div class="paged-filters">
        <input type="search" class="table-filter" data-paged-search placeholder="{{ $label }}…" aria-label="{{ $label }}">
        @if (!empty($accessFilters))
            <label for="{{ \Illuminate\Support\Str::slug($label) }}-client" class="visually-hidden">Client address</label>
            <input type="text" id="{{ \Illuminate\Support\Str::slug($label) }}-client" data-paged-param="client" placeholder="Client IP (or its start)" size="18" spellcheck="false" inputmode="decimal">
            {{-- Status classes: none ticked shows every status; several, any of them. --}}
            <span class="check-group" role="group" aria-label="Status">
                @foreach ([2 => 'ok', 3 => 'unknown', 4 => 'warning', 5 => 'critical'] as $class => $badge)
                    <label class="check"><input type="checkbox" data-paged-param="s{{ $class }}xx"> <span class="badge {{ $badge }}">{{ $class }}xx</span></label>
                @endforeach
            </span>
            @if (!empty($banFilter))
                <span class="check-group" role="group" aria-label="fail2ban">
                    <label class="check" title="Only addresses fail2ban has banned"><input type="checkbox" data-paged-param="banned"> <span class="ip-banned">Banned</span></label>
                    <label class="check" title="Only addresses protected from banning"><input type="checkbox" data-paged-param="protected"> <span class="ip-protected">Protected</span></label>
                </span>
            @endif
            <label class="check"><input type="checkbox" data-paged-param="hide_local" checked> Hide localhost</label>
        @endif
    </div>
    <div class="paged-pager">
        <button type="button" class="secondary" data-paged-prev disabled>‹ Previous</button>
        <span data-paged-status class="muted"></span>
        <button type="button" class="secondary" data-paged-next disabled>Next ›</button>
    </div>
</div>
<p class="alert error" data-paged-error role="alert" hidden></p>
