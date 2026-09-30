@extends('layouts.app')

@section('title', 'Edit Apache configuration')
@section('width', 'wide')

@section('content')
    <div class="card">
        <h1>{{ $kind === 'main' ? 'apache2.conf' : ($kind === 'ports' ? 'ports.conf' : \App\Services\LocalApacheService::KINDS[$kind] ?? $kind) }} @if ($name)<code>{{ $name }}</code>@endif</h1>
        <p class="muted">Saving backs the file up, writes it, and runs Apache's configuration test; if the test fails, the old file is put back. Reload Apache afterwards to apply it.</p>

        @if ($error)
            <div class="alert error" role="alert"><pre class="log-message">{{ $error }}</pre></div>
        @endif

        <form method="post" action="/admin/apache/local/edit" id="edit">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <input type="hidden" name="name" value="{{ $name }}">
            <label for="content">Contents</label>
            <textarea id="content" name="content" rows="28" spellcheck="false" style="font-family: ui-monospace, monospace; font-size: 13px">{{ $content }}</textarea>

            @include('partials.confirm-fields', ['formId' => 'edit', 'what' => 'Save'])

            <div class="actions">
                <button type="submit">Save</button>
                <a class="button secondary-link" href="/admin/apache/local">Cancel</a>
            </div>
        </form>
    </div>
    <script src="{{ \App\Utils\Asset::url('/assets/js/passkeys.js') }}"></script>
@endsection
