@extends('layouts.app')

@section('width', 'wide')
@section('title', 'Virtual hosts')

@section('content')
    <div class="card">
        <h1>Virtual hosts</h1>
        @if ($error)
            <div class="alert error" role="alert">{{ $error }}</div>
        @endif
        <p class="muted">The &lt;VirtualHost&gt; blocks in each server's Apache configuration, found when Apache is scanned (hourly with its checks, or Rescan on the <a href="/apache">Apache</a> page) and removed when they leave the configuration. SSL shows whether a certificate in <a href="/ssl">SSL monitoring</a> covers the name.</p>

        @if (count($vhosts) === 0)
            <p class="muted">None found yet. Turn on Apache monitoring for a server and run its checks.</p>
        @else
            <div class="table-wrap">
            <table class="top">
                <thead>
                    <tr><th>Name</th><th>Server</th><th>Address</th><th>SSL</th><th>Document root</th><th>Logs</th><th>Found</th><th data-nosort></th></tr>
                </thead>
                <tbody>
                    @foreach ($vhosts as $vhost)
                        @php($aliases = $vhost->aliasList())
                        @php($certificate = $certificates[$vhost->id] ?? null)
                        <tr>
                            <td>
                                @if ($vhost->name)
                                    {{ $vhost->name }}
                                @else
                                    <span class="muted">No ServerName</span>
                                @endif
                                @if ($aliases)
                                    <div class="muted">{{ implode(', ', $aliases) }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($vhost->server)
                                    <a href="/servers/{{ $vhost->server->id }}">{{ $vhost->server->name }}</a>
                                @endif
                            </td>
                            <td data-sort="{{ $vhost->port ?? 0 }}"><code>{{ $vhost->address }}</code></td>
                            <td data-sort="{{ $certificate ? (\App\Enums\HealthStatus::tryFrom((string) $certificate->last_status)?->severity() ?? 0) + 10 : ($vhost->ssl ? 1 : 0) }}">
                                @if ($certificate)
                                    <a href="/ssl/{{ $certificate->id }}">{{ $certificate->name }}</a>
                                    @if ($certificate->last_status)
                                        <span class="badge {{ $certificate->last_status }}">{{ ucfirst($certificate->last_status) }}</span>
                                    @endif
                                @elseif ($vhost->ssl)
                                    <span class="muted">Not monitored</span>
                                    @if ($auth->isAdmin() && $vhost->name && !str_contains($vhost->name, '*'))
                                        <div><a href="/admin/ssl/new?{{ http_build_query(['name' => $vhost->name, 'hostnames' => implode("\n", array_merge([$vhost->name], array_filter($aliases, fn ($a) => $a !== $vhost->name))), 'server_id' => $vhost->server_id, 'port' => $vhost->port ?? 443]) }}">Monitor</a></div>
                                    @endif
                                @else
                                    <span class="muted">No</span>
                                @endif
                            </td>
                            <td><code>{{ $vhost->document_root ?? '' }}</code></td>
                            <td>
                                @foreach ($vhost->accessLogList() as $log)
                                    <div><code>{{ $log }}</code></div>
                                @endforeach
                                @if ($vhost->error_log)
                                    <div><code>{{ $vhost->error_log }}</code></div>
                                @endif
                                @if (!$vhost->accessLogList() && !$vhost->error_log)
                                    <span class="muted">The main server's</span>
                                @endif
                            </td>
                            <td data-sort="{{ $vhost->first_seen_at->getTimestamp() }}" style="white-space: nowrap">{{ \App\Utils\LocalTime::format($vhost->first_seen_at, 'Y-m-d') }}</td>
                            <td class="row-actions">
                                <a href="/vhosts/reports?vhost={{ $vhost->id }}">Report</a>
                                @if ($auth->isAdmin() && \App\Middleware\RequireLocalAdmin::fromThisMachine() && $vhost->config_file && $vhost->server?->sshReady())
                                    <a href="/admin/vhost?id=vhost:{{ $vhost->id }}&amp;back=vhosts">Edit</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
@endsection
