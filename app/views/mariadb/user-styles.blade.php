{{-- Shared styles and script for the database account manager's pages. --}}
<style>
    .user-servers { display: flex; flex-wrap: wrap; gap: 6px 18px; border: 1px solid var(--line); border-radius: 8px; padding: 10px 14px; margin: 0 0 12px; }
    .user-servers legend { font-weight: 600; padding: 0 6px; }
    label.check { display: inline-flex; gap: 6px; align-items: center; font-weight: normal; margin: 6px 0; }
    .field-row { display: flex; flex-wrap: wrap; gap: 0 16px; }
    .field-row > div { flex: 1 1 220px; }
    .password-row { display: flex; gap: 8px; align-items: center; }
    .password-row input { flex: 1; font-family: ui-monospace, monospace; }
    ul.results { list-style: none; padding: 0; margin: 0; }
    ul.results li { padding: 4px 0; overflow-wrap: anywhere; }
    .grants code { display: block; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 12px; margin: 2px 0; }
</style>
<script>
    (function () {
        // Server boxes: all / none.
        document.querySelectorAll('.user-servers').forEach(function (set) {
            var boxes = set.querySelectorAll('input[type=checkbox]');
            set.querySelector('[data-check-all]')?.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = true; }); });
            set.querySelector('[data-check-none]')?.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); });
        });
        // Generate a password here (shown, so it can be copied), or show what was typed.
        var alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_';
        document.querySelectorAll('[data-generate]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-generate'));
                var bytes = new Uint32Array(24), password = '';
                crypto.getRandomValues(bytes);
                bytes.forEach(function (n) { password += alphabet[n % alphabet.length]; });
                input.value = password;
                input.type = 'text';
            });
        });
        // Database fields suggest the ticked servers' databases, and after "db." that database's tables and
        // views (GET /mariadb/users/names, through the monitoring accounts), each list fetched once.
        var names = {};
        document.querySelectorAll('input[name=database]').forEach(function (input, i) {
            var form = input.form, list = document.createElement('datalist');
            list.id = 'database-names-' + i;
            input.after(list);
            input.setAttribute('list', list.id);
            input.setAttribute('autocomplete', 'off');
            var shown = null;
            var suggest = function () {
                var servers = [].map.call(form.querySelectorAll('input[name="servers[]"]:checked'), function (b) { return b.value; });
                var dot = input.value.indexOf('.'), db = dot > 0 ? input.value.slice(0, dot) : '';
                var key = servers.join(',') + '|' + db;
                if (servers.length === 0) { list.textContent = ''; shown = null; return; }
                if (key === shown) { return; }
                shown = key;
                var params = new URLSearchParams(servers.map(function (id) { return ['servers[]', id]; }));
                if (db) { params.set('db', db); }
                names[key] = names[key] || fetch('/mariadb/users/names?' + params, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then(function (response) { return response.json(); })
                    .then(function (data) { return data.names || []; })
                    .catch(function () { return []; });
                names[key].then(function (found) {
                    if (shown !== key) { return; }
                    list.textContent = '';
                    (db ? [db + '.*'].concat(found.map(function (t) { return db + '.' + t; })) : ['*'].concat(found)).forEach(function (value) {
                        var option = document.createElement('option');
                        option.value = value;
                        list.appendChild(option);
                    });
                });
            };
            input.addEventListener('focus', suggest);
            input.addEventListener('input', suggest);
            form.querySelectorAll('input[name="servers[]"]').forEach(function (box) { box.addEventListener('change', function () { shown = null; }); });
        });
        document.querySelectorAll('[data-reveal]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-reveal'));
                input.type = input.type === 'password' ? 'text' : 'password';
                button.textContent = input.type === 'password' ? 'Show' : 'Hide';
            });
        });
    })();
</script>
