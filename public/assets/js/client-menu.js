// Right-click menus in the access log (reports/apache-data). A client address (.client-ip): show only
// its requests, and, for admins, ban or unban it (fail2ban.js handles those items; the menu keeps the
// address and the table it was opened from in data-ip / data-jails and menu.target). A request's URL
// (.request-path): search the table for it.
(function () {
    var menu = document.getElementById('client-menu');
    if (!menu) { return; }

    var close = function () { menu.hidden = true; };

    var pathMenu = document.getElementById('path-menu');
    var closePath = function () { if (pathMenu) { pathMenu.hidden = true; } };
    var place = function (box, event) { // keep a menu on screen
        box.hidden = false;
        var x = Math.min(event.clientX, window.innerWidth - box.offsetWidth - 8);
        var y = Math.min(event.clientY, window.innerHeight - box.offsetHeight - 8);
        box.style.left = Math.max(8, x) + 'px';
        box.style.top = Math.max(8, y) + 'px';
        box.querySelector('button:not([hidden])').focus();
    };

    // A request's URL: search the table for it (the path alone, or with its query string).
    document.addEventListener('contextmenu', function (event) {
        var cell = pathMenu && event.target.closest('.request-path');
        if (!cell) { closePath(); return; }
        event.preventDefault();
        close();
        var url = cell.getAttribute('data-path');
        var bare = url.split('?')[0];
        pathMenu.target = cell;
        var buttons = pathMenu.querySelectorAll('[data-path-search]');
        buttons[0].setAttribute('data-path-search', bare);
        buttons[0].querySelector('span').textContent = bare;
        buttons[1].setAttribute('data-path-search', url);
        buttons[1].querySelector('span').textContent = url;
        buttons[1].hidden = url === bare;
        place(pathMenu, event);
    });
    if (pathMenu) {
        document.addEventListener('click', function (event) { if (!pathMenu.contains(event.target)) { closePath(); } });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { closePath(); } });
        window.addEventListener('scroll', closePath, true);
        pathMenu.querySelectorAll('[data-path-search]').forEach(function (button) {
            button.addEventListener('click', function () {
                closePath();
                var box = pathMenu.target && pathMenu.target.closest('.paged');
                var search = box && box.querySelector('[data-paged-search]');
                if (!search) { return; }
                search.value = button.getAttribute('data-path-search');
                search.dispatchEvent(new Event('input'));
                search.scrollIntoView({ block: 'nearest' });
            });
        });
    }

    document.addEventListener('contextmenu', function (event) {
        var cell = event.target.closest('.client-ip');
        if (!cell) { close(); return; }
        event.preventDefault();
        closePath();
        menu.dataset.ip = cell.getAttribute('data-ip');
        menu.dataset.jails = cell.getAttribute('data-jails') || '';
        menu.target = cell;
        menu.querySelectorAll('[data-client-ip]').forEach(function (span) { span.textContent = menu.dataset.ip; });
        place(menu, event);
    });
    document.addEventListener('click', function (event) { if (!menu.contains(event.target)) { close(); } });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { close(); } });
    window.addEventListener('scroll', close, true);
    menu.addEventListener('click', function (event) { if (event.target.closest('button')) { close(); } });

    // Show only this address: the table's client filter (a whole address matches exactly). "Hide
    // localhost" would hide every row of a loopback address, so that's turned off for one.
    var only = menu.querySelector('[data-client-action="filter"]');
    if (only) {
        only.addEventListener('click', function () {
            var box = menu.target && menu.target.closest('.paged');
            var client = box && box.querySelector('[data-paged-param="client"]');
            if (!client) { return; }
            var ip = menu.dataset.ip;
            var local = box.querySelector('[data-paged-param="hide_local"]');
            if (local && local.checked && (/^127\./.test(ip) || ip === '::1')) { local.checked = false; }
            client.value = ip;
            client.dispatchEvent(new Event('input'));
            client.scrollIntoView({ block: 'nearest' });
        });
    }
})();
