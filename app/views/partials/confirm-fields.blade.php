{{-- The fields to prove it's you with any method you have, inside a form. Needs $usable, $passkeysHere and $formId;
     $what labels the passkey button. With $passkeyTarget = '' the passkey check reloads the page instead of
     submitting the form (for forms with several submit buttons), and $passkeyReady says one was just made. --}}
@php($target = $passkeyTarget ?? $formId)
@if (!empty($passkeyReady))
    <input type="hidden" name="passkey_confirmed" value="1">
    <p class="alert notice" role="status">Passkey confirmed: make your change within a minute.</p>
@else
    @if (in_array('password', $usable, true))
        <label for="{{ $formId }}_password">Your password</label>
        <input type="password" id="{{ $formId }}_password" name="password" autocomplete="current-password">
    @endif
    @if (in_array('authenticator', $usable, true))
        <label for="{{ $formId }}_code">{{ in_array('password', $usable, true) ? 'Or a code' : 'A code' }} from your authenticator app</label>
        <input type="text" id="{{ $formId }}_code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6">
    @endif
    @if (in_array('passkey', $usable, true) && $passkeysHere)
        <div class="actions">
            <button type="button" class="secondary" data-passkey-confirm="{{ $target }}">{{ $what }} with a passkey</button>
        </div>
    @endif
    <p class="passkey-message alert error" role="alert" hidden></p>
@endif
