{{-- Every key metric as a statement: metric, value, change on the period before (a badge with its direction), breakdown. Details report only. --}}
@php($tones = ['up' => ['pos', '▲'], 'down' => ['neg', '▼']])
<table class="full tbl">
    <tr>
        <th style="width: 22%;">{{ __('owner_summary.export.metric') }}</th>
        <th class="r" style="width: 12%;">{{ __('owner_summary.export.value') }}</th>
        <th style="width: 30%;">{{ __('owner_summary.export.change') }}</th>
        <th>{{ __('owner_summary.export.breakdown') }}</th>
    </tr>
    @foreach ($summary->kpis() as $kpi)
        @php([$class, $arrow] = $tones[$kpi['tone']] ?? ['neu', '—'])
        <tr class="{{ $loop->even ? 'even' : '' }}">
            <td>{{ $kpi['label'] }}</td>
            <td class="r strong">{{ $kpi['value'] }}</td>
            <td>
                @if ($kpi['tag'])
                    <span class="badge {{ $class }}">{{ $arrow }} {{ ucfirst($kpi['tag']) }}</span> <span class="vs">{{ $kpi['note'] }}</span>
                @else
                    <span class="muted">&mdash;</span>
                @endif
            </td>
            <td class="muted">{{ implode(' · ', array_map(fn (array $line) => $line['label'] . ' ' . $line['value'], $kpi['rows'])) ?: '—' }}</td>
        </tr>
    @endforeach
</table>
