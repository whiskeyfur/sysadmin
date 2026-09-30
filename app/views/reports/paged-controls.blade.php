{{-- Search box, optional filters and pager above a paged table (public/assets/js/paged-table.js).
     Needs $label; $statuses (the status codes in the period) adds the access log's status, client and
     "hide localhost" filters, and $banFilter (the server's fail2ban bans are known) the banned one;
     they're sent as the data-paged-param names. --}}
<div class="paged-controls">
    <div class="paged-filters">
        <input type="search" class="table-filter" data-paged-search placeholder="{{ $label }}…" aria-label="{{ $label }}">
        @isset($statuses)
            <label for="{{ \Illuminate\Support\Str::slug($label) }}-status" class="visually-hidden">Status</label>
            <select id="{{ \Illuminate\Support\Str::slug($label) }}-status" data-paged-param="status" title="Status">
                <option value="">Any status</option>
                <option value="errors">Errors (4xx and 5xx)</option>
                @foreach (['2xx' => 'Success (2xx)', '3xx' => 'Redirects (3xx)', '4xx' => 'Client errors (4xx)', '5xx' => 'Server errors (5xx)'] as $value => $name)
                    <option value="{{ $value }}">{{ $name }}</option>
                @endforeach
                @if ($statuses)
                    <optgroup label="In this period">
                        @foreach ($statuses as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </optgroup>
                @endif
            </select>
            <label for="{{ \Illuminate\Support\Str::slug($label) }}-client" class="visually-hidden">Client address</label>
            <input type="text" id="{{ \Illuminate\Support\Str::slug($label) }}-client" data-paged-param="client" placeholder="Client IP (or its start)" size="18" spellcheck="false" inputmode="decimal">
            @if (!empty($banFilter))
                <label for="{{ \Illuminate\Support\Str::slug($label) }}-banned" class="visually-hidden">fail2ban</label>
                <select id="{{ \Illuminate\Support\Str::slug($label) }}-banned" data-paged-param="banned" title="Banned by fail2ban">
                    <option value="">Banned or not</option>
                    <option value="yes">Banned by fail2ban</option>
                    <option value="no">Not banned</option>
                </select>
            @endif
            <label class="check"><input type="checkbox" data-paged-param="hide_local" checked> Hide localhost</label>
        @endisset
    </div>
    <div class="paged-pager">
        <button type="button" class="secondary" data-paged-prev disabled>‹ Previous</button>
        <span data-paged-status class="muted"></span>
        <button type="button" class="secondary" data-paged-next disabled>Next ›</button>
    </div>
</div>
<p class="alert error" data-paged-error role="alert" hidden></p>
