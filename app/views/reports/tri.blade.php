{{-- A tri-state filter (public/assets/js/paged-table.js): click cycles any → only these (✓, "in") → not
     these (✗, "out"). Needs $param, $label (HTML) and $name; $state starts it at "in" or "out". --}}
@php($state = $state ?? '')
<button type="button" class="tri" data-paged-param="{{ $param }}" data-state="{{ $state }}" data-name="{{ $name }}"
    title="{{ $name }}: {{ ['in' => 'only these', 'out' => 'not these'][$state] ?? 'any' }} (click to change)" aria-label="{{ $name }}: {{ ['in' => 'only these', 'out' => 'not these'][$state] ?? 'any' }}"><span class="tri-box" aria-hidden="true"></span> {!! $label !!}</button>
