<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') · sys</title>
    <style>
        :root {
            --bg: #f6f7f9; --panel: #ffffff; --line: #dfe3e8; --text: #1b1f24; --muted: #5b6470;
            --accent: #2457c5; --accent-hover: #1c469f; --accent-text: #ffffff;
            --error: #b42318; --error-bg: #fdecea; --notice: #1d6b3a; --notice-bg: #e8f5ec;
            --warn: #8a5a00; --warn-bg: #fff4d6; --code-bg: #eef1f5;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1216; --panel: #171b21; --line: #2a3039; --text: #e6e9ee; --muted: #9aa4b1;
                --accent: #6b9bff; --accent-hover: #8fb2ff; --accent-text: #0f1216;
                --error: #ff8a80; --error-bg: #3a1a18; --notice: #7bd89a; --notice-bg: #15301f;
                --warn: #ffd166; --warn-bg: #33290f; --code-bg: #20262e;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 24px; border-bottom: 1px solid var(--line); background: var(--panel); }
        header .brand { font-weight: 700; letter-spacing: .02em; color: inherit; text-decoration: none; }
        header nav { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        header nav.primary .brand { margin-right: 8px; }
        header nav a[aria-current="page"] { font-weight: 700; text-decoration: underline; text-underline-offset: 4px; }
        main { max-width: 960px; margin: 0 auto; padding: 32px 16px; }
        main.narrow { max-width: 440px; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 24px; }
        .card + .card { margin-top: 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 17px; margin: 0 0 12px; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); }
        label { display: block; font-weight: 600; margin: 16px 0 6px; }
        input[type=text], input[type=password], select, textarea { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--text); font: inherit; }
        textarea { font: 12px/1.5 ui-monospace, monospace; }
        input:focus-visible, button:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .hint { font-size: 13px; color: var(--muted); margin-top: 4px; }
        button, .button { display: inline-block; padding: 9px 16px; border: 0; border-radius: 6px; background: var(--accent); color: var(--accent-text); font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        button:hover, .button:hover { background: var(--accent-hover); }
        button.secondary { background: transparent; color: var(--accent); border: 1px solid var(--line); }
        button.danger, .button.danger-link { background: transparent; color: var(--error); border: 1px solid var(--line); }
        button.danger-solid { background: var(--error); color: var(--panel); }
        .button.secondary-link { background: transparent; color: var(--accent); border: 1px solid var(--line); }
        .button.danger-link:hover, .button.secondary-link:hover, button.secondary:hover, button.danger:hover { background: var(--bg); }
        .row-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .row-actions button, .row-actions .button { padding: 5px 10px; font-size: 13px; }
        .table-wrap { overflow-x: auto; }
        .inline-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .inline-form label { margin-top: 0; }
        .badge.user { background: var(--code-bg); }
        .badge.ok { background: var(--notice-bg); color: var(--notice); }
        .badge.failed { background: var(--error-bg); color: var(--error); }
        .badge.untested, .badge.warning { background: var(--warn-bg); color: var(--warn); }
        .badge.critical { background: var(--error-bg); color: var(--error); }
        .badge.unknown { background: var(--code-bg); color: var(--muted); }
        .history { display: inline-flex; gap: 2px; }
        .dot { width: 8px; height: 16px; border-radius: 2px; background: var(--line); }
        .dot.ok { background: var(--notice); }
        .dot.warning { background: var(--warn); }
        .dot.critical { background: var(--error); }
        .dot.unknown { background: var(--muted); }
        .pubkey { display: block; padding: 10px; background: var(--code-bg); border-radius: 6px; font: 12px/1.5 ui-monospace, monospace; word-break: break-all; }
        fieldset { border: 1px solid var(--line); border-radius: 8px; padding: 4px 16px 16px; margin: 20px 0 0; }
        legend { font-weight: 600; padding: 0 6px; }
        .check { display: flex; gap: 8px; align-items: center; font-weight: 600; margin-top: 12px; }
        .steps { padding-left: 20px; }
        .steps li { margin: 6px 0; }
        .steps li.ok::marker { content: '✓  '; color: var(--notice); }
        .steps li.failed::marker { content: '✗  '; color: var(--error); }
        .grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 0 12px; }
        .actions { margin-top: 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
        .alert.error { background: var(--error-bg); color: var(--error); }
        .alert.notice { background: var(--notice-bg); color: var(--notice); }
        .alert.warn { background: var(--warn-bg); color: var(--warn); }
        a { color: var(--accent); }
        code { background: var(--code-bg); padding: 2px 6px; border-radius: 4px; font-size: 13px; word-break: break-all; }
        .qr { display: block; width: 200px; height: 200px; margin: 12px 0; background: #fff; padding: 8px; border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; }
        th { font-size: 13px; color: var(--muted); font-weight: 600; }
        td form { display: inline; }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--code-bg); }
        .badge.admin { background: var(--notice-bg); color: var(--notice); }
    </style>
</head>
<body>
    @php($path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')
    <header>
        <nav class="primary" aria-label="Monitoring">
            <a class="brand" href="/">sys</a>
            @isset($auth)
                <a href="/servers" @if (str_starts_with($path, '/servers')) aria-current="page" @endif>Servers</a>
                <a href="/ssl" @if (str_starts_with($path, '/ssl')) aria-current="page" @endif>SSL</a>
            @endisset
        </nav>
        @isset($auth)
            <nav class="secondary" aria-label="Configuration and account">
                @if ($auth->isAdmin())
                    <a href="/admin/servers" @if (str_starts_with($path, '/admin/servers')) aria-current="page" @endif>Configure</a>
                    <a href="/admin/users" @if (str_starts_with($path, '/admin/users')) aria-current="page" @endif>Users</a>
                @endif
                <a href="/password" @if ($path === '/password') aria-current="page" @endif>Password</a>
                <span class="muted">{{ $auth->user->username }}</span>
                <form method="post" action="/logout">
                    @csrf
                    <button type="submit" class="secondary">Sign out</button>
                </form>
            </nav>
        @endisset
    </header>
    <main class="@yield('width')">
        @yield('content')
    </main>
</body>
</html>
