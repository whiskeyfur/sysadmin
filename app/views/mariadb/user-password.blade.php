{{-- A new password with a Generate button (24 characters, made in the browser). Needs $id. --}}
<label for="{{ $id }}">Password</label>
<div class="password-row">
    <input type="password" id="{{ $id }}" name="password" minlength="{{ \App\Services\DbUserManagerService::MIN_PASSWORD }}" autocomplete="new-password" spellcheck="false" required>
    <button type="button" class="secondary" data-generate="{{ $id }}">Generate</button>
    <button type="button" class="secondary" data-reveal="{{ $id }}">Show</button>
</div>
<p class="hint">At least {{ \App\Services\DbUserManagerService::MIN_PASSWORD }} characters, not containing the username.</p>
