{{-- A dialog that asks for the signed-in user's password or a passkey before any form marked
     data-confirm-dialog="Question?" is sent (once per page; the controller checks with
     confirmIdentity(codes: false, passwordField: 'acct_password')). The password goes with the form as
     acct_password; a passkey check adds passkey_confirmed and sends the form (public/assets/js/passkeys.js,
     which the page must load). Needs $usable and $passkeysHere. --}}
@php($password = in_array('password', $usable, true))
@php($passkey = in_array('passkey', $usable, true) && $passkeysHere)
<dialog id="confirm-dialog" aria-labelledby="confirm-title">
    <h2 id="confirm-title">Confirm it's you</h2>
    <p id="confirm-ask"></p>
    @if ($password || $passkey)
        @if ($password)
            <label for="confirm-password">Your password</label>
            <input type="password" id="confirm-password" autocomplete="current-password">
        @endif
        <div class="actions">
            @if ($password)
                <button type="button" id="confirm-go">Confirm</button>
            @endif
            @if ($passkey)
                <button type="button" class="{{ $password ? 'secondary' : '' }}" id="confirm-passkey" data-passkey-confirm="" data-message="confirm-passkey-message">{{ $password ? 'Use a passkey instead' : 'Confirm with a passkey' }}</button>
            @endif
            <button type="button" class="secondary" id="confirm-cancel">Cancel</button>
        </div>
        <p class="passkey-message alert error" id="confirm-passkey-message" role="alert" hidden></p>
    @else
        <p class="alert error" role="alert">Changes here need your password or a passkey{{ in_array('passkey', $usable, true) ? ' (passkeys only work on the address they were made on)' : '' }}. Set one up on your <a href="/profile">profile</a> first.</p>
        <div class="actions"><button type="button" class="secondary" id="confirm-cancel">Close</button></div>
    @endif
</dialog>
<style>
    #confirm-dialog { width: min(460px, calc(100vw - 32px)); border: 1px solid var(--line); border-radius: 10px; background: var(--panel); color: var(--text); padding: 24px; box-shadow: 0 12px 40px rgba(0, 0, 0, .25); }
    #confirm-dialog::backdrop { background: rgba(0, 0, 0, .45); }
    #confirm-dialog h2 { margin-top: 0; }
    #confirm-dialog input[type=password] { width: 100%; box-sizing: border-box; }
</style>
<script>
    (function () {
        var dialog = document.getElementById('confirm-dialog');
        if (!dialog || typeof dialog.showModal !== 'function') { return; }
        var input = document.getElementById('confirm-password');
        var go = document.getElementById('confirm-go');
        var passkey = document.getElementById('confirm-passkey');
        var form = null;

        // The form has passed its own checks by the time it's submitted; hold it and ask.
        document.addEventListener('submit', function (event) {
            if (!event.target.matches('form[data-confirm-dialog]')) { return; }
            event.preventDefault();
            form = event.target;
            document.getElementById('confirm-ask').textContent = form.getAttribute('data-confirm-dialog');
            if (passkey) { passkey.setAttribute('data-passkey-confirm', form.id); }
            dialog.showModal();
            (input || passkey || document.getElementById('confirm-cancel')).focus();
        });

        var send = function () {
            if (!form || !input || input.value === '') { if (input) { input.focus(); } return; }
            var field = form.querySelector('input[name=acct_password]') || document.createElement('input');
            field.type = 'hidden'; field.name = 'acct_password'; field.value = input.value;
            form.appendChild(field);
            dialog.close();
            form.submit(); // not a submit event, so it isn't held again
        };

        if (go) { go.addEventListener('click', send); }
        if (input) { input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); send(); } }); }
        document.getElementById('confirm-cancel').addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('close', function () { if (input) { input.value = ''; } });
    })();
</script>
