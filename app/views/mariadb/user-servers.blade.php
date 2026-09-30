{{-- Server checkboxes for a database-account change. Needs $servers, $chosen (ids) and $legend. --}}
<fieldset class="user-servers">
    <legend>{{ $legend }} <button type="button" class="link" data-check-all>all</button> · <button type="button" class="link" data-check-none>none</button></legend>
    @forelse ($servers as $server)
        <label class="check"><input type="checkbox" name="servers[]" value="{{ $server->id }}" {{ in_array($server->id, $chosen, true) ? 'checked' : '' }}> {{ $server->name }}</label>
    @empty
        <p class="muted">None.</p>
    @endforelse
</fieldset>
