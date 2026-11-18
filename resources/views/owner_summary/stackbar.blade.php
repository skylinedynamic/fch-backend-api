{{-- A bar split by share: $parts each with 'percent' and 'color'. Cells, because dompdf and Outlook both draw those. --}}
@php($shown = array_filter($parts, fn (array $part) => $part['percent'] > 0))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse; table-layout: fixed; background-color: #f1f3f5;">
    <tr>
        @forelse ($shown as $part)
            <td width="{{ $part['percent'] }}%" height="12" style="width: {{ $part['percent'] }}%; height: 12px; font-size: 0; line-height: 0; background-color: {{ $part['color'] }};">&nbsp;</td>
        @empty
            <td height="12" style="height: 12px; font-size: 0; line-height: 0;">&nbsp;</td>
        @endforelse
    </tr>
</table>
