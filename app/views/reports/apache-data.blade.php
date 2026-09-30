{{-- Charts and tables of an ApacheReportService report. Needs $report, $range, $scope (which logs, for the hint),
     $banServer (the server, for fail2ban) and $pagedParams (server or vhost id, for the paged log tables). --}}
        @php($from = $report['from']->getTimestamp())
        @php($to = $report['to']->getTimestamp())
        @php($per = $report['bucket_minutes'] === 5 ? '5-minute intervals' : ($report['bucket_minutes'] >= 60 ? ($report['bucket_minutes'] / 60) . '-hour intervals' : $report['bucket_minutes'] . '-minute intervals'))
        @if ($report['requests'])
            <div class="card">
                {!! \App\Utils\LineChart::render('Requests per minute', $report['requests'], $from, $to, '/min') !!}
                <p class="hint">Averaged over {{ $per }}, across {{ $scope }}.</p>
            </div>
            <div class="card">
                {!! \App\Utils\LineChart::render('Traffic (MB per minute)', $report['traffic'], $from, $to, ' MB') !!}
            </div>
        @endif
        @if ($report['workers'])
            <div class="card">
                {!! \App\Utils\LineChart::render('Busy workers', $report['workers'], $from, $to, '%', 100) !!}
                <p class="hint">From mod_status at each check.</p>
            </div>
        @endif
        @if ($report['log_counts'])
            <div class="card">
                {!! \App\Utils\LineChart::render('Error log entries per interval', $report['log_counts'], $from, $to) !!}
            </div>
        @endif

        @if ($report['rows'] !== [])
            <div class="card">
                <h2>Requests</h2>
                <p class="hint">Totals per {{ rtrim($per, 's') }}, newest first.</p>
                <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>From</th><th>Requests</th><th>2xx</th><th>3xx</th><th>4xx</th><th>5xx</th><th>Served</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $row)
                            <tr>
                                <td data-sort="{{ $row['time']->getTimestamp() }}">{{ \App\Utils\LocalTime::format($row['time']) }}</td>
                                <td>{{ $row['requests'] }}</td>
                                <td>{{ $row['status_2xx'] }}</td>
                                <td>{{ $row['status_3xx'] }}</td>
                                <td>{{ $row['status_4xx'] }}</td>
                                <td>{{ $row['status_5xx'] }}</td>
                                <td data-sort="{{ $row['bytes'] }}">{{ \App\Services\Checks\FileIoCheck::size($row['bytes']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif

        @php($banServer = $banServer ?? null)
        @php($canBan = $banServer !== null && ($auth ?? null)?->isAdmin() && $banServer->sshReady())
        {{-- Paged in the browser (public/assets/js/paged-table.js): each page, search and sort is fetched
             from /apache/reports/entries, since these logs grow large. --}}
        @php($pagedQuery = http_build_query(['range' => $range] + $pagedParams))
        <div class="card">
            <h2>Access log <span class="muted">({{ number_format($report['access_total']) }})</span></h2>
            @if ($report['access_total'] === 0)
                <p class="muted">No requests stored for this period.
                    @if (($keep = (new \App\Services\SettingsService())->integer(\App\Services\SettingsService::APACHE_ACCESS_KEEP_DAYS)) === 0)
                        Storing requests is turned off in the Apache settings.
                    @else
                        Requests are kept {{ $keep }} {{ $keep === 1 ? 'day' : 'days' }} (Apache settings).
                    @endif
                </p>
            @else
                <p class="hint">Each request as logged, read in the format its CustomLog names; newest first, {{ \App\Services\ApacheReportService::PAGE_SIZE }} a page. Search matches client, host, request, referer, user agent or a status; the client box matches a whole address exactly, or a partial one from its start (e.g. 10.0.0.). The status, fail2ban and Localhost boxes each cycle through any, only these (✓) and not these (✗); local requests (127.* and ::1) start excluded.
                    @php($blocklists = (new \App\Services\BlocklistService())->status())
                    Addresses on a public blocklist are <code class="ip-listed">amber</code> ({{ collect(\App\Services\BlocklistService::SOURCES)->map(fn ($source, $name) => $source['label'] . (isset($blocklists[$name]['entries']) && $blocklists[$name]['entries'] > 0 ? ', ' . number_format($blocklists[$name]['entries']) . ' entries, ' . \App\Utils\LocalTime::format(\Carbon\Carbon::createFromTimestamp($blocklists[$name]['fetched_at'])) : ': not downloaded yet'))->implode('; ') }}; downloaded daily, checked here, nothing sent).
                    @if ($banServer?->fail2ban_checked_at)
                        Addresses fail2ban had banned at {{ \App\Utils\LocalTime::format($banServer->fail2ban_checked_at) }} are <code class="ip-banned">red</code>, protected ones <code class="ip-protected">green</code> (hover for details).
                    @elseif ($banServer?->fail2ban_message)
                        {{ $banServer->fail2ban_message }}
                    @endif
                    Right-click a client address to show only its requests{{ $canBan ? ', to ban or unban it with fail2ban on ' . $banServer->name . ', or to protect it from banning' : '' }}; right-click a URL to search for it.
                </p>
                <div class="paged" id="access-log" data-paged="/apache/reports/entries?kind=access&amp;{{ $pagedQuery }}">
                    @include('reports.paged-controls', ['label' => 'Search requests', 'accessFilters' => true, 'banFilter' => $banServer?->fail2ban_bans !== null])
                    <div class="paged-wrap">
                    <table class="top">
                        <thead>
                            <tr><th data-sort-key="time" aria-sort="descending">Time</th><th data-sort-key="client">Client</th><th data-sort-key="host">Host</th><th data-sort-key="request">Request</th><th data-sort-key="status">Status</th><th data-sort-key="size">Size</th><th data-sort-key="took">Took</th><th>Referer / user agent</th></tr>
                        </thead>
                        <tbody><tr><td colspan="8" class="muted">Loading…</td></tr></tbody>
                    </table>
                    </div>
                </div>
            @endif
        </div>
        @if ($report['access_total'] > 0)
            {{-- Right-click a client address (client-menu.js); ban items for admins (fail2ban.js). --}}
            <div id="client-menu" class="ban-menu" role="menu" hidden>
                <button type="button" role="menuitem" data-client-action="filter">Show only requests from <span data-client-ip></span></button>
                @if ($canBan)
                    <button type="button" role="menuitem" data-ban-action="ban">Ban <span data-client-ip></span> with fail2ban…</button>
                    <button type="button" role="menuitem" data-ban-action="unban">Unban <span data-client-ip></span>…</button>
                    <button type="button" role="menuitem" data-ban-action="protect">Protect <span data-client-ip></span> from banning…</button>
                    <button type="button" role="menuitem" data-ban-action="unprotect">Remove protection from <span data-client-ip></span>…</button>
                @endif
            </div>
            <div id="path-menu" class="ban-menu" role="menu" hidden>
                <button type="button" role="menuitem" data-path-search="">Search for <span></span></button>
                <button type="button" role="menuitem" data-path-search="">Search for <span></span></button>
            </div>
            <script src="{{ \App\Utils\Asset::url('/assets/js/client-menu.js') }}"></script>
        @endif
        @if ($canBan)
            <dialog id="ban-dialog" class="ban-dialog" data-server="{{ $banServer->id }}" data-server-name="{{ $banServer->name }}">
                <h2 id="ban-title"></h2>
                <p class="muted" id="ban-where"></p>
                <label for="ban-jail">Jail</label>
                <select id="ban-jail"><option>Loading…</option></select>
                <p class="alert error" id="ban-error" role="alert" hidden></p>
                <p class="alert notice" id="ban-done" role="status" hidden></p>
                <div class="actions">
                    <button type="button" id="ban-go" disabled></button>
                    <button type="button" class="secondary" id="ban-cancel">Close</button>
                </div>
            </dialog>
            <script src="{{ \App\Utils\Asset::url('/assets/js/fail2ban.js') }}"></script>
        @endif
        <style>
            .client-ip, .request-path { cursor: context-menu; }
            code.ip-banned, span.ip-banned { color: var(--error); background: var(--error-bg); font-weight: 600; }
            code.ip-protected, span.ip-protected { color: var(--notice); background: var(--notice-bg); font-weight: 600; }
            code.ip-listed, span.ip-listed { color: var(--warn); background: var(--warn-bg); font-weight: 600; }
            span.ip-local { color: var(--notice); background: var(--notice-bg); font-weight: 600; }
            span.ip-banned, span.ip-protected, span.ip-listed, span.ip-local { padding: 1px 6px; border-radius: 4px; }
            .ban-menu button span { display: inline-block; max-width: 40ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; font-family: ui-monospace, monospace; font-size: 13px; }
            .ban-menu { position: fixed; z-index: 50; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 6px 18px rgba(0, 0, 0, .2); padding: 4px 0; display: flex; flex-direction: column; }
            .ban-menu button { background: none; border: 0; text-align: left; padding: 7px 14px; font: inherit; color: var(--text); cursor: pointer; }
            .ban-menu button:hover, .ban-menu button:focus { background: var(--code-bg); }
            .ban-dialog { width: min(440px, calc(100vw - 32px)); border: 1px solid var(--line); border-radius: 10px; background: var(--panel); color: var(--text); padding: 24px; }
            .ban-dialog::backdrop { background: rgba(0, 0, 0, .45); }
            .ban-dialog h2 { margin-top: 0; }
            td.access-request, td.access-agent { overflow-wrap: anywhere; }
            td.access-request { min-width: 16em; }
            button.log-link { font-size: 12px; margin-left: 6px; white-space: nowrap; border: 0; background: none; padding: 0; }
            button.log-link.log-errors { color: var(--error); }
            tr.error-fold > td { background: var(--error-bg); padding: 8px 12px; }
            table.error-fold-table { width: 100%; border-collapse: collapse; }
            table.error-fold-table td { border: 0; padding: 3px 8px 3px 0; vertical-align: top; }
            table.error-fold-table td:not(:last-child) { width: 1%; white-space: nowrap; padding-right: 14px; }
            .error-fold-more { margin-top: 4px; font-size: 12px; }
            .error-fold-more button.log-link { margin-left: 0; }
            td.access-agent { font-size: 12px; min-width: 14em; max-width: 28em; }
        </style>

        <div class="card">
            <h2>Error log <span class="muted">({{ number_format($report['log_total']) }})</span></h2>
            @if ($report['log_total'] === 0)
                <p class="muted">No error log entries in this period.</p>
            @else
                <p class="hint">Notices, warnings, errors and crashes (info and debug lines aren't kept); newest first, {{ \App\Services\ApacheReportService::PAGE_SIZE }} a page. Search matches the message, level or log file.</p>
                <div class="paged" id="error-log" data-paged="/apache/reports/entries?kind=errors&amp;{{ $pagedQuery }}">
                    @include('reports.paged-controls', ['label' => 'Search error log'])
                    <div class="paged-wrap">
                    <table class="top">
                        <thead>
                            <tr><th data-sort-key="time" aria-sort="descending">Logged</th><th data-sort-key="level">Level</th><th data-sort-key="log">Log</th><th>Message</th></tr>
                        </thead>
                        <tbody><tr><td colspan="4" class="muted">Loading…</td></tr></tbody>
                    </table>
                    </div>
                </div>
            @endif
        </div>
        <script src="{{ \App\Utils\Asset::url('/assets/js/paged-table.js') }}"></script>
