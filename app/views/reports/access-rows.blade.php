{{-- One page of access log rows (ApacheReportService::accessPage()), for the paged table in
     reports/apache-data. Needs $rows, $banServer (or null) and $canBan. --}}
@forelse ($rows as $request)
    @php($badge = $request->status >= 500 ? 'critical' : ($request->status >= 400 ? 'warning' : ($request->status >= 300 ? 'unknown' : 'ok')))
    @php($jails = $banServer?->bannedIn($request->client) ?? [])
    <tr>
        <td style="white-space: nowrap">{{ \App\Utils\LocalTime::format($request->requested_at, 'Y-m-d H:i:s') }}</td>
        <td style="white-space: nowrap">
            @if ($request->client && $canBan)
                <code class="client-ip" data-ip="{{ $request->client }}" data-jails="{{ implode(',', $jails) }}" title="Right-click to ban or unban with fail2ban">{{ $request->client }}</code>
            @else
                <code>{{ $request->client ?? '—' }}</code>
            @endif
            @if ($jails)
                <span class="badge critical" title="Banned by fail2ban as of {{ \App\Utils\LocalTime::format($banServer->fail2ban_checked_at) }}">banned: {{ implode(', ', $jails) }}</span>
            @endif
        </td>
        <td class="muted">{{ $request->vhost ?? basename($request->source) }}</td>
        <td class="access-request"><code>{{ trim(($request->method ?? '') . ' ' . ($request->path ?? '—')) }}</code>@if ($request->protocol) <span class="muted">{{ $request->protocol }}</span>@endif</td>
        <td><span class="badge {{ $badge }}">{{ $request->status ?: '—' }}</span></td>
        <td style="white-space: nowrap">{{ \App\Services\Checks\FileIoCheck::size($request->bytes) }}</td>
        <td style="white-space: nowrap">{{ $request->duration_ms === null ? '' : $request->duration_ms . ' ms' }}</td>
        <td class="access-agent">@if ($request->referer)<div>{{ $request->referer }}</div>@endif<div class="muted">{{ $request->agent }}</div></td>
    </tr>
@empty
    <tr><td colspan="8" class="muted">No requests match.</td></tr>
@endforelse
