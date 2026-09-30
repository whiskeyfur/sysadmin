{{-- Search box and pager above a paged table (public/assets/js/paged-table.js). Needs $label. --}}
<div class="paged-controls">
    <input type="search" class="table-filter" data-paged-search placeholder="{{ $label }}…" aria-label="{{ $label }}">
    <div class="paged-pager">
        <button type="button" class="secondary" data-paged-prev disabled>‹ Previous</button>
        <span data-paged-status class="muted"></span>
        <button type="button" class="secondary" data-paged-next disabled>Next ›</button>
    </div>
</div>
<p class="alert error" data-paged-error role="alert" hidden></p>
