{{-- The fields of a query-tool account (add or edit): name, username, password, the servers it's for,
     and, when a save was refused, how each server answered. Needs $servers, $values (label, username),
     $chosen (server ids), $action, $button, $passwordHint and optionally $tested. The password is never
     filled in. --}}
<form method="post" action="{{ $action }}" class="account-form">
    @csrf
    @if (!empty($tested))
        <ul class="tested">
            @foreach ($tested as $result)
                <li><span class="badge {{ $result['ok'] ? 'ok' : 'critical' }}">{{ $result['ok'] ? 'Accepted' : 'Refused' }}</span> <strong>{{ $result['server']->name }}</strong>: {{ $result['message'] }}</li>
            @endforeach
        </ul>
    @endif
    <div class="account-grid">
        <div>
            <label for="account_label">Name <span class="muted">(yours, e.g. "Shop, read only")</span></label>
            <input type="text" id="account_label" name="label" value="{{ $values['label'] ?? '' }}" maxlength="{{ \App\Services\QueryAccountService::MAX_LABEL }}" required>
        </div>
        <div>
            <label for="account_username">Database username</label>
            <input type="text" id="account_username" name="username" value="{{ $values['username'] ?? '' }}" autocomplete="off" spellcheck="false" required>
        </div>
        <div>
            <label for="account_password">Password</label>
            <input type="password" id="account_password" name="password" autocomplete="new-password">
            <p class="hint">{{ $passwordHint ?? 'Leave blank for an account without a password.' }}</p>
        </div>
    </div>
    <fieldset class="account-servers">
        <legend>The servers it's good on</legend>
        @forelse ($servers as $server)
            <label class="check"><input type="checkbox" name="servers[]" value="{{ $server->id }}" {{ in_array($server->id, array_map('intval', $chosen), true) ? 'checked' : '' }}> {{ $server->name }} <span class="muted">{{ $server->mysqlHost() }}:{{ $server->mysql_port }}</span></label>
        @empty
            <p class="muted">No server has MariaDB/MySQL monitoring set up.</p>
        @endforelse
    </fieldset>
    <p class="hint">The login is tried on each server ticked; it's saved only when all of them accept it. If the first one refuses it, the others aren't tried.</p>
    <div class="actions">
        <button type="submit">{{ $button }}</button>
    </div>
</form>
<style>
    .account-grid { display: flex; flex-wrap: wrap; gap: 0 16px; }
    .account-grid > div { flex: 1 1 200px; }
    .account-servers { display: flex; flex-wrap: wrap; gap: 6px 18px; border: 1px solid var(--line); border-radius: 8px; padding: 10px 14px; margin: 8px 0; }
    .account-servers legend { font-weight: 600; padding: 0 6px; }
    .account-servers label.check { display: inline-flex; gap: 6px; align-items: center; font-weight: normal; margin: 0; }
    ul.tested { list-style: none; padding: 0; margin: 0 0 12px; }
    ul.tested li { padding: 3px 0; overflow-wrap: anywhere; }
</style>
