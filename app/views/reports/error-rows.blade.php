{{-- One page of Apache error log rows (ApacheReportService::errorPage()), for the paged table in
     reports/apache-data. Needs $rows. An entry with a request ID (mod_unique_id) links to its request in
     the access log (public/assets/js/paged-table.js, data-paged-find). --}}
@forelse ($rows as $entry)
    @php($badge = ['crash' => 'critical', 'error' => 'critical', 'warning' => 'warning', 'note' => 'unknown'][$entry->level] ?? 'unknown')
    <tr>
        <td style="white-space: nowrap">{{ \App\Utils\LocalTime::format($entry->logged_at, 'Y-m-d H:i:s') }}</td>
        {{-- Apache's own name for the level: notice (stored as "note"). --}}
        <td><span class="badge {{ $badge }}">{{ $entry->level === 'note' ? 'Notice' : ucfirst($entry->level) }}</span></td>
        <td class="muted">{{ basename($entry->source) }}</td>
        <td><pre class="log-message">{{ $entry->message }}</pre>
            @if ($entry->request_id)
                <button type="button" class="link log-link" data-paged-find="#access-log" data-value="{{ $entry->request_id }}" title="Show the request this came from ({{ $entry->request_id }})">Show request</button>
            @endif
        </td>
    </tr>
@empty
    <tr><td colspan="4" class="muted">No entries match.</td></tr>
@endforelse
