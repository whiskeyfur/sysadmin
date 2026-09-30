<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') · sys</title>
    <style>
        :root {
            --bg: #f6f7f9; --panel: #ffffff; --line: #dfe3e8; --text: #1b1f24; --muted: #5b6470;
            --accent: #2457c5; --accent-hover: #1c469f; --accent-text: #ffffff;
            --error: #b42318; --error-bg: #fdecea; --notice: #1d6b3a; --notice-bg: #e8f5ec;
            --warn: #8a5a00; --warn-bg: #fff4d6; --code-bg: #eef1f5;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1216; --panel: #171b21; --line: #2a3039; --text: #e6e9ee; --muted: #9aa4b1;
                --accent: #6b9bff; --accent-hover: #8fb2ff; --accent-text: #0f1216;
                --error: #ff8a80; --error-bg: #3a1a18; --notice: #7bd89a; --notice-bg: #15301f;
                --warn: #ffd166; --warn-bg: #33290f; --code-bg: #20262e;
            }
        }
        * { box-sizing: border-box; }
        /* Elements with their own display (grids, flex) must still hide. */
        [hidden] { display: none !important; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 24px; border-bottom: 1px solid var(--line); background: var(--panel); position: sticky; top: 0; z-index: 45; }
        header .brand { font-weight: 700; letter-spacing: .02em; color: inherit; text-decoration: none; }
        header nav { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        header .brand { margin-right: 8px; }
        header nav.primary { flex: 1; }
        header .nav-toggle { display: none; }
        @media (max-width: 700px) {
            /* Phones: one row (brand, menu button); the menus open as a panel under it. */
            header { flex-wrap: wrap; padding: 8px 16px; gap: 8px; }
            header .nav-toggle { display: inline-flex; margin-left: auto; padding: 6px 12px; }
            header:not(.open) nav.primary, header:not(.open) nav.secondary { display: none; }
            header.open { max-height: 100vh; overflow-y: auto; }
            header.open nav.primary, header.open nav.secondary { flex-basis: 100%; flex-direction: column; align-items: stretch; gap: 4px; }
            header.open nav.secondary { border-top: 1px solid var(--line); padding-top: 8px; }
            header.open details.menu summary { padding: 6px 0; }
            header.open details.menu .menu-items { position: static; box-shadow: none; border: 0; padding: 0 0 6px 14px; min-width: 0; background: none; }
            header.open nav.secondary a { padding: 4px 0; }
            header.open nav.secondary form button { width: 100%; }
        }
        header nav a[aria-current="page"], header nav summary.current { font-weight: 700; text-decoration: underline; text-underline-offset: 4px; }
        button.link { background: none; border: 0; padding: 0; color: var(--accent); font: inherit; font-size: 13px; cursor: pointer; }
        button.fold::before { content: '▸ '; }
        button.fold[aria-expanded="true"]::before { content: '▾ '; }
        tr.fold-row td { background: var(--code-bg); padding-top: 6px; padding-bottom: 6px; }
        pre.log-message { margin: 0; white-space: pre-wrap; word-break: break-word; font: 12px/1.45 ui-monospace, monospace; max-height: 12em; overflow: auto; }
        figure.chart { margin: 0; }
        figure.chart figcaption { font-weight: 600; margin-bottom: 8px; }
        svg.chart { display: block; width: 100%; height: auto; }
        svg.chart .grid { stroke: currentColor; opacity: .12; }
        svg.chart .axis { fill: currentColor; opacity: .6; font-size: 12px; }
        figure.chart .legend { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 6px; font-size: 13px; }
        /* Paged tables (public/assets/js/paged-table.js, reports/paged-controls). */
        .paged-wrap { overflow-x: auto; }
        .paged-wrap table { width: 100%; }
        .paged th[data-sort-key] { cursor: pointer; user-select: none; white-space: nowrap; }
        .paged th[data-sort-key]::after { content: ' ↕'; opacity: .35; }
        .paged th[aria-sort="ascending"]::after { content: ' ▲'; opacity: .8; }
        .paged th[aria-sort="descending"]::after { content: ' ▼'; opacity: .8; }
        .paged.loading tbody { opacity: .5; }
        .paged-controls { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .paged-filters { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; }
        .paged-filters input.table-filter { max-width: 260px; margin: 0; }
        .paged-filters select, .paged-filters input[type=text] { width: auto; margin: 0; }
        .paged-filters .check-group { display: inline-flex; gap: 10px; align-items: center; padding: 4px 10px; border: 1px solid var(--line); border-radius: 6px; }
        .paged-filters label.check { display: inline-flex; gap: 6px; align-items: center; margin: 0; font-weight: normal; font-size: 14px; white-space: nowrap; }
        .paged-filters button.tri { display: inline-flex; gap: 6px; align-items: center; background: none; border: 0; padding: 2px 0; margin: 0; font: inherit; font-size: 14px; color: var(--text); cursor: pointer; white-space: nowrap; }
        .paged-filters .tri-box { width: 15px; height: 15px; border: 1.5px solid var(--muted); border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; line-height: 1; font-weight: 700; }
        .paged-filters .tri[data-state="in"] .tri-box { border-color: var(--notice); background: var(--notice-bg); color: var(--notice); }
        .paged-filters .tri[data-state="in"] .tri-box::after { content: '✓'; }
        .paged-filters .tri[data-state="out"] .tri-box { border-color: var(--error); background: var(--error-bg); color: var(--error); }
        .paged-filters .tri[data-state="out"] .tri-box::after { content: '✗'; }
        .paged-filters .tri:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }
        .paged-pager { display: flex; align-items: center; gap: 8px; font-size: 13px; }
        figure.chart .legend .legend-item { background: none; border: 0; padding: 0; margin: 0; font: inherit; color: inherit; cursor: pointer; display: inline-flex; align-items: center; }
        figure.chart .legend .legend-item[aria-pressed="false"] { text-decoration: line-through; opacity: .45; }
        figure.chart .legend .legend-item:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        svg.chart .series.hidden { display: none; }
        figure.chart .legend .chart-hint { margin-left: auto; color: var(--muted); font-size: 12px; }
        svg.chart[data-from] { cursor: crosshair; touch-action: pan-y; user-select: none; }
        svg.chart .zoom-band { fill: var(--accent, #2563eb); fill-opacity: .15; stroke: var(--accent, #2563eb); stroke-opacity: .6; }
        .period-dates { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .period-dates input { width: auto; }
        figure.chart .legend i { display: inline-block; width: 12px; height: 3px; margin-right: 6px; vertical-align: middle; border-radius: 2px; }
        .visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        details.menu { position: relative; }
        details.menu summary { cursor: pointer; list-style: none; color: var(--accent); }
        details.menu summary::-webkit-details-marker { display: none; }
        details.menu summary::after { content: ' ▾'; font-size: 11px; }
        details.menu .menu-items { position: absolute; top: calc(100% + 8px); left: 0; z-index: 10; min-width: 150px; display: flex; flex-direction: column; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 6px 0; box-shadow: 0 6px 18px rgba(0, 0, 0, .15); }
        details.menu .menu-items a { padding: 6px 14px; text-decoration: none; }
        details.menu .menu-items a:hover { background: var(--code-bg); }
        details.menu .menu-divider { border: 0; border-top: 1px solid var(--line); margin: 6px 0; }
        /* Section navigator (scrollspy), built by the script below on pages with enough sections. */
        nav.spy { position: fixed; right: 12px; top: 12px; z-index: 40; width: 220px; max-height: calc(100vh - 24px); overflow: auto; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 6px 18px rgba(0, 0, 0, .12); font-size: 13px; }
        nav.spy .spy-toggle { display: none; width: 100%; background: none; border: 0; padding: 8px 12px; font: inherit; font-weight: 600; color: var(--text); text-align: left; cursor: pointer; }
        nav.spy ol { list-style: none; margin: 0; padding: 6px 0; }
        nav.spy a { display: block; padding: 4px 12px 4px 10px; border-left: 3px solid transparent; color: var(--muted); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        nav.spy a:hover { color: var(--text); background: var(--code-bg); }
        nav.spy a.active { color: var(--accent); border-left-color: var(--accent); font-weight: 600; background: var(--code-bg); }
        nav.spy.collapsed { width: auto; }
        nav.spy.collapsed .spy-toggle { display: block; }
        nav.spy.collapsed:not(.open) ol { display: none; }
        nav.spy.collapsed.open { width: 240px; }
        @media print { nav.spy { display: none; } }
        main { max-width: 960px; margin: 0 auto; padding: 32px 16px; }
        main.narrow { max-width: 440px; }
        main.wide { max-width: 1440px; }
        table.top td { vertical-align: top; }
        input.table-filter { width: 100%; max-width: 280px; margin: 0 0 10px; padding: 7px 10px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--text); font: inherit; }
        th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        th.sortable::after { content: ' ↕'; opacity: .35; }
        th[aria-sort="ascending"]::after { content: ' ▲'; opacity: 1; }
        th[aria-sort="descending"]::after { content: ' ▼'; opacity: 1; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 24px; }
        .card + .card { margin-top: 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 17px; margin: 0 0 12px; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); }
        label { display: block; font-weight: 600; margin: 16px 0 6px; }
        input[type=text], input[type=password], input[type=number], select, textarea { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--text); font: inherit; }
        textarea { font: 12px/1.5 ui-monospace, monospace; }
        input:focus-visible, button:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .hint { font-size: 13px; color: var(--muted); margin-top: 4px; }
        button, .button { display: inline-block; padding: 9px 16px; border: 0; border-radius: 6px; background: var(--accent); color: var(--accent-text); font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        button:hover, .button:hover { background: var(--accent-hover); }
        button.secondary { background: transparent; color: var(--accent); border: 1px solid var(--line); }
        button.danger, .button.danger-link { background: transparent; color: var(--error); border: 1px solid var(--line); }
        button.danger-solid { background: var(--error); color: var(--panel); }
        .button.secondary-link { background: transparent; color: var(--accent); border: 1px solid var(--line); }
        .button.danger-link:hover, .button.secondary-link:hover, button.secondary:hover, button.danger:hover { background: var(--bg); }
        .row-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .row-actions button, .row-actions .button { padding: 5px 10px; font-size: 13px; }
        /* A flex table cell loses its place in the row (misaligned borders): keep cells as cells. */
        td.row-actions { display: table-cell; }
        td.row-actions > * { display: inline-block; margin: 3px 6px 3px 0; vertical-align: middle; }
        .table-wrap { overflow-x: auto; }
        button:disabled, button:disabled:hover { opacity: .45; cursor: not-allowed; }
        .inline-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .inline-form label { margin-top: 0; }
        .badge.user { background: var(--code-bg); }
        .badge.ok { background: var(--notice-bg); color: var(--notice); }
        .badge.failed { background: var(--error-bg); color: var(--error); }
        .badge.untested, .badge.warning { background: var(--warn-bg); color: var(--warn); }
        .badge.critical { background: var(--error-bg); color: var(--error); }
        .badge.unknown { background: var(--code-bg); color: var(--muted); }
        .history { display: inline-flex; gap: 2px; }
        .dot { width: 8px; height: 16px; border-radius: 2px; background: var(--line); }
        .dot.ok { background: var(--notice); }
        .dot.warning { background: var(--warn); }
        .dot.critical { background: var(--error); }
        .dot.unknown { background: var(--muted); }
        .pubkey { display: block; padding: 10px; background: var(--code-bg); border-radius: 6px; font: 12px/1.5 ui-monospace, monospace; word-break: break-all; }
        fieldset { border: 1px solid var(--line); border-radius: 8px; padding: 4px 16px 16px; margin: 20px 0 0; }
        legend { font-weight: 600; padding: 0 6px; }
        .check { display: flex; gap: 8px; align-items: center; font-weight: 600; margin-top: 12px; }
        .steps { padding-left: 20px; }
        .steps li { margin: 6px 0; }
        .steps li.ok::marker { content: '✓  '; color: var(--notice); }
        .steps li.failed::marker { content: '✗  '; color: var(--error); }
        .grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 0 12px; }
        .actions { margin-top: 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
        .alert.error { background: var(--error-bg); color: var(--error); }
        .alert.notice { background: var(--notice-bg); color: var(--notice); }
        .alert.warn { background: var(--warn-bg); color: var(--warn); }
        a { color: var(--accent); }
        code { background: var(--code-bg); padding: 2px 6px; border-radius: 4px; font-size: 13px; word-break: break-all; }
        .qr { display: block; width: 200px; height: 200px; margin: 12px 0; background: #fff; padding: 8px; border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; }
        th { font-size: 13px; color: var(--muted); font-weight: 600; }
        td form { display: inline; }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--code-bg); }
        .badge.admin { background: var(--notice-bg); color: var(--notice); }
    </style>
</head>
<body>
    @php($path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')
    <header>
        <a class="brand" href="/">sys</a>
        @isset($auth)
            {{-- Phones: the menus fold away behind this button (see the header script). --}}
            <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="site-menus">☰ Menu</button>
        @endisset
        <nav class="primary" id="site-menus" aria-label="Monitoring">
            @isset($auth)
                {{-- Each area is a menu: reports and its test page (everyone), then add, accounts and settings (admins). --}}
                @php($menus = [
                    'SSL' => ['/ssl/reports' => 'Reports', '/ssl' => 'Test', '/admin/ssl/new' => 'Add', '/admin/settings/ssl' => 'Settings'],
                    'SSH' => ['/ssh/reports' => 'Reports', '/ssh' => 'Test', '/admin/ssh/new' => 'Add', '/admin/accounts/ssh' => 'Accounts', '/admin/settings/ssh' => 'Settings'],
                    'MariaDB' => ['/mariadb/reports' => 'Reports', '/mariadb' => 'Test', '/mariadb/query' => 'Query', '/admin/mariadb/new' => 'Add', '/admin/accounts/mariadb' => 'Accounts', '/admin/settings/mariadb' => 'Settings'],
                    'Apache' => ['/apache/reports' => 'Reports', '/apache' => 'Test', '/admin/apache/new' => 'Add', '/admin/settings/apache' => 'Settings']
                        // This machine's own Apache: only where the root helper is installed, for admins on this machine.
                        + (\App\Services\LocalApacheService::installed() && \App\Middleware\RequireLocalAdmin::fromThisMachine() ? ['/admin/apache/local' => 'This server', '/admin/apache/local/rewrite' => 'Rewrite rules', '/admin/apache/local/simulate' => 'URL simulator'] : []),
                    'Vhosts' => ['/vhosts/reports' => 'Reports', '/vhosts' => 'List'],
                ])
                @foreach ($menus as $menu => $items)
                    {{-- The most specific item under the current page: /ssh/reports is Reports, not also Test (/ssh). --}}
                    @php($active = collect(array_keys($items))->filter(fn ($href) => $path === $href || str_starts_with($path, $href . '/'))->sortByDesc(fn ($href) => strlen($href))->first())
                    @php($current = $active !== null)
                    <details class="menu">
                        <summary @if ($current) class="current" @endif>{{ $menu }}</summary>
                        <div class="menu-items">
                            @foreach ($items as $href => $label)
                                @if (in_array($label, ['Reports', 'Test', 'List', 'Query'], true) || $auth->isAdmin())
                                    @if ($label === 'Add')
                                        {{-- Viewing items above, admin tools below. --}}
                                        <hr class="menu-divider">
                                    @endif
                                    <a href="{{ $href }}" @if ($href === $active) aria-current="page" @endif>{{ $label }}</a>
                                @endif
                            @endforeach
                        </div>
                    </details>
                @endforeach
            @endisset
        </nav>
        @isset($auth)
            <nav class="secondary" aria-label="Configuration and account">
                @if ($auth->isAdmin())
                    <a href="/admin/users" @if (str_starts_with($path, '/admin/users')) aria-current="page" @endif>Users</a>
                    <a href="/admin/settings/app" @if ($path === '/admin/settings/app') aria-current="page" @endif>Settings</a>
                @endif
                <a href="/profile" title="How you sign in" @if (str_starts_with($path, '/profile')) aria-current="page" @endif>{{ $auth->user->username }}</a>
                <form method="post" action="/logout">
                    @csrf
                    <button type="submit" class="secondary">Sign out</button>
                </form>
            </nav>
        @endisset
    </header>
    <script>
        // Every table: a filter box above it and sortable column headers. A cell's
        // data-sort overrides its text (status severity, timestamps); a header
        // with data-nosort (or no text) isn't sortable. .fold-row rows stay with
        // the row above them.
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.table-wrap > table').forEach(function (table) {
                var body = table.tBodies[0];
                var header = table.tHead && table.tHead.rows[0];

                if (!body || !header || body.rows.length === 0) { return; }

                function groups() {
                    var list = [];
                    Array.prototype.forEach.call(body.rows, function (row) {
                        if (row.classList.contains('fold-row') && list.length) { list[list.length - 1].push(row); } else { list.push([row]); }
                    });
                    return list;
                }

                // The cell under column $index, allowing for colspans.
                function cellAt(row, index) {
                    var column = 0;
                    for (var i = 0; i < row.cells.length; i++) {
                        column += row.cells[i].colSpan;
                        if (column > index) { return row.cells[i]; }
                    }
                    return null;
                }

                function sortKey(row, index) {
                    var cell = cellAt(row, index);
                    if (!cell) { return ''; }
                    var value = cell.getAttribute('data-sort');
                    return value !== null ? value : cell.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
                }

                function compare(a, b) {
                    var numeric = /^-?\d+(\.\d+)?$/;
                    if (a === '' || b === '') { return a === b ? 0 : (a === '' ? 1 : -1); }
                    if (numeric.test(a) && numeric.test(b)) { return parseFloat(a) - parseFloat(b); }
                    return a.localeCompare(b, undefined, { numeric: true });
                }

                var filter = document.createElement('input');
                filter.type = 'search';
                filter.className = 'table-filter';
                filter.placeholder = 'Filter…';
                filter.setAttribute('aria-label', 'Filter this table');
                table.parentNode.parentNode.insertBefore(filter, table.parentNode);
                filter.addEventListener('input', function () {
                    var query = filter.value.trim().toLowerCase();
                    groups().forEach(function (group) {
                        var show = query === '' || group.some(function (row) { return row.textContent.toLowerCase().indexOf(query) !== -1; });
                        var fold = group[0].querySelector('button.fold');
                        group[0].hidden = !show;
                        group.slice(1).forEach(function (row) { row.hidden = !show || !fold || fold.getAttribute('aria-expanded') !== 'true'; });
                    });
                });

                Array.prototype.forEach.call(header.cells, function (th, index) {
                    if (th.hasAttribute('data-nosort') || th.textContent.trim() === '') { return; }
                    th.classList.add('sortable');
                    th.tabIndex = 0;
                    function sort() {
                        var ascending = th.getAttribute('aria-sort') !== 'ascending';
                        Array.prototype.forEach.call(header.cells, function (other) { other.removeAttribute('aria-sort'); });
                        th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                        groups()
                            .sort(function (a, b) { var result = compare(sortKey(a[0], index), sortKey(b[0], index)); return ascending ? result : -result; })
                            .forEach(function (group) { group.forEach(function (row) { body.appendChild(row); }); });
                    }
                    th.addEventListener('click', sort);
                    th.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); sort(); }
                    });
                });
            });
        });

        // One menu open at a time; close on outside click or Escape.
        (function () {
            var menus = document.querySelectorAll('details.menu');
            menus.forEach(function (menu) {
                menu.addEventListener('toggle', function () {
                    if (menu.open) { menus.forEach(function (other) { if (other !== menu) { other.open = false; } }); }
                });
            });
            document.addEventListener('click', function (event) {
                menus.forEach(function (menu) { if (!menu.contains(event.target)) { menu.open = false; } });
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') { menus.forEach(function (menu) { menu.open = false; }); }
            });
        })();

        document.addEventListener('DOMContentLoaded', function () {
            // Report periods: a preset submits on its own; the start and end fields are only sent once
            // edited (so changing the server keeps "Last 7 days" rolling instead of freezing its dates).
            document.querySelectorAll('select[data-period-preset]').forEach(function (select) {
                var form = select.form;
                var dates = form.querySelectorAll('.period-dates input');
                dates.forEach(function (input) {
                    input.setAttribute('data-name', input.name);
                    input.removeAttribute('name');
                    input.addEventListener('input', function () { dates.forEach(function (d) { d.name = d.getAttribute('data-name'); }); });
                });
                select.addEventListener('change', function () { form.submit(); });
            });

            // The header is sticky: jumps (anchors, scrollIntoView) stop below it, whatever height it has.
            // On phones its menus fold behind the ☰ Menu button.
            (function () {
                var header = document.querySelector('header');
                if (!header) { return; }
                var pad = function () { document.documentElement.style.scrollPaddingTop = (header.offsetHeight + 12) + 'px'; };
                pad();
                window.addEventListener('resize', pad);
                var toggle = header.querySelector('.nav-toggle');
                if (!toggle) { return; }
                var set = function (open) {
                    header.classList.toggle('open', open);
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    toggle.textContent = open ? '✕ Close' : '☰ Menu';
                    window.dispatchEvent(new Event('resize')); // the header's height changed
                };
                toggle.addEventListener('click', function () { set(!header.classList.contains('open')); });
                document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && header.classList.contains('open')) { set(false); } });
                document.addEventListener('click', function (event) { if (header.classList.contains('open') && !header.contains(event.target)) { set(false); } });
            })();

            // Section navigator: every card (or collapsible section, or chart) with a heading, listed in a
            // box at the top right, under the sticky header; click to jump (opening a closed section). In the
            // right margin when it fits, else a small "Sections" button whose list stays open until the
            // button or the page is clicked; only on pages with 3 or more.
            (function () {
                var main = document.querySelector('main');
                if (!main) { return; }
                var sections = [];
                main.querySelectorAll('.card, figure.chart').forEach(function (el) {
                    if (el.parentElement.closest('.card') && !el.matches('figure.chart')) { return; } // nested card
                    if (el.matches('.card') && el.querySelector(':scope > figure.chart') && !el.querySelector(':scope > h1, :scope > h2')) { return; } // its chart stands for it
                    var heading = el.matches('figure.chart') ? el.querySelector('figcaption') : el.querySelector(':scope > h1, :scope > h2, :scope > summary h2, :scope > .actions h1, :scope > .actions h2, :scope > div > h1, :scope > div > h2');
                    var label = heading && heading.textContent.replace(/\s+/g, ' ').trim();
                    if (!label || el.closest('dialog')) { return; }
                    if (!el.id) { el.id = 'section-' + (sections.length + 1); }
                    sections.push({ el: el, label: label.length > 48 ? label.slice(0, 47) + '…' : label });
                });
                if (sections.length < 3) { return; }

                var nav = document.createElement('nav');
                nav.className = 'spy';
                nav.setAttribute('aria-label', 'Sections of this page');
                var toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'spy-toggle';
                toggle.textContent = '☰ Sections';
                toggle.setAttribute('aria-expanded', 'false');
                toggle.addEventListener('click', function () {
                    var open = !nav.classList.contains('open');
                    nav.classList.toggle('open', open);
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
                nav.appendChild(toggle);
                var list = document.createElement('ol');
                sections.forEach(function (section) {
                    var item = document.createElement('li');
                    var link = document.createElement('a');
                    link.href = '#' + section.el.id;
                    link.textContent = section.label;
                    link.title = section.label;
                    link.addEventListener('click', function (event) {
                        event.preventDefault();
                        if (section.el.tagName === 'DETAILS' && !section.el.open) { section.el.open = true; }
                        section.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        history.replaceState(null, '', '#' + section.el.id);
                    });
                    section.link = link;
                    item.appendChild(link);
                    list.appendChild(item);
                });
                nav.appendChild(list);
                document.body.appendChild(nav);

                var header = document.querySelector('header');
                var frame = null;
                function update() {
                    frame = null;
                    // Under the (sticky) header, always.
                    var top = (header ? header.getBoundingClientRect().bottom : 0) + 12;
                    nav.style.top = top + 'px';
                    nav.style.maxHeight = 'calc(100vh - ' + (top + 12) + 'px)';
                    // In the right margin if the box fits there, else collapsed.
                    var room = window.innerWidth - main.getBoundingClientRect().right + parseFloat(getComputedStyle(main).paddingRight);
                    nav.classList.toggle('collapsed', room < 220 + 24);
                    // The section being read: the last one whose top has passed a third of the way down.
                    var current = sections[0];
                    var line = window.innerHeight / 3;
                    sections.forEach(function (section) { if (section.el.getBoundingClientRect().top <= line) { current = section; } });
                    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) { current = sections[sections.length - 1]; }
                    sections.forEach(function (section) { section.link.classList.toggle('active', section === current); });
                }
                var schedule = function () { if (frame === null) { frame = requestAnimationFrame(update); } };
                window.addEventListener('scroll', schedule, { passive: true });
                window.addEventListener('resize', schedule);
                document.addEventListener('toggle', schedule, true); // a section opened or closed
                document.addEventListener('click', function (event) { if (!nav.contains(event.target)) { nav.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); } });
                update();
            })();

            // Charts: click a legend entry to hide or show its line; the value scale refits to the lines
            // still shown (all shown again: the chart as drawn). LineChart puts each point's value in data-v.
            document.querySelectorAll('figure.chart').forEach(function (figure) {
                var svg = figure.querySelector('svg.chart[data-from]');
                var items = figure.querySelectorAll('.legend-item');
                if (!svg || !items.length) { return; }
                var top = +svg.dataset.top, bottom = +svg.dataset.bottom;
                var floor = +svg.dataset.min, ceiling = svg.dataset.max === '' ? null : +svg.dataset.max, unit = svg.dataset.unit || '';
                var labels = Array.prototype.slice.call(svg.querySelectorAll('.y-label'));
                var groups = Array.prototype.slice.call(svg.querySelectorAll('g.series'));
                // As drawn: restored when every line is shown again.
                var drawn = { labels: labels.map(function (l) { return l.textContent; }), points: groups.map(function (g) {
                    return { line: g.querySelector('polyline') && g.querySelector('polyline').getAttribute('points'), cy: Array.prototype.map.call(g.querySelectorAll('circle'), function (c) { return c.getAttribute('cy'); }) };
                }) };
                var nice = function (value) { // LineChart::niceCeiling()
                    if (value <= 0) { return 1; }
                    var power = Math.pow(10, Math.floor(Math.log10(value)));
                    var steps = [1, 2, 2.5, 5, 10];
                    for (var i = 0; i < steps.length; i++) { if (value <= steps[i] * power) { return steps[i] * power; } }
                    return 10 * power;
                };
                var number = function (value) { return String(+value.toFixed(2)); };

                function refit() {
                    var shown = groups.filter(function (g) { return !g.classList.contains('hidden'); });
                    if (shown.length === groups.length) {
                        labels.forEach(function (l, i) { l.textContent = drawn.labels[i]; });
                        groups.forEach(function (g, i) {
                            var line = g.querySelector('polyline');
                            if (line) { line.setAttribute('points', drawn.points[i].line); }
                            g.querySelectorAll('circle').forEach(function (c, j) { c.setAttribute('cy', drawn.points[i].cy[j]); });
                        });
                        return;
                    }
                    var values = [];
                    shown.forEach(function (g) { g.querySelectorAll('circle').forEach(function (c) { values.push(+c.dataset.v); }); });
                    if (!values.length) { return; }
                    var high = nice(Math.max.apply(null, values) * 1.1);
                    if (ceiling !== null) { high = Math.min(high, ceiling); }
                    if (high <= floor) { high = floor + 1; }
                    var y = function (v) { return top + (1 - (Math.max(floor, Math.min(v, high)) - floor) / (high - floor)) * (bottom - top); };
                    labels.forEach(function (l, i) { l.textContent = number(floor + (high - floor) * i / 4) + unit; });
                    shown.forEach(function (g) {
                        var points = [];
                        g.querySelectorAll('circle').forEach(function (c) {
                            var cy = y(+c.dataset.v).toFixed(1);
                            c.setAttribute('cy', cy);
                            points.push(c.getAttribute('cx') + ',' + cy);
                        });
                        var line = g.querySelector('polyline');
                        if (line) { line.setAttribute('points', points.join(' ')); }
                    });
                }

                items.forEach(function (item) {
                    item.addEventListener('click', function () {
                        var on = item.getAttribute('aria-pressed') !== 'true';
                        item.setAttribute('aria-pressed', on ? 'true' : 'false');
                        groups[+item.dataset.series].classList.toggle('hidden', !on);
                        refit();
                    });
                });
            });

            // Charts: drag across one to show just that stretch of time (the whole report follows).
            document.querySelectorAll('svg.chart[data-from]').forEach(function (svg) {
                var from = +svg.dataset.from, to = +svg.dataset.to, left = +svg.dataset.left, right = +svg.dataset.right;
                var ns = 'http://www.w3.org/2000/svg', band = null, start = null;
                var at = function (event) {
                    var point = svg.createSVGPoint();
                    point.x = event.clientX; point.y = event.clientY;
                    return Math.max(left, Math.min(right, point.matrixTransform(svg.getScreenCTM().inverse()).x));
                };
                var time = function (x) { return Math.round(from + (x - left) / (right - left) * (to - from)); };
                svg.addEventListener('pointerdown', function (event) {
                    if (event.button !== 0) { return; }
                    start = at(event);
                    band = document.createElementNS(ns, 'rect');
                    band.setAttribute('class', 'zoom-band');
                    band.setAttribute('y', svg.dataset.top);
                    band.setAttribute('height', svg.dataset.bottom - svg.dataset.top);
                    band.setAttribute('x', start); band.setAttribute('width', 0);
                    svg.appendChild(band);
                    svg.setPointerCapture(event.pointerId);
                });
                svg.addEventListener('pointermove', function (event) {
                    if (band === null) { return; }
                    var x = at(event);
                    band.setAttribute('x', Math.min(start, x)); band.setAttribute('width', Math.abs(x - start));
                });
                var finish = function (event, apply) {
                    if (band === null) { return; }
                    var x = at(event), a = Math.min(start, x), b = Math.max(start, x);
                    band.remove(); band = null;
                    if (!apply || b - a < 4) { return; }
                    var url = new URL(window.location.href);
                    url.searchParams.set('range', time(a) + '-' + time(b));
                    url.searchParams.delete('start'); url.searchParams.delete('end');
                    window.location.href = url.toString();
                };
                svg.addEventListener('pointerup', function (event) { finish(event, true); });
                svg.addEventListener('pointercancel', function (event) { finish(event, false); });
            });
        });

        // "Are you sure?" for forms and buttons with data-confirm.
        document.addEventListener('submit', function (event) {
            var button = event.submitter;
            var message = (button && button.getAttribute('data-confirm')) || event.target.getAttribute('data-confirm');
            if (message && !window.confirm(message)) { event.preventDefault(); }
        });
    </script>
    <main class="@yield('width')">
        @yield('content')
    </main>
</body>
</html>
