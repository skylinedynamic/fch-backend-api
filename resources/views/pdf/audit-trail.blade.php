<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
@include('pdf.partials.styles')
<style>
    .integrity { margin: 10px 0; padding: 6px 8px; font-weight: bold; }
    .integrity.valid { background-color: #ecfdf5; color: #047857; }
    .integrity.broken { background-color: #fef2f2; color: #b91c1c; }
    .events th { text-align: left; color: #6b7280; font-weight: normal; padding: 5px 6px; border-bottom: 1px solid #d1d5db; }
    .events td { padding: 5px 6px; border-bottom: 1px solid #eceef1; word-wrap: break-word; }
    .events tr { page-break-inside: avoid; }
    .muted { color: #6b7280; }
    .hash { font-size: 7px; color: #6b7280; word-break: break-all; }
</style>
</head>
<body>
@include('pdf.partials.footer')
@include('pdf.partials.header')

<div class="integrity {{ $integrity['valid'] ? 'valid' : 'broken' }}">
    {{ $integrity['valid']
        ? __('audit.pdf.integrity_valid', ['count' => $integrity['checked']])
        : __('audit.pdf.integrity_broken', ['event' => $integrity['broken_at']]) }}
</div>

<table class="events">
    <thead>
        <tr>
            <th style="width: 11%;">{{ $columns['occurred_at'] }}</th>
            <th style="width: 12%;">{{ $columns['event'] }}</th>
            <th style="width: 14%;">{{ $columns['actor'] }}</th>
            <th style="width: 9%;">{{ $columns['ip_address'] }}</th>
            <th style="width: 30%;">{{ $columns['changes'] }}</th>
            <th style="width: 24%;">{{ $columns['details'] }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $row)
            <tr>
                <td>{{ $row['occurred_at'] }}</td>
                <td>
                    {{ $row['event'] }}
                    @if($row['legacy'])<div class="muted">{{ $columns['legacy'] }}</div>@endif
                </td>
                <td>
                    {{ $row['actor'] }}
                    @if($row['actor_type'])<div class="muted">{{ $row['actor_type'] }}</div>@endif
                </td>
                <td>{{ $row['ip_address'] ?: __('audit.empty_value') }}</td>
                <td>
                    @foreach($row['changes'] as $change)
                        <div><span class="muted">{{ $change['label'] }}:</span> {{ $change['old'] }} &rarr; {{ $change['new'] }}</div>
                    @endforeach
                </td>
                <td>
                    {{ $row['details'] }}
                    @if($row['file_hash'])<div class="hash">{{ $columns['file_hash'] }}: {{ $row['file_hash'] }}</div>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('audit.pdf.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>
</body>
</html>
