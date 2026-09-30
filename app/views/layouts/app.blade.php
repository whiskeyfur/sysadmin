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
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 24px; border-bottom: 1px solid var(--line); background: var(--panel); }
        header .brand { font-weight: 700; letter-spacing: .02em; color: inherit; text-decoration: none; }
        header nav { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        header nav.primary .brand { margin-right: 8px; }
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
        input[type=text], input[type=password], select, textarea { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--text); font: inherit; }
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
        <nav class="primary" aria-label="Monitoring">
            <a class="brand" href="/">sys</a>
            @isset($auth)
                {{-- Each area is a menu: reports and its test page (everyone), then add, accounts and settings (admins). --}}
                @php($menus = [
                    'SSL' => ['/ssl/reports' => 'Reports', '/ssl' => 'Test', '/admin/ssl/new' => 'Add', '/admin/settings/ssl' => 'Settings'],
                    'SSH' => ['/ssh/reports' => 'Reports', '/ssh' => 'Test', '/admin/ssh/new' => 'Add', '/admin/accounts/ssh' => 'Accounts', '/admin/settings/ssh' => 'Settings'],
                    'MariaDB' => ['/mariadb/reports' => 'Reports', '/mariadb' => 'Test', '/admin/mariadb/new' => 'Add', '/admin/accounts/mariadb' => 'Accounts', '/admin/settings/mariadb' => 'Settings'],
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
                                @if (in_array($label, ['Reports', 'Test', 'List'], true) || $auth->isAdmin())
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
