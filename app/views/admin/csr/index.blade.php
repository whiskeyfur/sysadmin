@extends('layouts.app')

@section('title', 'Certificate request (CSR)')

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <h1>Certificate request (CSR)</h1>
        <p class="muted">A certificate signing request to replace a certificate: start from one of the monitored certificates (the one its server presents now is fetched) or paste one, and the form takes its names, organisation details and kind of key. Give the request to your certificate authority; install what it sends back with the private key that goes with the request.</p>

        <form method="get" action="/admin/csr" class="csr-pick">
            <label for="certificate">Start from a monitored certificate</label>
            <div class="csr-row">
                <select id="certificate" name="certificate">
                    <option value="">Choose…</option>
                    @foreach ($certificates as $each)
                        <option value="{{ $each->id }}" {{ $certificate?->id === $each->id ? 'selected' : '' }}>{{ $each->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="secondary">Fetch it</button>
            </div>
        </form>

        <details class="csr-paste" @if (!$certificates) open @endif>
            <summary>Or paste a certificate</summary>
            <form method="post" action="/admin/csr/read">
                @csrf
                <label for="paste_pem">Certificate (PEM)</label>
                <textarea id="paste_pem" name="certificate_pem" rows="6" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----&#10;…&#10;-----END CERTIFICATE-----"></textarea>
                <div class="actions"><button type="submit" class="secondary">Read it</button></div>
            </form>
        </details>
    </div>

    @php($v = fn (string $key, mixed $default = '') => $input[$key] ?? $from[$key] ?? $default)
    @php($subject = $input['subject'] ?? $from['subject'] ?? [])
    <form method="post" action="/admin/csr" class="card" id="csr-form">
        @csrf
        <h2>The request</h2>
        @if ($from)
            <p class="hint">From {{ $certificate ? $certificate->name . ', as served now' : 'the pasted certificate' }}: issued by {{ $from['issuer'] ?: 'unknown' }}, expires {{ $from['valid_to'] ? \App\Utils\LocalTime::format($from['valid_to'], 'Y-m-d') : 'unknown' }}; SHA-256 <code class="fingerprint">{{ $from['fingerprint'] }}</code>.</p>
            <input type="hidden" name="certificate_pem" value="{{ $from['pem'] }}">
        @elseif (!empty($input['certificate_pem']))
            <input type="hidden" name="certificate_pem" value="{{ $input['certificate_pem'] }}">
        @endif
        @if ($certificate || !empty($input['certificate_id']))
            <input type="hidden" name="certificate_id" value="{{ $certificate?->id ?? $input['certificate_id'] }}">
        @endif

        <label for="common_name">Common name</label>
        <input type="text" id="common_name" name="common_name" value="{{ $v('common_name') }}" spellcheck="false" placeholder="www.example.com" required>
        <label for="names">Other names on it (SAN)</label>
        <textarea id="names" name="names" rows="4" spellcheck="false" placeholder="example.com&#10;shop.example.com">{{ is_array($v('names', [])) ? implode("\n", array_values(array_diff($v('names', []), [$v('common_name')]))) : $v('names') }}</textarea>
        <p class="hint">One per line: host names (<code>*.example.com</code> for a wildcard) or IP addresses. The common name is always included.</p>

        <div class="csr-subject">
            @foreach (\App\Services\CsrService::SUBJECT as $field => $label)
                <div>
                    <label for="subject_{{ $field }}">{{ $label }} <span class="muted">(optional)</span></label>
                    <input type="text" id="subject_{{ $field }}" name="subject[{{ $field }}]" value="{{ $subject[$field] ?? '' }}" maxlength="{{ $field === 'C' ? 2 : 64 }}">
                </div>
            @endforeach
        </div>
        <p class="hint">Domain-validated certificates (e.g. Let's Encrypt) ignore these; organisation-validated ones show them.</p>

        <fieldset class="csr-key">
            <legend>Private key</legend>
            <label for="private_key">The existing private key <span class="muted">(optional)</span></label>
            <textarea id="private_key" name="private_key" rows="4" spellcheck="false" autocomplete="off" placeholder="-----BEGIN PRIVATE KEY-----&#10;…"></textarea>
            <p class="hint">Paste it to keep the same key: it's checked against the certificate and used, never stored. Leave it empty for a new key (recommended), made here and kept encrypted until you delete the request.</p>
            <label for="key">Kind of new key</label>
            <select id="key" name="key">
                @foreach (\App\Services\CsrService::KEYS as $value => [$type, $size, $label])
                    <option value="{{ $value }}" {{ $v('key', 'rsa-2048') === $value ? 'selected' : '' }}>{{ $label }}{{ ($from['key'] ?? null) === $value ? ' (as the certificate has now)' : '' }}</option>
                @endforeach
            </select>
        </fieldset>

        <div class="actions"><button type="submit">Make the request</button></div>
    </form>

    @if ($requests)
        <div class="card">
            <h2>Requests made</h2>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Made</th><th>Common name</th><th>Names</th><th>Key</th><th>For</th><th>By</th></tr></thead>
                <tbody>
                    @foreach ($requests as $each)
                        <tr>
                            <td data-sort="{{ $each->created_at->getTimestamp() }}"><a href="/admin/csr/{{ $each->id }}">{{ \App\Utils\LocalTime::format($each->created_at) }}</a></td>
                            <td><code>{{ $each->common_name }}</code></td>
                            <td data-sort="{{ count($each->names) }}">{{ count($each->names) }}</td>
                            <td>{{ $each->keyLabel() }}, {{ $each->key_source === 'new' ? 'new (kept)' : 'existing' }}</td>
                            <td>{{ $each->certificate?->name ?? '—' }}</td>
                            <td class="muted">{{ $each->user?->username ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif

    <style>
        .csr-row { display: flex; gap: 8px; align-items: center; }
        .csr-row select { flex: 1; margin: 0; }
        .csr-paste { margin-top: 12px; }
        .csr-subject { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0 16px; }
        .csr-key { border: 1px solid var(--line); border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
        .csr-key legend { font-weight: 600; padding: 0 6px; }
        code.fingerprint { overflow-wrap: anywhere; font-size: 11px; }
    </style>
@endsection
