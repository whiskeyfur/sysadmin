@extends('layouts.app')

@section('title', 'Apache on this server')
@section('width', 'wide')

@section('content')
    <div class="card">
        <h1>Apache on this server</h1>
        <p class="muted">This machine's own Apache (<code>/etc/apache2</code>), through the root helper. Every change is checked with <code>apache2ctl configtest</code> first and undone if Apache refuses it; files are backed up to <code>/var/backups/sys-apache</code>. Changes apply when Apache reloads. Everything done here is logged below.</p>

        @if ($notice)
            <div class="alert notice" role="status">{{ $notice }}</div>
        @endif
        @if ($error)
            <div class="alert error" role="alert"><pre class="log-message">{{ $error }}</pre></div>
        @endif
        @if ($output)
            <pre class="log-message">{{ $output }}</pre>
        @endif

        @unless ($status['ready'])
            <div class="alert error" role="alert">{{ $status['problem'] }}</div>
            <p class="hint">The helper is the only part of sys that runs as root; it does only the Apache actions on this page. Installing it adds one sudoers rule letting <code>www-data</code> run it (<code>/etc/sudoers.d/sys-apache-helper</code>).</p>
        @endunless
    </div>

    @if ($overview)
        <form method="post" action="/admin/apache/local/change" id="change">
            @csrf
            {{-- Changes are confirmed in this dialog (the script below opens it for every change button but "Test").
                 It comes first in the form so Enter in its fields presses its own button. --}}
            <dialog id="confirm-dialog" aria-labelledby="confirm-title">
                <h2 id="confirm-title">Confirm the change</h2>
                <p id="confirm-ask"></p>
                <p class="muted">Each change needs a fresh check with one of your sign-in methods (an authenticator code works once: wait for the next one between changes).</p>
                <input type="hidden" name="change" id="confirm-change" disabled>
                @include('partials.confirm-fields', ['formId' => 'change', 'what' => 'Confirm', 'passkeyTarget' => 'change'])
                <div class="actions">
                    <button type="submit" id="confirm-go" formnovalidate>Confirm</button>
                    <button type="button" class="secondary" id="confirm-cancel">Cancel</button>
                </div>
            </dialog>

            <div class="card">
                <div class="actions" style="margin-top: 0; justify-content: space-between">
                    <h2>{{ $overview['version'] ?? 'Apache' }} <span class="badge {{ ($overview['state'] ?? '') === 'active' ? 'ok' : 'critical' }}">{{ $overview['state'] ?? 'unknown' }}</span></h2>
                    <div class="row-actions">
                        <button type="submit" name="change" value="test" class="secondary" formnovalidate>Test configuration</button>
                        <button type="submit" name="change" value="reload" data-ask="Reload Apache (graceful: open requests finish)?">Reload</button>
                        <button type="submit" name="change" value="restart" class="secondary danger" data-ask="Restart Apache? Every open connection is dropped, including this page's.">Restart</button>
                    </div>
                </div>
            </div>

            <details class="card section" data-remember="config" open>
                <summary><h2>Config</h2></summary>
                <p class="muted">The whole configuration, the way Apache reads it: apache2.conf line by line, each Include opened where it is, in the order Apache reads the files. Greyed: inside an &lt;IfModule&gt; or the like that doesn't apply (Apache also doesn't follow an Include there). Change a value and save (each changed file is checked with the configuration test like any other change); use Edit on a file for anything else.</p>
                <div class="actions" style="justify-content: space-between">
                    <label class="check" style="margin: 0"><input type="checkbox" id="cfg-hide"> Hide comments and blank lines</label>
                    <div class="row-actions">
                        <a class="button" href="/admin/apache/local/new">New site</a>
                        <a class="button secondary-link" href="/admin/apache/local/rewrite">Rewrite and access rules</a>
                        <a class="button secondary-link" href="/admin/apache/local/simulate">URL simulator</a>
                    </div>
                </div>
                <div class="cfg" id="cfg">
                    @foreach ($config as $row)
                        @switch($row['type'])
                            @case('file')
                                <details class="cfg-file" {{ str_contains($row['short'], 'mods-enabled/') ? '' : 'open' }}>
                                    <summary>
                                        <code>{{ $row['short'] }}</code>
                                        @if ($row['edit'])
                                            <a href="/admin/apache/local/edit?kind={{ $row['edit'][0] }}&amp;name={{ urlencode($row['edit'][1]) }}">Edit</a>
                                        @endif
                                        @if ($row['disable'])
                                            <button type="submit" name="change" value="disable:{{ $row['disable']['kind'] }}:{{ $row['disable']['name'] }}" class="link danger" data-ask="Disable {{ $row['disable']['kind'] }} {{ $row['disable']['name'] }}?">Disable {{ $row['disable']['kind'] === 'mod' ? 'module' : ($row['disable']['kind'] === 'conf' ? 'snippet' : 'site') }}</button>
                                        @endif
                                    </summary>
                                    <input type="hidden" name="config_hash[{{ $row['file'] }}]" value="{{ $row['hash'] }}">
                                @break
                            @case('end')
                                </details>
                                @break
                            @case('line')
                                <div class="cfg-line {{ $row['kind'] }} {{ $row['active'] ? '' : 'inactive' }}">
                                    <span class="cfg-no">{{ $row['line'] }}</span>
                                    @if ($row['editable'])
                                        <span class="cfg-text">{{ preg_match('/^\s*/', $row['text'], $m) ? $m[0] : '' }}{{ $row['name'] }} </span><input type="text" name="config[{{ $row['file'] }}][{{ $row['line'] }}]" value="{{ $row['value'] }}" aria-label="{{ $row['name'] }}, line {{ $row['line'] }} of {{ $row['file'] }}" autocapitalize="none" spellcheck="false">
                                    @else
                                        <span class="cfg-text">{{ $row['text'] }}</span>
                                    @endif
                                    @foreach ($row['links'] as $link)
                                        <a class="cfg-link" href="{{ $link['href'] }}">{{ $link['label'] }}</a>
                                    @endforeach
                                </div>
                                @break
                            @case('note')
                                <div class="cfg-note muted">{{ $row['text'] }}</div>
                                @break
                            @case('disabled')
                                @if ($row['items'])
                                    <div class="cfg-disabled">
                                        <span class="muted">Not enabled {{ ['site' => 'sites', 'conf' => 'snippets', 'mod' => 'modules'][$row['kind']] }}:</span>
                                        @foreach ($row['items'] as $item)
                                            <span class="cfg-item"><code>{{ $item }}</code>
                                                @if ($row['kind'] !== 'mod')
                                                    <a href="/admin/apache/local/edit?kind={{ $row['kind'] }}&amp;name={{ urlencode($item) }}">Edit</a>
                                                @endif
                                                <button type="submit" name="change" value="enable:{{ $row['kind'] }}:{{ $item }}" class="link" data-ask="Enable {{ $row['kind'] }} {{ $item }}?">Enable</button>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                                @break
                        @endswitch
                    @endforeach
                </div>
                <div class="actions">
                    <button type="submit" name="change" value="config" data-ask="Save the changed values? Each changed file is checked with the configuration test.">Save changes</button>
                </div>
            </details>
        </form>

        <details class="card section" data-remember="errorlog" open>
            <summary><h2>Error log</h2></summary>
            @if ($errorLog)
                <p class="hint">The last {{ count($errorLog) }} lines, newest first. Long lines are cut at 300 characters: click one for all of it.</p>
                <div class="log-box">
                    @foreach (array_reverse($errorLog) as $line)
                        @if (mb_strlen($line) > 300)
                            <details class="log-line"><summary><code>{{ mb_substr($line, 0, 300) }}…</code> <span class="muted">({{ number_format(mb_strlen($line)) }} characters)</span></summary><pre class="log-message">{{ $line }}</pre></details>
                        @else
                            <div class="log-line"><code>{{ $line }}</code></div>
                        @endif
                    @endforeach
                </div>
            @else
                <p class="muted">Empty.</p>
            @endif
        </details>
    @endif

    <details class="card section" data-remember="changes">
        <summary><h2>Changes made here <span class="muted">({{ count($changes) }})</span></h2></summary>
        @if (count($changes) === 0)
            <p class="muted">None yet.</p>
        @else
            <div class="table-wrap">
            <table class="top">
                <thead><tr><th>When</th><th>Who</th><th>Change</th><th>Outcome</th></tr></thead>
                <tbody>
                    @foreach ($changes as $change)
                        <tr>
                            <td data-sort="{{ $change->created_at->getTimestamp() }}" style="white-space: nowrap">{{ \App\Utils\LocalTime::format($change->created_at, 'Y-m-d H:i:s') }}</td>
                            <td>{{ $change->user->username ?? '?' }}</td>
                            <td>{{ $change->action }} {{ $change->target }}</td>
                            <td><span class="badge {{ $change->ok ? 'ok' : 'critical' }}">{{ $change->ok ? 'Done' : 'Refused' }}</span>
                                @if ($change->output)
                                    <pre class="log-message">{{ \Illuminate\Support\Str::limit($change->output, 600) }}</pre>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </details>

    <style>
        details.section > summary { cursor: pointer; list-style: none; display: flex; align-items: center; gap: 8px; }
        details.section > summary::-webkit-details-marker { display: none; }
        details.section > summary::before { content: '▸'; color: var(--muted); transition: transform .15s; }
        details.section[open] > summary::before { transform: rotate(90deg); }
        details.section > summary h2 { margin: 0; }
        details.section[open] > summary { margin-bottom: 12px; }
        .cfg { font-family: ui-monospace, monospace; font-size: 12.5px; line-height: 1.5; overflow-x: auto; }
        .cfg-file { margin: 2px 0 2px 14px; border-left: 2px solid var(--line); padding-left: 8px; }
        #cfg > .cfg-file { margin-left: 0; }
        .cfg-file > summary { cursor: pointer; padding: 3px 0; font-family: inherit; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .cfg-file > summary code { font-weight: 600; }
        .cfg-line { display: flex; align-items: baseline; white-space: pre; min-height: 1.5em; }
        .cfg-no { color: var(--muted); min-width: 3.5em; text-align: right; padding-right: 1em; user-select: none; flex: none; }
        .cfg-text { white-space: pre; }
        .cfg-line input { flex: 1; min-width: 12em; padding: 0 6px; font: inherit; height: 1.5em; margin: 1px 0; }
        .cfg-line.comment .cfg-text { color: var(--muted); }
        .cfg-line.inactive { opacity: .45; }
        .cfg-link { margin-left: 12px; font-family: system-ui, sans-serif; font-size: 12px; white-space: nowrap; }
        .cfg-note, .cfg-disabled { margin: 2px 0 2px 4.5em; font-family: system-ui, sans-serif; font-size: 12.5px; }
        .cfg-item { margin-right: 14px; white-space: nowrap; }
        button.link { background: none; border: 0; padding: 0; color: var(--accent, #2457c5); text-decoration: underline; cursor: pointer; font: inherit; font-size: 12.5px; }
        button.link.danger { color: var(--error); }
        .cfg.hide-comments .cfg-line.comment, .cfg.hide-comments .cfg-line.blank { display: none; }
        #confirm-dialog { width: min(460px, calc(100vw - 32px)); border: 1px solid var(--line); border-radius: 10px; background: var(--panel); color: var(--text); padding: 24px; box-shadow: 0 12px 40px rgba(0, 0, 0, .25); }
        #confirm-dialog::backdrop { background: rgba(0, 0, 0, .45); }
        #confirm-dialog h2 { margin-top: 0; }
        #confirm-dialog input[type=password], #confirm-dialog input[type=text] { width: 100%; box-sizing: border-box; }
        .log-box { max-height: 420px; overflow: auto; border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; }
        .log-line { padding: 3px 0; border-bottom: 1px solid var(--line); font-size: 12px; overflow-wrap: anywhere; }
        .log-line:last-child { border-bottom: 0; }
        .log-line code { background: none; padding: 0; white-space: pre-wrap; }
        .log-line summary { cursor: pointer; }
    </style>
    <script>
        // Every change but "Test" asks for the confirmation in a dialog; its Confirm button sends the chosen change.
        (function () {
            var form = document.getElementById('change'), dialog = document.getElementById('confirm-dialog');
            if (!form || !dialog || typeof dialog.showModal !== 'function') { return; }
            var change = document.getElementById('confirm-change'), go = document.getElementById('confirm-go');
            var open = function (button) {
                change.value = button.value;
                change.disabled = false;
                document.getElementById('confirm-ask').textContent = button.getAttribute('data-ask') || button.textContent.trim() + '?';
                dialog.showModal();
                var first = dialog.querySelector('input[type=password], input[type=text], button[data-passkey-confirm]');
                (first || go).focus();
            };
            form.addEventListener('submit', function (event) {
                var button = event.submitter;
                if (button === go) {
                    // Enter in a config value with the dialog closed means "Save changes".
                    if (!dialog.open) { event.preventDefault(); open(form.querySelector('button[value="config"]')); }
                    return;
                }
                if (!button || button.value === 'test') { return; }
                event.preventDefault();
                open(button);
            });
            dialog.addEventListener('close', function () {
                change.disabled = true;
                dialog.querySelectorAll('input[name=password], input[name=code]').forEach(function (input) { input.value = ''; });
            });
            document.getElementById('confirm-cancel').addEventListener('click', function () { dialog.close(); });
        })();

        // Hide comments and blank lines (remembered per browser).
        (function () {
            var box = document.getElementById('cfg-hide'), cfg = document.getElementById('cfg');
            if (!box || !cfg) { return; }
            try { box.checked = localStorage.getItem('sys-apache-local:hide-comments') === '1'; } catch (e) {}
            var apply = function () { cfg.classList.toggle('hide-comments', box.checked); };
            box.addEventListener('change', function () { apply(); try { localStorage.setItem('sys-apache-local:hide-comments', box.checked ? '1' : '0'); } catch (e) {} });
            apply();
        })();

        // Remember which sections are open (per browser; the page works the same without it).
        document.querySelectorAll('details.section[data-remember]').forEach(function (section) {
            var key = 'sys-apache-local:' + section.getAttribute('data-remember');
            try {
                var saved = localStorage.getItem(key);
                if (saved !== null) { section.open = saved === '1'; }
            } catch (e) {}
            section.addEventListener('toggle', function () {
                try { localStorage.setItem(key, section.open ? '1' : '0'); } catch (e) {}
            });
        });
    </script>
    <script src="/assets/js/passkeys.js"></script>
@endsection
