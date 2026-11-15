{{-- What a report covers, as label and value rows: on the summary's title block and the details report's cover. --}}
@php
    $meta = array_filter([
        [__('owner_summary.export.prepared_for_label'), $companyName],
        [__('owner_summary.export.compared_with'), $summary->comparedWithLabel()],
        [__('owner_summary.export.payments'), $environmentLabel],
        [__('owner_summary.export.timezone'), $summary->timezone()],
        ($withGenerated ?? false) ? [__('owner_summary.export.generated'), $generatedOn] : null,
    ]);
@endphp
<table>
    @foreach ($meta as [$label, $value])
        <tr><td class="meta-label">{{ $label }}</td><td class="meta-value">{{ $value }}</td></tr>
    @endforeach
</table>
