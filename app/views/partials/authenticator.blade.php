{{-- Enrolment block shared by setup and registration. Needs $secret and $qr. --}}
<h2>Authenticator</h2>
<p>Scan this code with Google Authenticator or another authenticator app, then enter the 6-digit code it shows.</p>
<img class="qr" src="{{ $qr }}" alt="QR code for your authenticator app">
<p class="hint">Can't scan it? Enter this key manually: <code>{{ trim(chunk_split($secret, 4, ' ')) }}</code></p>
<input type="hidden" name="totp_secret" value="{{ $secret }}">

<label for="code">Code from the app</label>
<input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
