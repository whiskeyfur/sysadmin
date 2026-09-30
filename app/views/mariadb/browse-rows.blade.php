{{-- One page of a table's rows in the MariaDB browser (DatabaseBrowserService::rows()). Needs $rows, $columns. --}}
@forelse ($rows as $row)
    <tr>
        @foreach ($row as $value)
            @if ($value === null)
                <td class="muted" data-null>NULL</td>
            @else
                <td><code>{{ $value }}</code></td>
            @endif
        @endforeach
    </tr>
@empty
    <tr><td colspan="{{ max(1, count($columns)) }}" class="muted">No rows match.</td></tr>
@endforelse
