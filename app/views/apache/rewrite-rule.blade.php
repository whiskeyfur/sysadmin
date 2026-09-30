@extends('layouts.app')

@section('title', $index === null ? 'New rewrite rule' : 'Edit rewrite rule')
@section('width', 'wide')

@section('content')
    @php($conds = array_values($rule['conds']))
    @php($flags = [])
    @foreach ($rule['flags'] as [$flag, $value])
        @php($flags[$flag] = $value ?? '')
    @endforeach
    <div class="card">
        <p class="muted"><a href="/admin/apache/local/rewrite">Rewrite rules</a> › <a href="/admin/apache/local/rewrite/scope?id={{ urlencode($scope['id']) }}">{{ $scope['title'] }}</a> ›</p>
        <h1>{{ $index === null ? 'New rule' : 'Rule ' . ($index + 1) }}</h1>
        <p class="muted">The rule is tried against the URL path ({{ $scope['kind'] === 'virtualhost' ? 'with its leading slash, e.g. /blog/post' : 'relative to this directory, without a leading slash, e.g. blog/post' }}). If the pattern matches, the conditions are checked (all of them, or any of an [OR] group), then the path becomes the substitution: $1… are the pattern's groups, %1… the last condition's, %{HTTP_HOST} and the like are request variables, "-" leaves it as it is.</p>
        @if ($error)
            <div class="alert error" role="alert"><pre class="log-message">{{ $error }}</pre></div>
        @endif
    </div>

    <form method="post" action="/admin/apache/local/rewrite/rule" id="rule">
        @csrf
        <input type="hidden" name="id" value="{{ $scope['id'] }}">
        <input type="hidden" name="hash" value="{{ $scope['hash'] }}">
        <input type="hidden" name="index" value="{{ $index }}">

        <div class="card">
            <h2>Conditions (RewriteCond)</h2>
            <p class="hint">Test string (e.g. <code>%{REQUEST_FILENAME}</code>, <code>%{HTTP_HOST}</code>, <code>%{QUERY_STRING}</code>, <code>%{HTTPS}</code>), then a pattern: a regular expression, <code>!</code> in front to negate, or <code>-f</code> (is a file), <code>-d</code> (is a directory), <code>=text</code>, <code>&lt;text</code>… Leave a row empty to drop it.</p>
            <div class="conds-wrap">
            <table>
                <thead><tr><th data-nosort>Test string</th><th data-nosort>Pattern</th><th data-nosort>No case</th><th data-nosort>OR next</th></tr></thead>
                <tbody>
                    @for ($c = 0; $c < max(count($conds) + 2, 3); $c++)
                        <tr>
                            <td><input type="text" name="conds[{{ $c }}][test]" value="{{ $conds[$c]['test'] ?? '' }}" aria-label="Test string {{ $c + 1 }}" autocapitalize="none" spellcheck="false"></td>
                            <td><input type="text" name="conds[{{ $c }}][pattern]" value="{{ $conds[$c]['pattern'] ?? '' }}" aria-label="Pattern {{ $c + 1 }}" autocapitalize="none" spellcheck="false"></td>
                            <td><input type="checkbox" name="conds[{{ $c }}][nc]" value="1" aria-label="No case {{ $c + 1 }}" {{ isset($conds[$c]['flags']['NC']) ? 'checked' : '' }}></td>
                            <td><input type="checkbox" name="conds[{{ $c }}][or]" value="1" aria-label="OR next {{ $c + 1 }}" {{ isset($conds[$c]['flags']['OR']) ? 'checked' : '' }}></td>
                        </tr>
                    @endfor
                </tbody>
            </table>
            </div>
        </div>

        <div class="card">
            <h2>Rule (RewriteRule)</h2>
            <label for="pattern">Pattern (regular expression; ! in front to negate)</label>
            <input type="text" id="pattern" name="pattern" value="{{ $rule['pattern'] }}" placeholder="^old/(.*)$" autocapitalize="none" spellcheck="false" required>
            <label for="substitution">Substitution</label>
            <input type="text" id="substitution" name="substitution" value="{{ $rule['substitution'] }}" placeholder="/new/$1" autocapitalize="none" spellcheck="false" required>
            <h3>Flags</h3>
            <div class="flag-grid">
                @foreach (\App\Services\RewriteRules::RULE_FLAGS as $flag => $meaning)
                    <label class="check">
                        <input type="checkbox" name="flags[{{ $flag }}]" value="1" {{ array_key_exists($flag, $flags) ? 'checked' : '' }}>
                        <code>{{ $flag }}</code> <span class="muted">{{ $meaning }}</span>
                        @if (in_array($flag, ['R', 'S', 'E', 'T', 'H', 'CO'], true))
                            <input type="text" name="flag_values[{{ $flag }}]" value="{{ $flags[$flag] ?? '' }}" aria-label="{{ $flag }} value" placeholder="{{ ['R' => '301', 'S' => '1', 'E' => 'VAR:value', 'T' => 'text/html', 'H' => 'handler', 'CO' => 'name:value:domain'][$flag] }}" style="width: 130px; display: inline-block; margin-left: 6px">
                        @endif
                    </label>
                @endforeach
            </div>
        </div>

        <div class="card">
            <h2>Try it</h2>
            <p class="muted">Simulate a URL with this rule as written, before saving (the whole configuration, with this file changed).</p>
            @include('apache.simulate-form', ['name' => 'test_url', 'url' => $testUrl])
            <div class="actions"><button type="submit" name="action" value="test" class="secondary" formnovalidate>Try it</button></div>
            @if ($trace)
                @include('apache.trace')
            @endif
        </div>

        <div class="card">
            <h2>Save</h2>
            @include('partials.confirm-fields', ['formId' => 'rule', 'what' => 'Save'])
            <div class="actions">
                <button type="submit" name="action" value="save">{{ $index === null ? 'Add rule' : 'Save rule' }}</button>
                <a class="button secondary-link" href="/admin/apache/local/rewrite/scope?id={{ urlencode($scope['id']) }}">Cancel</a>
            </div>
        </div>
    </form>
    <style>
        .flag-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 4px 16px; }
        .flag-grid .check { margin: 0; }
        .flag-grid code { white-space: nowrap; }
        .conds-wrap { overflow-x: auto; }
        .conds-wrap table { width: 100%; }
    </style>
    <script src="{{ \App\Utils\Asset::url('/assets/js/passkeys.js') }}"></script>
@endsection
