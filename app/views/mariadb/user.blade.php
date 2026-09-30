@extends('layouts.app')

@section('title', "'$user'@'$host'")
@section('width', 'wide')

@section('content')
    @if ($error)
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    <div class="card">
        <h1><code>{{ "'$user'@'$host'" }}</code></h1>
        <p class="muted"><a href="/mariadb/users">All database users</a>. Changes are made on the servers you tick, each on its own; every one needs a fresh check with your sign-in.</p>
    </div>

    @if ($results !== null)
        <div class="card">
            <h2>{{ $what }}</h2>
            <ul class="results">
                @foreach ($results as $result)
                    <li><span class="badge {{ $result['ok'] ? 'ok' : 'critical' }}">{{ $result['ok'] ? 'Done' : 'Failed' }}</span> <strong>{{ $result['server']->name }}</strong>: {{ $result['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php($here = array_values(array_filter($servers, fn ($s) => $grants[$s->id] !== null)))
    <div class="card">
        <h2>Grants</h2>
        @if ($servers === [])
            <p class="muted">No server's monitoring account can manage accounts.</p>
        @endif
        <div class="table-wrap">
        <table class="top grants">
            <thead><tr><th>Server</th><th>Grants</th></tr></thead>
            <tbody>
                @foreach ($servers as $server)
                    <tr>
                        <td>{{ $server->name }}</td>
                        <td>
                            @if ($grants[$server->id] === null)
                                <span class="muted">Not on this server.</span>
                            @else
                                @foreach ($grants[$server->id] as $grant)
                                    {{-- Password hashes aren't shown. --}}
                                    <code>{{ preg_replace("/(IDENTIFIED (?:BY PASSWORD|VIA \\S+ USING)\\s+)'[^']*'/i", "\$1'…'", $grant) }}</code>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    @if ($here !== [])
        @php($ids = array_map(fn ($s) => $s->id, $here))
        @foreach ([
            'grant' => ['Set access to a database', 'Set access', 'What it had on that database is replaced by the level chosen.'],
            'revoke' => ['Remove access to a database', 'Remove access', 'Everything it has on that database is taken away.'],
            'password' => ['Change the password', 'Change the password', null],
            'drop' => ['Drop the account', 'Drop', 'The account is removed from the servers ticked; its entry in Accounts goes too once no account of that name is left on a server.'],
        ] as $action => [$heading, $button, $about])
            <form method="post" action="/mariadb/users/account" class="card" id="action-{{ $action }}" @if ($action === 'drop') data-confirm="Drop {{ "'$user'@'$host'" }} from the servers ticked?" @endif>
                @csrf
                <input type="hidden" name="user" value="{{ $user }}">
                <input type="hidden" name="host" value="{{ $host }}">
                <input type="hidden" name="action" value="{{ $action }}">
                <h2>{{ $heading }}</h2>
                @if ($about)
                    <p class="hint">{{ $about }}</p>
                @endif
                @include('mariadb.user-servers', ['servers' => $here, 'chosen' => $ids, 'legend' => 'On these servers'])
                @if ($action === 'grant' || $action === 'revoke')
                    <div class="field-row">
                        <div>
                            <label for="{{ $action }}_database">Database</label>
                            <input type="text" id="{{ $action }}_database" name="database" spellcheck="false" placeholder="* for all databases" required>
                        </div>
                        @if ($action === 'grant')
                            <div>
                                <label for="grant_level">Access</label>
                                <select id="grant_level" name="level">
                                    @foreach (\App\Services\DbUserManagerService::LEVELS as $key => [$label, $privileges])
                                        <option value="{{ $key }}">{{ $label }} ({{ $privileges }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                @elseif ($action === 'password')
                    @include('mariadb.user-password', ['id' => 'change_password'])
                    <label class="check"><input type="checkbox" name="track" value="1" checked> Keep the new password in Accounts</label>
                @endif
                @include('partials.confirm-fields', ['formId' => "action-$action", 'what' => $button, 'passkeyTarget' => "action-$action"])
                <div class="actions"><button type="submit" @if ($action === 'drop') class="danger" @endif>{{ $button }}</button></div>
            </form>
        @endforeach
    @endif

    @include('mariadb.user-changes', ['changes' => $changes])
    @include('mariadb.user-styles')
    <script src="{{ \App\Utils\Asset::url('/assets/js/passkeys.js') }}"></script>
@endsection
