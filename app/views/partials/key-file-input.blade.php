{{--
    Reads the key file in the browser and submits its contents as a form
    field. A normal file upload would make PHP write the key to a temp file
    on disk, which CLAUDE.md forbids. The file input has no name, so the
    file itself is never sent.
--}}
<label for="key_file_picker">Key file</label>
<input type="file" id="key_file_picker" accept=".json,application/json" required>
<textarea name="key_file" id="key_file" hidden></textarea>
<p class="hint">The key file an admin shared with you outside this website.</p>
<script>
    document.getElementById('key_file_picker').addEventListener('change', function (event) {
        var field = document.getElementById('key_file');
        var file = event.target.files[0];
        field.value = '';
        if (!file) { return; }
        var reader = new FileReader();
        reader.onload = function () { field.value = reader.result; };
        reader.readAsText(file);
    });
</script>
