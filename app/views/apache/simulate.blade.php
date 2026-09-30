@extends('layouts.app')

@section('title', 'URL simulator')
@section('width', 'wide')

@section('content')
    <div class="card">
        <h1>URL simulator</h1>
        <p class="muted">Where a URL ends up on this machine's Apache, worked out from its configuration and files: which virtual host takes it, what the rewrite rules, redirects and aliases do, the &lt;Directory&gt; sections and .htaccess files on the way, access rules, index files and fallbacks. No request is sent. Checked against a real Apache for the cases in <code>tests/checks/ApacheSimulator.test.php</code>; what it can't know is said below the result.</p>
        <form method="get" action="/admin/apache/local/simulate">
            @include('apache.simulate-form', ['name' => 'url'])
            <div class="actions">
                <button type="submit">Simulate</button>
                <a class="button secondary-link" href="/admin/apache/local/rewrite">Rewrite rules</a>
            </div>
        </form>
    </div>

    @if ($trace)
        <div class="card">
            <h2>{{ $url }}</h2>
            @include('apache.trace')
        </div>
    @endif
@endsection
