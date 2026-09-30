@extends('layouts.app')

@section('title', 'Rewrite rules')
@section('width', 'wide')

@section('content')
    @php($set = $scope['rules'])
    <div class="card">
        <p class="muted"><a href="/admin/apache/local/rewrite">Rewrite rules</a> ›</p>
        <h1>{{ $scope['title'] }}</h1>
        @if ($scope['kind'] === 'virtualhost' && $scope['block'])
            <p><a href="/admin/vhost?id={{ urlencode('local:' . $scope['block']->file . ':' . $scope['block']->line) }}">Edit this virtual host's definition</a></p>
        @endif
        <p class="muted"><code>{{ $scope['file'] }}</code>{{ $scope['exists'] ? '' : ' (doesn\'t exist yet: adding a rule creates it)' }}. {{ $scope['kind'] === 'htaccess' ? 'Changes apply right away (Apache reads .htaccess on every request), if AllowOverride allows FileInfo here.' : 'Changes are checked with apache2ctl configtest and apply when Apache reloads.' }}</p>

        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif
        @if ($error)
            <div class="alert error" role="alert"><pre class="log-message">{{ $error }}</pre></div>
        @endif
        @foreach ($set->problems as $problem)
            <div class="alert error" role="alert">{{ $problem }}</div>
        @endforeach
        @unless ($scope['editable'])
            <div class="alert error" role="alert">This file can't be edited here (only sites, conf snippets, apache2.conf, ports.conf and .htaccess files).</div>
        @endunless
    </div>

    <form method="post" action="/admin/apache/local/rewrite/change" id="change">
        @csrf
        <input type="hidden" name="id" value="{{ $scope['id'] }}">
        <input type="hidden" name="hash" value="{{ $scope['hash'] }}">

        <div class="card">
            <div class="actions" style="margin-top: 0; justify-content: space-between">
                <h2>Rules, in the order Apache tries them</h2>
                @if ($scope['editable'])
                    <a class="button" href="/admin/apache/local/rewrite/rule?id={{ urlencode($scope['id']) }}">Add a rule</a>
                @endif
            </div>
            @if ($set->rules === [])
                <p class="muted">No rules here yet.</p>
            @else
                <div class="table-wrap">
                <table class="top">
                    <thead><tr><th data-nosort>#</th><th data-nosort>If</th><th data-nosort>Pattern</th><th data-nosort>Becomes</th><th data-nosort>Flags</th><th data-nosort></th></tr></thead>
                    <tbody>
                        @foreach ($set->rules as $i => $rule)
                            <tr>
                                <td class="muted">{{ $i + 1 }}<div><code>:{{ $rule['node']->line }}</code></div></td>
                                <td>
                                    @foreach ($rule['conds'] as $cond)
                                        <div><code>{{ $cond['test'] }}</code> <code>{{ $cond['pattern'] }}</code>@if ($cond['flags']) <span class="muted">[{{ implode(',', array_keys($cond['flags'])) }}]</span>@endif</div>
                                    @endforeach
                                </td>
                                <td><code>{{ $rule['pattern'] }}</code></td>
                                <td><code>{{ $rule['substitution'] }}</code></td>
                                <td>@foreach ($rule['flags'] as [$flag, $value])<span class="badge unknown" title="{{ \App\Services\RewriteRules::RULE_FLAGS[$flag] ?? '' }}">{{ $value === null ? $flag : "$flag=$value" }}</span> @endforeach</td>
                                <td class="row-actions">
                                    @if ($scope['editable'])
                                        <a class="button secondary-link" href="/admin/apache/local/rewrite/rule?id={{ urlencode($scope['id']) }}&amp;index={{ $i }}">Edit</a>
                                        @if ($i > 0)<button type="submit" name="op" value="up:{{ $i }}" class="secondary" title="Move up">↑</button>@endif
                                        @if ($i < count($set->rules) - 1)<button type="submit" name="op" value="down:{{ $i }}" class="secondary" title="Move down">↓</button>@endif
                                        <button type="submit" name="op" value="delete:{{ $i }}" class="secondary danger" data-confirm="Delete rule {{ $i + 1 }}?">Delete</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>

        @if ($scope['editable'])
            <div class="card">
                <h2>Settings</h2>
                <label class="check"><input type="checkbox" name="engine" value="1" {{ $set->engine ? 'checked' : '' }}> RewriteEngine On</label>
                <label for="base">RewriteBase (per-directory rules: the URL path for relative substitutions)</label>
                <input type="text" id="base" name="base" value="{{ $set->base }}" placeholder="/blog/" autocapitalize="none" spellcheck="false">
                @if ($set->options)
                    <p class="hint">RewriteOptions {{ implode(' ', $set->options) }}.</p>
                @endif
                <div class="actions"><button type="submit" name="op" value="settings" class="secondary">Save settings</button></div>
            </div>

            <div class="card">
                <h2>Confirm, then use a button above</h2>
                <p class="muted">Every change needs a fresh check (an authenticator code works once).</p>
                @include('partials.confirm-fields', ['formId' => 'change', 'what' => 'Confirm', 'passkeyTarget' => ''])
            </div>
        @endif
    </form>

    <div class="card">
        <h2>Try a URL</h2>
        <form method="get" action="/admin/apache/local/simulate">
            @include('apache.simulate-form', ['name' => 'url', 'url' => '', 'form' => ['method' => 'GET', 'remote_addr' => '127.0.0.1', 'user_agent' => '', 'referer' => '', 'cookie' => '']])
            <div class="actions"><button type="submit" class="secondary">Simulate</button></div>
        </form>
    </div>
    <script src="/assets/js/passkeys.js"></script>
@endsection
