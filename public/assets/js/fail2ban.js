// Right-click a client address in the access log to ban or unban it with fail2ban on the report's server
// (reports/apache-data). The jails are read from the server when the dialog opens; the change is a JSON
// POST with the page's CSRF token, like the passkey requests.
(function () {
    var menu = document.getElementById('ban-menu');
    var dialog = document.getElementById('ban-dialog');
    if (!menu || !dialog || typeof dialog.showModal !== 'function') { return; }

    var server = dialog.getAttribute('data-server');
    var jail = document.getElementById('ban-jail');
    var go = document.getElementById('ban-go');
    var error = document.getElementById('ban-error');
    var done = document.getElementById('ban-done');
    var current = { ip: null, jails: [], action: 'ban' };

    var csrf = function () {
        var input = document.querySelector('input[name="X-Leaf-CSRF-Token"]');
        return input ? input.value : '';
    };
    var show = function (element, text) { element.textContent = text || ''; element.hidden = !text; };
    var closeMenu = function () { menu.hidden = true; };

    document.addEventListener('contextmenu', function (event) {
        var cell = event.target.closest('.client-ip');
        if (!cell) { closeMenu(); return; }
        event.preventDefault();
        current.ip = cell.getAttribute('data-ip');
        current.jails = (cell.getAttribute('data-jails') || '').split(',').filter(Boolean);
        menu.querySelectorAll('[data-ban-ip]').forEach(function (span) { span.textContent = current.ip; });
        menu.hidden = false;
        // Keep the menu on screen.
        var x = Math.min(event.clientX, window.innerWidth - menu.offsetWidth - 8);
        var y = Math.min(event.clientY, window.innerHeight - menu.offsetHeight - 8);
        menu.style.left = Math.max(8, x) + 'px';
        menu.style.top = Math.max(8, y) + 'px';
        menu.querySelector('button').focus();
    });
    document.addEventListener('click', function (event) { if (!menu.contains(event.target)) { closeMenu(); } });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { closeMenu(); } });
    window.addEventListener('scroll', closeMenu, true);

    menu.querySelectorAll('[data-ban-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeMenu();
            open(button.getAttribute('data-ban-action'));
        });
    });

    function open(action) {
        current.action = action;
        document.getElementById('ban-title').textContent = (action === 'ban' ? 'Ban ' : 'Unban ') + current.ip;
        document.getElementById('ban-where').textContent = (action === 'ban' ? 'fail2ban on ' : 'Lift the ban in fail2ban on ') + dialog.getAttribute('data-server-name')
            + (current.jails.length ? '. Banned now in: ' + current.jails.join(', ') + '.' : '.');
        go.textContent = action === 'ban' ? 'Ban' : 'Unban';
        go.className = action === 'ban' ? 'danger' : '';
        go.disabled = true;
        show(error, ''); show(done, '');
        jail.innerHTML = '<option>Loading jails…</option>';
        dialog.showModal();

        fetch('/admin/servers/' + server + '/fail2ban/jails', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error) { throw new Error(data.error); }
                jail.innerHTML = '';
                var jails = data.jails || [];
                // Unban: a jail it's banned in. Ban: web-abusers (extras/fail2ban) when it isn't in it yet, else one it isn't in.
                var pick = action === 'unban'
                    ? jails.filter(function (name) { return current.jails.indexOf(name) !== -1; })[0]
                    : (jails.indexOf('web-abusers') !== -1 && current.jails.indexOf('web-abusers') === -1 ? 'web-abusers' : jails.filter(function (name) { return current.jails.indexOf(name) === -1; })[0]);
                jails.forEach(function (name) {
                    var option = document.createElement('option');
                    option.value = name; option.textContent = name;
                    option.selected = name === pick;
                    jail.appendChild(option);
                });
                if (!data.jails || !data.jails.length) { throw new Error('fail2ban has no jails on this server.'); }
                go.disabled = false;
                jail.focus();
            })
            .catch(function (e) { jail.innerHTML = ''; show(error, e.message); });
    }

    go.addEventListener('click', function () {
        go.disabled = true;
        show(error, ''); show(done, '');
        fetch('/admin/servers/' + server + '/fail2ban', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ action: current.action, ip: current.ip, jail: jail.value }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error) { throw new Error(data.error); }
                show(done, data.message);
                // Mark every row of this address.
                document.querySelectorAll('.client-ip[data-ip="' + CSS.escape(current.ip) + '"]').forEach(function (cell) {
                    var jails = (cell.getAttribute('data-jails') || '').split(',').filter(Boolean);
                    jails = current.action === 'ban' ? jails.concat(jails.indexOf(jail.value) === -1 ? [jail.value] : []) : jails.filter(function (j) { return j !== jail.value; });
                    cell.setAttribute('data-jails', jails.join(','));
                    var badge = cell.parentNode.querySelector('.badge');
                    if (!badge && jails.length) {
                        badge = document.createElement('span');
                        badge.className = 'badge critical';
                        cell.parentNode.appendChild(document.createTextNode(' '));
                        cell.parentNode.appendChild(badge);
                    }
                    if (badge) { badge.textContent = 'banned: ' + jails.join(', '); badge.hidden = !jails.length; }
                });
            })
            .catch(function (e) { show(error, e.message); go.disabled = false; });
    });

    document.getElementById('ban-cancel').addEventListener('click', function () { dialog.close(); });
})();
