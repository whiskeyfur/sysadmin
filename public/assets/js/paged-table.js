// Paged tables whose rows come from the server (reports/apache-data, reports/mariadb): <div class="paged"
// data-paged="URL"> with a search box, filters ([data-paged-param]), a pager and sortable headers
// (th[data-sort-key]). Each change fetches
// URL&page=&q=&sort=&dir= and puts the returned rows ({html, total, page, pages}) in the tbody, so
// search and sort cover every row in the period, not just the page shown.
(function () {
    document.querySelectorAll('.paged[data-paged]').forEach(function (box) {
        var url = box.getAttribute('data-paged');
        var body = box.querySelector('tbody');
        var search = box.querySelector('[data-paged-search]');
        var prev = box.querySelector('[data-paged-prev]');
        var next = box.querySelector('[data-paged-next]');
        var status = box.querySelector('[data-paged-status]');
        var error = box.querySelector('[data-paged-error]');
        var state = { page: 1, q: '', sort: 'time', dir: 'desc', pages: 1 };
        var request = 0;

        function load() {
            var mine = ++request;
            var params = new URLSearchParams({ page: state.page, q: state.q, sort: state.sort, dir: state.dir });
            // Filters (e.g. the access log's status, client and hide_local).
            box.querySelectorAll('[data-paged-param]').forEach(function (input) {
                params.set(input.getAttribute('data-paged-param'), input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value.trim());
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

        box.querySelectorAll('[data-paged-param]').forEach(function (input) {
            var refresh = function () { state.page = 1; load(); };
            if (input.type === 'text' || input.type === 'search') {
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

        load();
    });
})();
