{{-- A new password with a Generate button (24 characters, made in the browser). Needs $id; $socketIfBlank makes
     it optional (blank: a socket login). --}}
<label for="{{ $id }}">Password</label>
<div class="password-row">
    <input type="password" id="{{ $id }}" name="db_password" minlength="{{ \App\Services\DbUserManagerService::MIN_PASSWORD }}" autocomplete="new-password" spellcheck="false" @unless (!empty($socketIfBlank)) required @endunless>
    <button type="button" class="secondary" data-generate="{{ $id }}">Generate</button>
    <button type="button" class="secondary" data-reveal="{{ $id }}">Show</button>
</div>
<p class="hint">At least {{ \App\Services\DbUserManagerService::MIN_PASSWORD }} characters, not containing the username. @if (!empty($socketIfBlank))Leave it blank for a socket login: the system user of the same name signs in over the local socket, with no password (host localhost or %).@endif</p>
