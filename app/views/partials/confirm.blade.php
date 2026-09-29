{{-- Prove it's you with any method you have. Needs $usable (LoginMethodService::usable()), $passkeysHere,
     $formId and $action; $what says what it's for. The passkey button checks first, then submits the form. --}}
<form method="post" action="{{ $action }}" id="{{ $formId }}">
    @csrf
    @if (in_array('password', $usable, true))
        <label for="{{ $formId }}_password">Your password</label>
        <input type="password" id="{{ $formId }}_password" name="password" autocomplete="current-password">
    @endif
    @if (in_array('authenticator', $usable, true))
        <label for="{{ $formId }}_code">{{ in_array('password', $usable, true) ? 'Or a code' : 'A code' }} from your authenticator app</label>
        <input type="text" id="{{ $formId }}_code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6">
    @endif
    <div class="actions">
        @if (array_intersect(['password', 'authenticator'], $usable))
            <button type="submit" class="secondary">{{ $what }}</button>
        @endif
        @if (in_array('passkey', $usable, true) && $passkeysHere)
            <button type="button" class="secondary" data-passkey-confirm="{{ $formId }}">{{ $what }} with a passkey</button>
        @endif
    </div>
    <p class="passkey-message alert error" role="alert" hidden></p>
</form>
