{{-- The URL (and optional request details) to simulate. Needs $form; $url; $name (the url field's name). --}}
<label for="{{ $name }}">URL</label>
<input type="text" id="{{ $name }}" name="{{ $name }}" value="{{ $url }}" placeholder="https://www.example.com/some/page?x=1" autocapitalize="none" spellcheck="false">
<details>
    <summary>Request details (optional)</summary>
    <label for="method">Method</label>
    <input type="text" id="method" name="method" value="{{ $form['method'] }}">
    <label for="remote_addr">Client address (REMOTE_ADDR)</label>
    <input type="text" id="remote_addr" name="remote_addr" value="{{ $form['remote_addr'] }}">
    <label for="user_agent">User-Agent</label>
    <input type="text" id="user_agent" name="user_agent" value="{{ $form['user_agent'] }}">
    <label for="referer">Referer</label>
    <input type="text" id="referer" name="referer" value="{{ $form['referer'] }}">
    <label for="cookie">Cookie</label>
    <input type="text" id="cookie" name="cookie" value="{{ $form['cookie'] }}">
</details>
