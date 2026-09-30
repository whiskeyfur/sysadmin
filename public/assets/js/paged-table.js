// Paged tables whose rows come from the server (reports/apache-data, reports/mariadb): <div class="paged"
// data-paged="URL"> with a search box, filters ([data-paged-param]), a pager and sortable headers
// (th[data-sort-key]). Each change fetches
// URL&page=&q=&sort=&dir= and puts the returned rows ({html, total, page, pages}) in the tbody, so
// search and sort cover every row in the period, not just the page shown.
(function () {
    // Links between tables: <button data-paged-find="#access-log" data-value="ID"> finds ID in that table.
    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-paged-find]');
        if (!link) { return; }
        var target = document.querySelector(link.getAttribute('data-paged-find'));
        if (!target) {
            link.disabled = true;
            link.textContent = link.getAttribute('data-paged-find') === '#access-log' ? 'request not stored' : 'no error log here';
            return;
        }
        target.dispatchEvent(new CustomEvent('paged-find', { detail: link.getAttribute('data-value') }));
    });

    // Fold-outs: <button data-fold-toggle> opens or closes the tr.fold-row right after its row (e.g. an
    // access log request's error log entries); in a <tr data-fold-row> a click anywhere on the row does
    // (except on its other links and buttons). The arrow in the button's label follows.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-fold-toggle]');
        var row = button ? button.closest('tr') : event.target.closest('tr[data-fold-row]');
        if (!row || (!button && event.target.closest('a, button, input, select, textarea'))) { return; }
        if (!button && window.getSelection && String(window.getSelection()).length > 0) { return; } // selecting text
        var fold = row.nextElementSibling;
        if (!fold || !fold.classList.contains('fold-row')) { return; }
        fold.hidden = !fold.hidden;
        button = button || row.querySelector('[data-fold-toggle]');
        if (button) {
            button.setAttribute('aria-expanded', fold.hidden ? 'false' : 'true');
            button.textContent = button.textContent.replace(/^[▸▾]/, fold.hidden ? '▸' : '▾');
        }
    });

    document.querySelectorAll('.paged[data-paged]').forEach(function (box) {
        var url = box.getAttribute('data-paged');
        var body = box.querySelector('tbody');
        var search = box.querySelector('[data-paged-search]');
        var prev = box.querySelector('[data-paged-prev]');
        var next = box.querySelector('[data-paged-next]');
        var status = box.querySelector('[data-paged-status]');
        var error = box.querySelector('[data-paged-error]');
        // Newest first by default; data-paged-sort names the column that means ("time" unless set).
        var state = { page: 1, q: '', sort: box.getAttribute('data-paged-sort') || 'time', dir: 'desc', pages: 1 };
        var request = 0;

        function load() {
            var mine = ++request;
            var params = new URLSearchParams({ page: state.page, q: state.q, sort: state.sort, dir: state.dir });
            // Filters (e.g. the access log's client box and tri-state status, fail2ban and localhost ones).
            box.querySelectorAll('[data-paged-param]').forEach(function (input) {
                var value = input.classList.contains('tri') ? input.getAttribute('data-state') : (input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value.trim());
                params.set(input.getAttribute('data-paged-param'), value || '');
            });
            box.classList.add('loading');

            fetch(url + '&' + params.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) {
                    return response.json().catch(function () { throw new Error('The server answered ' + response.status + '.'); });
                })
                .then(function (data) {
                    if (mine !== request) { return; } // a newer request is on its way
                    if (data.error) { throw new Error(data.error); }
                    body.innerHTML = data.html;
                    state.page = data.page;
                    state.pages = data.pages;
                    status.textContent = data.total === 0 ? 'Nothing found'
                        : 'Page ' + data.page.toLocaleString() + ' of ' + data.pages.toLocaleString() + ' · ' + data.total.toLocaleString() + (data.total === 1 ? ' entry' : ' entries');
                    prev.disabled = data.page <= 1;
                    next.disabled = data.page >= data.pages;
                    error.hidden = true;
                })
                .catch(function (e) {
                    if (mine !== request) { return; }
                    error.textContent = "Couldn't load the entries: " + e.message;
                    error.hidden = false;
                })
                .finally(function () { if (mine === request) { box.classList.remove('loading'); } });
        }

        prev.addEventListener('click', function () { if (state.page > 1) { state.page--; load(); } });
        next.addEventListener('click', function () { if (state.page < state.pages) { state.page++; load(); } });

        var typing = null;
        search.addEventListener('input', function () {
            clearTimeout(typing);
            typing = setTimeout(function () { state.q = search.value.trim(); state.page = 1; load(); }, 300);
        });

        // Tri-state filters: any → only these (in) → not these (out) → any.
        var setTri = function (button, value) {
            button.setAttribute('data-state', value);
            var text = button.getAttribute('data-name') + ': ' + ({ 'in': 'only these', out: 'not these' }[value] || 'any');
            button.setAttribute('aria-label', text);
            button.title = text + ' (click to change)';
        };

        box.querySelectorAll('[data-paged-param]').forEach(function (input) {
            var refresh = function () { state.page = 1; load(); };
            if (input.classList.contains('tri')) {
                input.addEventListener('click', function () {
                    setTri(input, { '': 'in', 'in': 'out', out: '' }[input.getAttribute('data-state') || '']);
                    refresh();
                });
            } else if (input.type === 'text' || input.type === 'search') {
                var wait = null;
                input.addEventListener('input', function () { clearTimeout(wait); wait = setTimeout(refresh, 300); });
            } else {
                input.addEventListener('change', refresh);
            }
        });

        box.querySelectorAll('th[data-sort-key]').forEach(function (th) {
            th.tabIndex = 0;
            var sort = function () {
                var key = th.getAttribute('data-sort-key');
                // A new column starts newest/largest first (time) or A→Z (others); the same one flips.
                state.dir = state.sort === key ? (state.dir === 'asc' ? 'desc' : 'asc') : (key === 'time' ? 'desc' : 'asc');
                state.sort = key;
                state.page = 1;
                box.querySelectorAll('th[data-sort-key]').forEach(function (other) { other.removeAttribute('aria-sort'); });
                th.setAttribute('aria-sort', state.dir === 'asc' ? 'ascending' : 'descending');
                load();
            };
            th.addEventListener('click', sort);
            th.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); sort(); } });
        });

        // Reload the page shown when something outside changes what it holds (a fail2ban ban or unban).
        document.addEventListener('paged-refresh', load);

        // Find one thing (e.g. a request ID from the other log): search for it with every filter cleared,
        // since any of them could hide it (a request from 127.0.0.1, say).
        box.addEventListener('paged-find', function (event) {
            box.querySelectorAll('[data-paged-param]').forEach(function (input) {
                if (input.classList.contains('tri')) { setTri(input, ''); } else if (input.type === 'checkbox') { input.checked = false; } else { input.value = ''; }
            });
            search.value = event.detail;
            state.q = event.detail;
            state.page = 1;
            load();
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        load();
    });
})();
