{{-- One page of imported MariaDB log rows (MariadbReportService::logPage()), for the paged table in
     reports/mariadb. Needs $rows. --}}
@forelse ($rows as $entry)
    @php($badge = ['crash' => 'critical', 'error' => 'critical', 'warning' => 'warning', 'slow' => 'warning', 'note' => 'unknown'][$entry->level] ?? 'unknown')
    <tr>
        <td style="white-space: nowrap">{{ \App\Utils\LocalTime::format($entry->logged_at, 'Y-m-d H:i:s') }}</td>
        <td><span class="badge {{ $badge }}">{{ ucfirst($entry->level) }}</span></td>
        <td class="muted">{{ ['error' => 'Error log', 'journal' => 'Journal', 'slow' => 'Slow log'][$entry->source] ?? $entry->source }}</td>
        <td><pre class="log-message">{{ $entry->message }}</pre></td>
    </tr>
@empty
    <tr><td colspan="4" class="muted">No entries match.</td></tr>
@endforelse
