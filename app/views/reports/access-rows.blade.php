{{-- One page of access log rows (ApacheReportService::accessPage()), for the paged table in
     reports/apache-data. Needs $rows, $banServer (or null), $canBan, $protected (addresses protected
     from banning), $errors (request ID => how many error log entries it has), $errorEntries (request ID
     => those entries, up to ApacheReportService::ERRORS_PER_REQUEST, folded out under the row) and
     $listed (address => the blocklists that name it). --}}
@forelse ($rows as $request)
    @php($badge = $request->status >= 500 ? 'critical' : ($request->status >= 400 ? 'warning' : ($request->status >= 300 ? 'unknown' : 'ok')))
    @php($jails = $banServer?->bannedIn($request->client) ?? [])
    <tr>
        <td style="white-space: nowrap">{{ \App\Utils\LocalTime::format($request->requested_at, 'Y-m-d H:i:s') }}</td>
        <td style="white-space: nowrap">
            {{-- Green: protected from banning; red: banned; amber: on a public blocklist (details in the tooltip). --}}
            @php($guarded = $request->client !== null && in_array($request->client, $protected ?? [], true))
            @php($lists = $request->client !== null ? ($listed[$request->client] ?? null) : null)
            @if ($request->client)
                <code class="client-ip{{ $jails ? ' ip-banned' : '' }}{{ $guarded ? ' ip-protected' : '' }}{{ $lists && !$jails && !$guarded ? ' ip-listed' : '' }}" data-ip="{{ $request->client }}" data-jails="{{ implode(',', $jails) }}" data-protected="{{ $guarded ? '1' : '' }}"
                    title="{{ $jails ? 'Banned by fail2ban in ' . implode(', ', $jails) . ' (as of ' . \App\Utils\LocalTime::format($banServer->fail2ban_checked_at) . '). ' : '' }}{{ $guarded ? 'Protected from banning (in every jail\'s ignoreip). ' : '' }}{{ $lists ? "On $lists. " : '' }}Right-click to show only this address{{ $canBan ? ', or ban, unban or protect it' : '' }}.">{{ $request->client }}</code>
            @else
                <code>—</code>
            @endif
        </td>
        <td class="muted">{{ $request->vhost ?? basename($request->source) }}</td>
        <td class="access-request"><code @if ($request->path) class="request-path" data-path="{{ $request->path }}" title="Right-click to search for this URL" @endif>{{ trim(($request->method ?? '') . ' ' . ($request->path ?? '—')) }}</code>@if ($request->protocol) <span class="muted">{{ $request->protocol }}</span>@endif
            @if ($request->request_id && ($count = $errors[$request->request_id] ?? 0) > 0)
                <button type="button" class="link log-link log-errors" data-fold-toggle aria-expanded="false" title="Show this request's error log entries ({{ $request->request_id }})">▸ {{ $count }} {{ $count === 1 ? 'error' : 'errors' }}</button>
            @endif
        </td>
        <td><span class="badge {{ $badge }}">{{ $request->status ?: '—' }}</span></td>
        <td style="white-space: nowrap">{{ \App\Services\Checks\FileIoCheck::size($request->bytes) }}</td>
        <td style="white-space: nowrap">{{ $request->duration_ms === null ? '' : $request->duration_ms . ' ms' }}</td>
        <td class="access-agent">@if ($request->referer)<div>{{ $request->referer }}</div>@endif<div class="muted">{{ $request->agent }}</div></td>
    </tr>
    @if ($request->request_id && ($errorEntries[$request->request_id] ?? []) !== [])
        {{-- The request's error log entries (by its mod_unique_id ID), opened with its "N errors" button. --}}
        <tr class="fold-row error-fold" hidden>
            <td colspan="8">
                <table class="error-fold-table">
                    @foreach ($errorEntries[$request->request_id] as $entry)
                        @php($level = ['crash' => 'critical', 'error' => 'critical', 'warning' => 'warning', 'note' => 'unknown'][$entry->level] ?? 'unknown')
                        <tr>
                            <td style="white-space: nowrap">{{ \App\Utils\LocalTime::format($entry->logged_at, 'Y-m-d H:i:s') }}</td>
                            <td><span class="badge {{ $level }}">{{ $entry->level === 'note' ? 'Notice' : ucfirst($entry->level) }}</span></td>
                            <td class="muted">{{ basename($entry->source) }}</td>
                            <td><pre class="log-message">{{ $entry->message }}</pre></td>
                        </tr>
                    @endforeach
                </table>
                <div class="error-fold-more">
                    @if (($count = $errors[$request->request_id] ?? 0) > count($errorEntries[$request->request_id]))
                        <span class="muted">The first {{ count($errorEntries[$request->request_id]) }} of {{ $count }}.</span>
                    @endif
                    <button type="button" class="link log-link" data-paged-find="#error-log" data-value="{{ $request->request_id }}">Find them in the error log</button>
                </div>
            </td>
        </tr>
    @endif
@empty
    <tr><td colspan="8" class="muted">No requests match.</td></tr>
@endforelse
