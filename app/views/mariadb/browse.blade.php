@extends('layouts.app')

@section('title', $table ?? $schema ?? ($server?->name ?? 'MariaDB browser'))
@section('width', 'wide')

@section('content')
    @php($link = fn (array $to) => '/mariadb/browse?' . http_build_query(array_filter($to + ['server' => $server?->id, 'as' => $as], fn ($v) => $v !== null && $v !== '')))
    @php($size = function (int $bytes): string {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $factor) {
            if ($bytes >= $factor) {
                return number_format($bytes / $factor, $bytes >= 10 * $factor ? 0 : 1) . " $unit";
            }
        }

        return $bytes . ' B';
    })

    <div class="card">
        <h1>MariaDB browser</h1>
        <nav class="crumbs" aria-label="Where you are">
            <a href="/mariadb/browse">Servers</a>
            @if ($server)
                › <a href="{{ $link(['schema' => null]) }}">{{ $server->name }}</a>
            @endif
            @if ($schema)
                › <a href="{{ $link(['schema' => $schema]) }}">{{ $schema }}</a>
            @endif
            @if ($table)
                › <strong>{{ $table }}</strong>
            @endif
        </nav>
        @if ($server && $logins)
            <form method="get" action="/mariadb/browse" class="browse-login">
                <input type="hidden" name="server" value="{{ $server->id }}">
                @if ($schema)<input type="hidden" name="schema" value="{{ $schema }}">@endif
                @if ($table)<input type="hidden" name="table" value="{{ $table }}">@endif
                <label for="as">Logged in as</label>
                <select id="as" name="as" onchange="this.form.submit()">
                    @foreach ($logins as $login)
                        <option value="{{ $login['value'] }}" {{ $as === $login['value'] ? 'selected' : '' }}>{{ $login['label'] }}{{ $login['refused'] ? ' — refused at its last check' : '' }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="secondary">Switch</button></noscript>
            </form>
        @endif
        <p class="muted">Read only: what the chosen login may see, as the server shows it to that login. Privileges are listed as far as the login can see them (without read access to the <code>mysql</code> database a login sees only its own), from this level and above; privileges that come through roles are listed under the role.</p>
    </div>

    @if ($error)
        @include('mariadb.error-alert', ['message' => $error])
    @endif

    @if (!$server)
        <div class="card">
            <h2>Servers</h2>
            @if ($servers === [])
                <p class="muted">No server has MariaDB/MySQL monitoring set up.</p>
            @else
                <div class="table-wrap">
                <table>
                    <thead><tr><th>Server</th><th>Address</th><th>Your logins</th></tr></thead>
                    <tbody>
                        @foreach ($servers as $each)
                            <tr>
                                <td><a href="/mariadb/browse?server={{ $each->id }}">{{ $each->name }}</a></td>
                                <td class="muted">{{ $each->mysqlHost() }}:{{ $each->mysql_port }}</td>
                                <td>{!! $loginsFor[$each->id] ? '<span class="badge ok">Yes</span>' : '<span class="muted">None: add one in <a href="/mariadb/query#accounts">MariaDB › Query</a></span>' !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    @endif

    @if ($schemas !== null)
        <div class="card">
            <h2>Databases on {{ $server->name }}</h2>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Database</th><th>Tables</th><th>Size</th><th>Collation</th></tr></thead>
                <tbody>
                    @foreach ($schemas as $each)
                        <tr>
                            <td><a href="{{ $link(['schema' => $each['name']]) }}">{{ $each['name'] }}</a> @if ($each['system'])<span class="badge unknown">system</span>@endif</td>
                            <td data-sort="{{ $each['tables'] }}">{{ number_format($each['tables']) }}</td>
                            <td data-sort="{{ $each['bytes'] }}">{{ $size($each['bytes']) }}</td>
                            <td class="muted">{{ $each['collation'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif

    @if ($tables !== null)
        <div class="card">
            <h2>Tables in {{ $schema }}</h2>
            @if ($tables === [])
                <p class="muted">No tables that this login can see.</p>
            @else
                <div class="table-wrap">
                <table>
                    <thead><tr><th>Table</th><th>Type</th><th>Engine</th><th>Rows (about)</th><th>Size</th><th>Collation</th><th>Comment</th></tr></thead>
                    <tbody>
                        @foreach ($tables as $each)
                            <tr>
                                <td><a href="{{ $link(['schema' => $schema, 'table' => $each['name']]) }}">{{ $each['name'] }}</a></td>
                                <td class="muted">{{ $each['type'] === 'BASE TABLE' ? 'Table' : ucwords(strtolower($each['type'])) }}</td>
                                <td class="muted">{{ $each['engine'] ?? '—' }}</td>
                                <td data-sort="{{ $each['rows'] ?? -1 }}">{{ $each['rows'] === null ? '—' : number_format($each['rows']) }}</td>
                                <td data-sort="{{ $each['bytes'] }}">{{ $size($each['bytes']) }}</td>
                                <td class="muted">{{ $each['collation'] ?? '—' }}</td>
                                <td class="muted">{{ $each['comment'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    @endif

    @if ($info !== null)
        <details class="card">
            <summary><h2 style="display: inline">Definition</h2></summary>
            <pre class="create-statement">{{ $info['create'] }}</pre>
        </details>

        <div class="card">
            <h2>Data</h2>
            <p class="muted">{{ \App\Services\DatabaseBrowserService::PAGE_SIZE }} rows a page. Search finds rows where any column contains the text; click a column to sort by it. Long values are cut at {{ number_format(\App\Services\MariadbQueryService::MAX_CELL) }} characters, binary ones shown as hex.</p>
            <div class="paged" id="table-data" data-paged="/mariadb/browse/rows?{{ http_build_query(['server' => $server->id, 'as' => $as, 'schema' => $schema, 'table' => $table]) }}">
                @include('reports.paged-controls', ['label' => 'Search the rows'])
                <div class="paged-wrap">
                    <table class="top browse-data">
                        <thead>
                            <tr>
                                @foreach ($columns as $column)
                                    <th data-sort-key="{{ $column['name'] }}" title="{{ $column['type'] }}{{ $column['key'] === 'PRI' ? ', primary key' : '' }}{{ $column['nullable'] ? ', may be NULL' : '' }}">{{ $column['name'] }}@if ($column['key'] === 'PRI') 🔑@endif</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody><tr><td colspan="{{ max(1, count($columns)) }}" class="muted">Loading…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <script src="{{ \App\Utils\Asset::url('/assets/js/paged-table.js') }}"></script>
    @endif

    @if ($privileges !== null)
        <details class="card" open>
            <summary><h2 style="display: inline">Privileges {{ $table ? 'on this table' : ($schema ? 'on this database' : 'on this server') }}</h2> <span class="muted">({{ count(array_unique(array_column($privileges, 'grantee'))) }} {{ count(array_unique(array_column($privileges, 'grantee'))) === 1 ? 'account or role' : 'accounts and roles' }})</span></summary>
            @if ($privileges === [])
                <p class="muted">None that this login can see.</p>
            @else
                <div class="table-wrap">
                <table class="top">
                    <thead><tr><th>Account or role</th><th>Level</th><th>On</th><th>Privileges</th><th>Can grant</th></tr></thead>
                    <tbody>
                        @foreach ($privileges as $grant)
                            <tr>
                                <td style="white-space: nowrap"><code>{{ $grant['grantee'] }}</code></td>
                                <td data-sort="{{ ['server' => 0, 'database' => 1, 'table' => 2, 'column' => 3][$grant['level']] }}">{{ ucfirst($grant['level']) }}</td>
                                <td><code>{{ $grant['on'] }}</code></td>
                                <td>{{ implode(', ', $grant['privileges']) }}</td>
                                <td>{{ $grant['grantable'] ? 'Yes' : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </details>
    @endif

    <style>
        .crumbs { margin: 4px 0 10px; }
        .browse-login { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; }
        .browse-login label { margin: 0; }
        .browse-login select { width: auto; margin: 0; }
        pre.create-statement { white-space: pre-wrap; overflow-x: auto; font-size: 13px; margin: 8px 0 0; }
        table.browse-data td { white-space: pre-wrap; max-width: 40em; overflow-wrap: anywhere; }
    </style>
@endsection
