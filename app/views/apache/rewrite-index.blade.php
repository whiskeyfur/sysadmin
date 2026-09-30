@extends('layouts.app')

@section('title', 'Rewrite rules')
@section('width', 'wide')

@section('content')
    <div class="card">
        <div class="actions" style="margin-top: 0; justify-content: space-between">
            <h1>Rewrite and access rules</h1>
            <div class="row-actions">
                <a class="button" href="/admin/apache/local/simulate">URL simulator</a>
                <a class="button secondary-link" href="/admin/apache/local">Apache on this server</a>
            </div>
        </div>
        <p class="muted">Every place this machine's Apache can have mod_rewrite rules: the virtual hosts and &lt;Directory&gt; sections of the enabled configuration, and .htaccess files in the directories it serves (up to {{ \App\Services\RewriteEditorService::HTACCESS_DEPTH }} levels down; vendor, node_modules and the like skipped). Rules in configuration files apply when Apache reloads; .htaccess files apply right away.</p>
        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif
        @foreach ($notes as $note)
            <div class="alert error" role="alert">{{ $note }}</div>
        @endforeach
        <form method="get" action="/admin/apache/local/rewrite/scope" class="inline-form">
            <div>
                <label for="htaccess_dir">.htaccess in another directory (inside a served one)</label>
                <input type="text" id="htaccess_dir" name="dir" placeholder="/var/www/html/blog" autocapitalize="none" spellcheck="false" style="min-width: 320px">
            </div>
            <button type="submit" class="secondary">Open</button>
        </form>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Where</th><th>Kind</th><th>Rules</th><th>Engine</th><th>Access lines</th><th>File</th></tr></thead>
            <tbody>
                @foreach ($scopes as $scope)
                    <tr>
                        <td><a href="/admin/apache/local/rewrite/scope?id={{ urlencode($scope['id']) }}">{{ $scope['title'] }}</a></td>
                        <td>{{ $scope['kind'] === 'htaccess' ? '.htaccess' : '<' . ucfirst($scope['kind']) . '>' }}</td>
                        <td data-sort="{{ $scope['rules'] }}">{{ $scope['rules'] ?: '' }}</td>
                        <td>
                            @if ($scope['engine'] === true)
                                <span class="badge ok">On</span>
                            @elseif ($scope['engine'] === false)
                                <span class="badge unknown">Off</span>
                            @endif
                        </td>
                        <td data-sort="{{ $scope['access'] }}">{{ $scope['access'] ?: '' }}</td>
                        <td class="muted"><code>{{ $scope['where'] }}</code>@unless ($scope['editable']) (read only)@endunless</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
@endsection
