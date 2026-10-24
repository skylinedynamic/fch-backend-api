{{--
    A section of label/value pairs in two columns.
    $title  - the section heading
    $fields - [label => value]; a null or empty value shows as N/A
    $wide   - (optional) labels whose value needs the full width, each on a row of its own
--}}
@php
    $wide = $wide ?? [];
    $rows = [];
    $pair = [];
    foreach ($fields as $label => $value) {
        if (in_array($label, $wide, true)) {
            if ($pair) { $rows[] = $pair; $pair = []; }
            $rows[] = ['wide' => [$label => $value]];
            continue;
        }
        $pair[$label] = $value;
        if (count($pair) === 2) { $rows[] = $pair; $pair = []; }
    }
    if ($pair) { $rows[] = $pair; }
    $shown = fn ($value) => ($value === null || $value === '') ? 'N/A' : $value;
@endphp
<div class="section">
    <div class="section-title">{{ $title }}</div>
    <table class="fields">
        @foreach($rows as $row)
            <tr>
                @if(isset($row['wide']))
                    @foreach($row['wide'] as $label => $value)
                        <td class="label">{{ $label }}</td>
                        <td class="value" colspan="4" style="word-break: break-all;">{{ $shown($value) }}</td>
                    @endforeach
                @else
                    @foreach($row as $label => $value)
                        <td class="label">{{ $label }}</td>
                        <td class="value">{{ $shown($value) }}</td>
                        @if($loop->first)<td class="gap"></td>@endif
                    @endforeach
                    @if(count($row) === 1)
                        <td class="label"></td><td class="value"></td>
                    @endif
                @endif
            </tr>
        @endforeach
    </table>
</div>
