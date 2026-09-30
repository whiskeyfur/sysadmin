{{-- Sites, confs or modules of this machine's Apache, with their enable/disable buttons (in the page's one change form). --}}
@if (count($items) === 0)
    <p class="muted">None.</p>
@else
    <div class="table-wrap">
    <table>
        <thead><tr><th>Name</th><th>State</th>@if ($editable)<th>Changed</th>@endif<th data-nosort></th></tr></thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td><code>{{ $item['name'] }}</code></td>
                    <td data-sort="{{ $item['enabled'] ? 1 : 0 }}"><span class="badge {{ $item['enabled'] ? 'ok' : 'unknown' }}">{{ $item['enabled'] ? 'Enabled' : 'Off' }}</span></td>
                    @if ($editable)
                        <td data-sort="{{ $item['modified'] }}">{{ \App\Utils\LocalTime::format(\Carbon\Carbon::createFromTimestamp($item['modified']), 'Y-m-d H:i') }}</td>
                    @endif
                    <td class="row-actions">
                        @if ($editable)
                            <a class="button secondary-link" href="/admin/apache/local/edit?kind={{ $kind }}&amp;name={{ urlencode($item['name']) }}">Edit</a>
                        @endif
                        <button type="submit" name="change" value="{{ ($item['enabled'] ? 'disable' : 'enable') . ':' . $kind . ':' . $item['name'] }}" class="secondary {{ $item['enabled'] ? 'danger' : '' }}">{{ $item['enabled'] ? 'Disable' : 'Enable' }}</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
@endif
