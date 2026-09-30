@extends('layouts.app')

@section('title', 'New Apache site')
@section('width', 'narrow')

@section('content')
    <div class="card">
        <h1>New site</h1>
        <p class="muted">Writes <code>/etc/apache2/sites-available/NAME.conf</code> with a virtual host from these fields. You can edit the file afterwards for anything else.</p>

        @if ($error)
            <div class="alert error" role="alert"><pre class="log-message">{{ $error }}</pre></div>
        @endif

        <form method="post" action="/admin/apache/local/new" id="new-site">
            @csrf
            <label for="name">File name</label>
            <input type="text" id="name" name="name" value="{{ $old['name'] ?? '' }}" placeholder="shop.example.com" autocapitalize="none" spellcheck="false" required>
            <label for="server_name">Hostname (ServerName)</label>
            <input type="text" id="server_name" name="server_name" value="{{ $old['server_name'] ?? '' }}" placeholder="shop.example.com" autocapitalize="none" spellcheck="false" required>
            <label for="aliases">Other hostnames (ServerAlias, optional)</label>
            <input type="text" id="aliases" name="aliases" value="{{ $old['aliases'] ?? '' }}" placeholder="www.shop.example.com" autocapitalize="none" spellcheck="false">
            <label for="document_root">Document root</label>
            <input type="text" id="document_root" name="document_root" value="{{ $old['document_root'] ?? '' }}" placeholder="/var/www/shop/public" autocapitalize="none" spellcheck="false" required>
            <label for="port">Port (optional)</label>
            <input type="text" id="port" name="port" value="{{ $old['port'] ?? '' }}" inputmode="numeric" placeholder="80, or 443 with a certificate">
            <details>
                <summary>Logs and SSL (optional)</summary>
                <label for="error_log">Error log</label>
                <input type="text" id="error_log" name="error_log" value="{{ $old['error_log'] ?? '' }}" placeholder="Default: ${APACHE_LOG_DIR}/HOSTNAME-error.log" autocapitalize="none" spellcheck="false">
                <label for="access_log">Access log</label>
                <input type="text" id="access_log" name="access_log" value="{{ $old['access_log'] ?? '' }}" placeholder="Default: ${APACHE_LOG_DIR}/HOSTNAME-access.log" autocapitalize="none" spellcheck="false">
                <label for="ssl_certificate">Certificate file (turns SSL on)</label>
                <input type="text" id="ssl_certificate" name="ssl_certificate" value="{{ $old['ssl_certificate'] ?? '' }}" placeholder="/etc/letsencrypt/live/HOSTNAME/fullchain.pem" autocapitalize="none" spellcheck="false">
                <label for="ssl_key">Key file</label>
                <input type="text" id="ssl_key" name="ssl_key" value="{{ $old['ssl_key'] ?? '' }}" placeholder="/etc/letsencrypt/live/HOSTNAME/privkey.pem" autocapitalize="none" spellcheck="false">
                <p class="hint">SSL needs mod_ssl enabled.</p>
            </details>
            <label class="check"><input type="checkbox" name="enable" value="1" {{ !empty($old['enable']) ? 'checked' : '' }}> Enable it right away</label>

            @include('partials.confirm-fields', ['formId' => 'new-site', 'what' => 'Create'])

            <div class="actions">
                <button type="submit">Create site</button>
                <a class="button secondary-link" href="/admin/apache/local">Cancel</a>
            </div>
        </form>
    </div>
    <script src="/assets/js/passkeys.js"></script>
@endsection
