{{-- One page of access log rows (ApacheReportService::accessPage()), for the paged table in
     reports/apache-data. Needs $rows, $banServer (or null), $canBan and $protected (addresses protected
     from banning). --}}
@forelse ($rows as $request)
    @php($badge = $request->status >= 500 ? 'critical' : ($request->status >= 400 ? 'warning' : ($request->status >= 300 ? 'unknown' : 'ok')))
    @php($jails = $banServer?->bannedIn($request->client) ?? [])
    <tr>
        <td style="white-space: nowrap">{{ \App\Utils\LocalTime::format($request->requested_at, 'Y-m-d H:i:s') }}</td>
        <td style="white-space: nowrap">
            {{-- Green: protected from banning; red: banned (which jails, in the tooltip). --}}
            @php($guarded = $request->client !== null && in_array($request->client, $protected ?? [], true))
            @if ($request->client)
                <code class="client-ip{{ $jails ? ' ip-banned' : '' }}{{ $guarded ? ' ip-protected' : '' }}" data-ip="{{ $request->client }}" data-jails="{{ implode(',', $jails) }}" data-protected="{{ $guarded ? '1' : '' }}"
                    title="{{ $jails ? 'Banned by fail2ban in ' . implode(', ', $jails) . ' (as of ' . \App\Utils\LocalTime::format($banServer->fail2ban_checked_at) . '). ' : '' }}{{ $guarded ? 'Protected from banning (in every jail\'s ignoreip). ' : '' }}Right-click to show only this address{{ $canBan ? ', or ban, unban or protect it' : '' }}.">{{ $request->client }}</code>
            @else
                <code>—</code>
            @endif
        </td>
        <td class="muted">{{ $request->vhost ?? basename($request->source) }}</td>
        <td class="access-request"><code @if ($request->path) class="request-path" data-path="{{ $request->path }}" title="Right-click to search for this URL" @endif>{{ trim(($request->method ?? '') . ' ' . ($request->path ?? '—')) }}</code>@if ($request->protocol) <span class="muted">{{ $request->protocol }}</span>@endif</td>
        <td><span class="badge {{ $badge }}">{{ $request->status ?: '—' }}</span></td>
        <td style="white-space: nowrap">{{ \App\Services\Checks\FileIoCheck::size($request->bytes) }}</td>
        <td style="white-space: nowrap">{{ $request->duration_ms === null ? '' : $request->duration_ms . ' ms' }}</td>
        <td class="access-agent">@if ($request->referer)<div>{{ $request->referer }}</div>@endif<div class="muted">{{ $request->agent }}</div></td>
    </tr>
@empty
    <tr><td colspan="8" class="muted">No requests match.</td></tr>
@endforelse
