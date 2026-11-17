{{-- The six-month trend as figures: one row per measure, a column per month (the current one bold); what each counts is noted under the table. --}}
@php
    $charts = $summary->charts();
    $tones = ['up' => ['pos', '▲'], 'down' => ['neg', '▼']];
@endphp
<table class="full tbl">
    <tr>
        <th style="width: 24%;">{{ __('owner_summary.export.trend_measure') }}</th>
        @foreach ($charts[0]['bars'] as $bar)
            <th class="r">{{ $bar['month'] }}</th>
        @endforeach
        <th class="r">{{ __('owner_summary.export.trend_change') }}</th>
    </tr>
    @foreach ($charts as $chart)
        <tr class="{{ $loop->even ? 'even' : '' }}">
            <td>{{ $chart['title'] }}</td>
            @foreach ($chart['bars'] as $bar)
                <td class="r {{ $bar['current'] ? 'strong' : '' }}">{{ $bar['value'] }}</td>
            @endforeach
            <td class="r">
                @if ($chart['trend'])
                    @php([$class, $arrow] = $tones[$chart['trend']['tone']] ?? ['neu', '—'])
                    <span class="badge {{ $class }}">{{ $arrow }} {{ $chart['trend']['text'] }}</span>
                @else
                    <span class="muted">&mdash;</span>
                @endif
            </td>
        </tr>
    @endforeach
</table>
<div class="note">{{ __('owner_summary.export.trend_notes', ['funded' => $charts[0]['sub'], 'cash' => $charts[1]['sub']]) }}</div>
