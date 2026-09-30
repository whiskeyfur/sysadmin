// Passkeys and security keys (WebAuthn) for the login, Profile and reveal pages.
// Buttons say what they do with data attributes; the server does all the checking.
(function () {
    'use strict';

    // The app's base path ('' at a site's root, e.g. '/sysadmin' in a subdirectory; App\Utils\BasePath).
    var base = document.documentElement.getAttribute('data-base') || '';

    function toBuffer(base64url) {
        var base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
        var binary = atob(base64 + '==='.slice((base64.length + 3) % 4));
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) { bytes[i] = binary.charCodeAt(i); }
        return bytes.buffer;
    }

    function toBase64url(buffer) {
        var bytes = new Uint8Array(buffer), binary = '';
        for (var i = 0; i < bytes.length; i++) { binary += String.fromCharCode(bytes[i]); }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function csrf() {
        var input = document.querySelector('input[name="X-Leaf-CSRF-Token"]');
        return input ? input.value : '';
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok) { throw new Error(data.error || 'Something went wrong (' + response.status + ').'); }
                return data;
            });
        });
    }

    function credentialJson(credential) {
        var r = credential.response, out = { id: credential.id, rawId: toBase64url(credential.rawId), type: credential.type, response: { clientDataJSON: toBase64url(r.clientDataJSON) } };
        if (r.attestationObject) { out.response.attestationObject = toBase64url(r.attestationObject); }
        if (r.authenticatorData) { out.response.authenticatorData = toBase64url(r.authenticatorData); }
        if (r.signature) { out.response.signature = toBase64url(r.signature); }
        if (r.userHandle) { out.response.userHandle = toBase64url(r.userHandle); }
        return out;
    }

    function get(optionsUrl, body) {
        return post(optionsUrl, body).then(function (options) {
            options.challenge = toBuffer(options.challenge);
            (options.allowCredentials || []).forEach(function (c) { c.id = toBuffer(c.id); });
            return navigator.credentials.get({ publicKey: options });
        }).then(credentialJson);
    }

    function create(optionsUrl) {
        return post(optionsUrl).then(function (options) {
            options.challenge = toBuffer(options.challenge);
            options.user.id = toBuffer(options.user.id);
            (options.excludeCredentials || []).forEach(function (c) { c.id = toBuffer(c.id); });
            return navigator.credentials.create({ publicKey: options });
        }).then(credentialJson);
    }

    function report(button, error) {
        var target = document.getElementById(button.getAttribute('data-message') || '') || button.parentNode.querySelector('.passkey-message');
        var message = error && error.name === 'NotAllowedError' ? 'Cancelled, or no passkey for this site on this device.' : (error && error.message) || String(error);
        if (target) { target.textContent = message; target.hidden = false; } else { alert(message); }
        button.disabled = false;
    }

    function wire(selector, run) {
        document.querySelectorAll(selector).forEach(function (button) {
            if (!window.PublicKeyCredential) { button.disabled = true; button.title = 'This browser has no passkey support.'; return; }
            button.addEventListener('click', function (event) {
                event.preventDefault();
                button.disabled = true;
                run(button).catch(function (error) { report(button, error); });
            });
        });
    }

    // Sign in: optional username (security keys need it; passkeys don't).
    wire('[data-passkey-login]', function () {
        var username = (document.getElementById('username') || {}).value || '';
        return get(base + '/login/passkey/options', { username: username }).then(function (credential) {
            return post(base + '/login/passkey', { credential: credential });
        }).then(function (data) { window.location = data.redirect || base + '/'; });
    });

    // Re-confirm with a passkey; then submit the form it belongs to (e.g. reveal) or reload.
    wire('[data-passkey-confirm]', function (button) {
        return get(base + '/profile/confirm/passkey/options').then(function (credential) {
            return post(base + '/profile/confirm/passkey', { credential: credential });
        }).then(function () {
            var form = document.getElementById(button.getAttribute('data-passkey-confirm'));
            if (form) {
                var flag = document.createElement('input');
                flag.type = 'hidden'; flag.name = 'passkey_confirmed'; flag.value = '1';
                form.appendChild(flag);
                form.querySelectorAll('input[required]').forEach(function (input) { input.required = false; });
                form.submit();
            } else {
                window.location.reload();
            }
        });
    });

    // Add a passkey or security key.
    wire('[data-passkey-register]', function () {
        var name = (document.getElementById('passkey_name') || {}).value || '';
        return create(base + '/profile/passkeys/options').then(function (credential) {
            return post(base + '/profile/passkeys', { credential: credential, name: name });
        }).then(function () { window.location = base + '/profile#passkeys'; });
    });
})();
