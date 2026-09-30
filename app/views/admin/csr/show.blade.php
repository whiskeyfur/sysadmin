@extends('layouts.app')

@section('title', 'CSR for ' . $request->common_name)

@section('content')
    @if ($notice)
        <div class="alert notice" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <h1>CSR for <code>{{ $request->common_name }}</code></h1>
        <p class="muted"><a href="/admin/csr">All requests</a>. Made {{ \App\Utils\LocalTime::format($request->created_at) }} by {{ $request->user?->username ?? '—' }}{{ $request->certificate ? ', to replace ' . $request->certificate->name : '' }}.</p>
        <dl class="csr-details">
            <dt>Names</dt><dd>{{ implode(', ', $request->names) }}</dd>
            @foreach ($request->subject as $field => $value)
                <dt>{{ \App\Services\CsrService::SUBJECT[$field] ?? $field }}</dt><dd>{{ $value }}</dd>
            @endforeach
            <dt>Private key</dt><dd>{{ $request->keyLabel() }}, {{ $request->key_source === 'new' ? 'new, kept here encrypted' : 'your existing key (not kept here)' }}</dd>
        </dl>
    </div>

    <div class="card">
        <h2>The request (CSR)</h2>
        <p class="hint">Give this to your certificate authority. It holds no secret.</p>
        <pre class="pem" id="csr-pem">{{ $request->csr }}</pre>
        <div class="actions">
            <a class="button" href="/admin/csr/{{ $request->id }}/csr">Download .csr</a>
            <button type="button" class="secondary" data-copy="csr-pem">Copy</button>
        </div>
    </div>

    @if ($request->key_source === 'new')
        <form method="post" action="/admin/csr/{{ $request->id }}/key" class="card" id="csr-key" data-confirm-dialog="Download the private key for {{ $request->common_name }}?">
            @csrf
            <h2>The new private key</h2>
            <p class="hint">Install it on the server with the certificate the authority sends back. Anyone who has it can pose as the site, so downloading it asks for your password or a passkey; delete the request once it's installed.</p>
            <div class="actions"><button type="submit">Download .key</button></div>
        </form>
    @endif

    <form method="post" action="/admin/csr/{{ $request->id }}/delete" class="card" data-confirm="Delete this request{{ $request->key_source === 'new' ? ' and its private key' : '' }}? This can't be undone.">
        @csrf
        <h2>Delete</h2>
        <p class="hint">{{ $request->key_source === 'new' ? 'Removes the request and the private key kept with it. Download the key first if it isn\'t installed yet.' : 'Removes the request.' }}</p>
        <div class="actions"><button type="submit" class="danger">Delete</button></div>
    </form>

    @include('partials.confirm-dialog')
    <script src="{{ \App\Utils\Asset::url('/assets/js/passkeys.js') }}"></script>
    <script>
        document.querySelectorAll('[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                navigator.clipboard.writeText(document.getElementById(button.getAttribute('data-copy')).textContent.trim()).then(function () {
                    button.textContent = 'Copied';
                    setTimeout(function () { button.textContent = 'Copy'; }, 1500);
                });
            });
        });
    </script>
    <style>
        pre.pem { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 12px; background: var(--code-bg); padding: 10px 12px; border-radius: 6px; }
        dl.csr-details { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 8px 0 0; }
        dl.csr-details dt { font-weight: 600; }
        dl.csr-details dd { margin: 0; overflow-wrap: anywhere; }
    </style>
@endsection
