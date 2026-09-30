@extends('layouts.app')

@section('title', 'Virtual host')
@section('width', 'wide')

@section('content')
    @php($f = $loaded['fields'])
    @php($server = $loaded['server'])
    @php($caps = $loaded['caps'])
    @php($canWrite = $loaded['write'] !== null && ($server === null || ($caps['test'] ?? null) !== null))
    <div class="card">
        <p class="muted">
            @if ($server)
                <a href="/vhosts">Vhosts</a> › {{ $server->name }} ›
            @else
                <a href="/admin/apache/local">Apache on this server</a> ›
            @endif
        </p>
        <h1>{{ $loaded['title'] }}</h1>
        <p class="muted"><code>{{ $loaded['file'] }}</code>, lines {{ $loaded['block']->line }}–{{ $loaded['block']->endLine }}. Saving changes only this virtual host's lines, runs Apache's configuration test and puts the old file back if it fails; reload Apache afterwards to apply it.</p>

        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif
        @if ($error)
            <div class="alert error" role="alert"><pre class="log-message">{{ $error }}</pre></div>
        @endif
        @if ($output)
            <pre class="log-message">{{ $output }}</pre>
        @endif

        @if ($server)
            <h2>What {{ $server->name }}'s sudo rules allow the SSH user ({{ $server->ssh_username }})</h2>
            <ul>
                <li>Change this file: {!! $loaded['write'] === 'direct' ? '<span class="badge ok">yes, directly</span>' : ($loaded['write'] === 'sudo' ? '<span class="badge ok">yes, with sudo tee</span>' : '<span class="badge critical">no</span>') !!}</li>
                <li>Configuration test: {!! $caps['test'] ? '<span class="badge ok">yes</span> <code>' . e($caps['ctl'] . ' ' . $caps['test']) . '</code>' : '<span class="badge critical">no</span>' !!}</li>
                <li>Reload: {!! $caps['reload'] || $caps['graceful'] ? '<span class="badge ok">yes</span>' : '<span class="badge unknown">no</span>' !!} · Restart: {!! $caps['restart'] ? '<span class="badge ok">yes</span>' : '<span class="badge unknown">no</span>' !!}</li>
            </ul>
            @unless ($canWrite && $caps['reload'] && $caps['restart'])
                <p class="hint">Nothing is installed on the server: the app uses the SSH user's own sudo rules, asked with <code>sudo -n -l</code> (which runs nothing). For example, on the server (<code>sudo visudo -f /etc/sudoers.d/sys</code>):</p>
                <pre class="log-message">{{ $server->ssh_username }} ALL=(root) NOPASSWD: {{ $caps['ctl'] ?? '/usr/sbin/apachectl' }} configtest, /usr/bin/systemctl reload {{ $caps['service'] ?? 'apache2' }}, /usr/bin/systemctl restart {{ $caps['service'] ?? 'apache2' }}, /usr/bin/tee {{ $loaded['file'] }}</pre>
            @endunless
        @endif
    </div>

    <form method="post" action="/admin/vhost" id="vhost">
        @csrf
        <input type="hidden" name="id" value="{{ $loaded['id'] }}">
        <input type="hidden" name="hash" value="{{ $loaded['hash'] }}">

        <div class="card">
            <h2>Definition</h2>
            <div class="vhost-grid">
                <div>
                    <label for="address">Address (&lt;VirtualHost …&gt;)</label>
                    <input type="text" id="address" name="address" value="{{ $f['address'] }}" placeholder="*:443" autocapitalize="none" spellcheck="false" required>
                    <label for="server_name">ServerName</label>
                    <input type="text" id="server_name" name="server_name" value="{{ $f['server_name'] }}" autocapitalize="none" spellcheck="false">
                    <label for="aliases">ServerAlias (space separated)</label>
                    <input type="text" id="aliases" name="aliases" value="{{ $f['aliases'] }}" autocapitalize="none" spellcheck="false">
                    <label for="document_root">DocumentRoot</label>
                    <input type="text" id="document_root" name="document_root" value="{{ $f['document_root'] }}" autocapitalize="none" spellcheck="false">
                    <label for="https_redirect">Redirect everything to (https://hostname/, for a port-80 host)</label>
                    <input type="text" id="https_redirect" name="https_redirect" value="{{ $f['https_redirect'] }}" placeholder="https://shop.example.com/" autocapitalize="none" spellcheck="false">
                </div>
                <div>
                    <label for="error_log">ErrorLog</label>
                    <input type="text" id="error_log" name="error_log" value="{{ $f['error_log'] }}" placeholder="${APACHE_LOG_DIR}/shop-error.log" autocapitalize="none" spellcheck="false">
                    <label for="access_log">CustomLog (its format is kept)</label>
                    <input type="text" id="access_log" name="access_log" value="{{ $f['access_log'] }}" placeholder="${APACHE_LOG_DIR}/shop-access.log" autocapitalize="none" spellcheck="false">
                    <label class="check"><input type="checkbox" name="ssl_engine" value="1" {{ $f['ssl_engine'] ? 'checked' : '' }}> SSLEngine on</label>
                    <label for="ssl_certificate">SSLCertificateFile</label>
                    <input type="text" id="ssl_certificate" name="ssl_certificate" value="{{ $f['ssl_certificate'] }}" autocapitalize="none" spellcheck="false">
                    <label for="ssl_key">SSLCertificateKeyFile</label>
                    <input type="text" id="ssl_key" name="ssl_key" value="{{ $f['ssl_key'] }}" autocapitalize="none" spellcheck="false">
                    <label for="ssl_chain">SSLCertificateChainFile (older setups)</label>
                    <input type="text" id="ssl_chain" name="ssl_chain" value="{{ $f['ssl_chain'] }}" autocapitalize="none" spellcheck="false">
                </div>
            </div>
            <p class="hint">An emptied field removes its line; a new one is added just before &lt;/VirtualHost&gt;. Everything else is left as it is.
                @unless ($server)
                    Rewrite rules: <a href="/admin/apache/local/rewrite/scope?id={{ urlencode('block:' . $loaded['file'] . ':' . $loaded['block']->line) }}">rule editor</a>.
                @endunless
            </p>
            <div class="actions"><button type="submit" name="mode" value="fields" {{ $canWrite ? '' : 'disabled' }}>Save these fields</button></div>
        </div>

        <div class="card">
            <h2>The whole definition</h2>
            <p class="muted">For anything the fields don't cover. Only this &lt;VirtualHost&gt; section is replaced; it must stay one section.</p>
            <textarea name="block_text" rows="{{ min(40, max(10, substr_count($loaded['blockText'], "\n") + 3)) }}" spellcheck="false" style="font-family: ui-monospace, monospace; font-size: 13px">{{ $loaded['blockText'] }}</textarea>
            <div class="actions"><button type="submit" name="mode" value="block" {{ $canWrite ? '' : 'disabled' }}>Save the definition</button></div>
        </div>

        @unless ($server)
            <div class="card">
                <h2>Try a URL first</h2>
                <label class="check"><input type="radio" name="test_with" value="fields" checked> with the fields</label>
                <label class="check"><input type="radio" name="test_with" value="block"> with the definition text</label>
                <label for="test_url">URL</label>
                <input type="text" id="test_url" name="test_url" value="{{ $testUrl }}" placeholder="https://{{ $f['server_name'] ?: 'www.example.com' }}/" autocapitalize="none" spellcheck="false">
                <div class="actions"><button type="submit" name="mode" value="test" class="secondary" formnovalidate>Try it</button></div>
                @if ($trace)
                    @include('apache.trace')
                @endif
            </div>
        @endunless

        <div class="card">
            <h2>Confirm to save</h2>
            @include('partials.confirm-fields', ['formId' => 'vhost', 'what' => 'Confirm', 'passkeyTarget' => ''])
        </div>
    </form>

    <form method="post" action="/admin/vhost/service" id="svc">
        @csrf
        <input type="hidden" name="id" value="{{ $loaded['id'] }}">
        <div class="card">
            <h2>Apache {{ $server ? 'on ' . $server->name : 'on this machine' }}</h2>
            @include('partials.confirm-fields', ['formId' => 'svc', 'what' => 'Confirm', 'passkeyTarget' => ''])
            <div class="actions">
                <button type="submit" name="action" value="test" class="secondary">Test configuration</button>
                <button type="submit" name="action" value="reload" data-confirm="Reload Apache (graceful)?">Reload</button>
                <button type="submit" name="action" value="restart" class="secondary danger" data-confirm="Restart Apache? Open connections are dropped.">Restart</button>
            </div>
        </div>
    </form>
    <style>
        .vhost-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 0 24px; }
    </style>
    <script src="{{ \App\Utils\Asset::url('/assets/js/passkeys.js') }}"></script>
@endsection
