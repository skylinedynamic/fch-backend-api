{{-- Net earnings by stream, with the total collected; the details report adds each stream's description. --}}
@php
    $streams = $summary->streams();
    $withNotes = $withNotes ?? false;
@endphp
<table class="full tbl">
    <tr>
        <th>{{ $summary->t('stream') }}</th>
        @if ($withNotes)<th>{{ __('owner_summary.export.description') }}</th>@endif
        <th class="r" style="width: 14%;">{{ $summary->t('payments') }}</th>
        <th class="r" style="width: 16%;">{{ $summary->periodColumn() }}</th>
    </tr>
    @foreach ($streams['rows'] as $row)
        @php($none = (int) $row['payments'] === 0)
        <tr class="{{ $loop->even ? 'even' : '' }}">
            <td><span class="dot {{ $none ? 'off' : '' }}"></span>{{ $row['label'] }}</td>
            @if ($withNotes)<td class="muted">{{ $row['note'] }}</td>@endif
            <td class="r {{ $none ? 'muted' : '' }}">{{ $row['payments'] }}</td>
            <td class="r {{ $none ? 'muted' : '' }}">{{ $row['amount'] }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td @if ($withNotes) colspan="2" @endif>{{ $summary->t('total_collected') }}</td>
        <td class="r">{{ $streams['payments'] }}</td>
        <td class="r">{{ $streams['amount'] }}</td>
    </tr>
</table>
