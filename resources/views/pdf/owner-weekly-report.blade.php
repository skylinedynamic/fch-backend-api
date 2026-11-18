<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 20px 28px 40px 28px; }
    body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #212529; }
    td { vertical-align: top; }
    .gap { height: 10px; }
    .row { width: 100%; border-collapse: collapse; }
    .row > tbody > tr, .row > tr { page-break-inside: avoid; }
    .left { padding-right: 5px; }
    .right { padding-left: 5px; }
    .section { page-break-inside: avoid; }
    .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 9.5px; color: #5c6670; border-top: 1px solid #e9ebec; padding-top: 6px; }
</style>
</head>
<body>
@php
    $r = fn (string $key, array $replace = []) => __("owner_summary.report.{$key}", $replace);
    [$fundedChart, $cashChart] = $summary->charts();
    $section = fn (string $name, array $extra = []) => view('owner_summary.sections', array_merge(['section' => $name, 'summary' => $summary], $extra))->render();
@endphp

<div class="footer">&copy; {{ date('Y') }} {{ $companyName }} &middot; {{ $title }} &middot; {{ $summary->periodLabel() }}</div>

<table class="row">
    <tr>
        <td>
            <span style="font-size: 22px; font-weight: 700; color: #001f3f;">{{ $r('heading') }}</span>
            <span style="margin-left: 8px; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; background-color: #fff4de; color: #7a4d00;">{{ $r('tag') }}</span>
            <div style="margin-top: 5px; font-size: 12.5px; color: #5c6670;">{{ $r('subtitle', ['company' => $companyName]) }}</div>
        </td>
        <td style="text-align: right; vertical-align: middle;">
            @if ($logoBase64)
                <img src="{{ $logoBase64 }}" alt="{{ $companyName }}" width="{{ $logoWidth }}" height="{{ $logoHeight }}" style="width: {{ $logoWidth * 0.75 }}px; height: {{ $logoHeight * 0.75 }}px;">
            @endif
        </td>
    </tr>
</table>

<p style="margin: 10px 0; font-size: 12.5px; color: #5c6670;">
    {{ $r('showing') }} <strong style="color: #212529;">{{ $r('last_week_range', ['period' => $summary->periodLabel()]) }}</strong>
    &middot; {{ $r('compared_with', ['period' => $summary->comparedWithLabel()]) }}
    &middot; {{ $r('times_in', ['timezone' => $summary->timezone()]) }}
    &middot; {{ $r('live_only') }}
</p>

<div class="section">{!! $section('kpis', ['columns' => 4, 'height' => 118]) !!}</div>
<div class="gap"></div>

<table class="row">
    <tr>
        <td class="left" style="width: 64%;">{!! $section('cash') !!}</td>
        <td class="right" style="width: 36%;">
            {!! $section('commissions') !!}
            <div class="gap"></div>
            {!! $section('owed') !!}
        </td>
    </tr>
</table>
<div class="gap"></div>

<div class="section">{!! $section('attention', ['columns' => 3, 'links' => false]) !!}</div>
<div class="gap"></div>

{{-- The charts and stalled deals come earlier than on the screen: cards are kept whole, so this is what fills the pages. --}}
<table class="row">
    <tr>
        <td class="left" style="width: 50%;">{!! $section('chart', ['chart' => $fundedChart]) !!}</td>
        <td class="right" style="width: 50%;">{!! $section('chart', ['chart' => $cashChart]) !!}</td>
    </tr>
</table>
<div class="gap"></div>

<div class="section">{!! $section('stalled') !!}</div>
<div class="gap"></div>

<table class="row">
    <tr>
        <td class="left" style="width: 60%;">{!! $section('pipeline') !!}</td>
        <td class="right" style="width: 40%;">{!! $section('leads') !!}</td>
    </tr>
</table>
<div class="gap"></div>

<table class="row">
    <tr>
        <td class="left" style="width: 40%;">{!! $section('network') !!}</td>
        <td class="right" style="width: 60%;">{!! $section('top_partners') !!}</td>
    </tr>
</table>
<div class="gap"></div>

<div class="section">{!! $section('definitions') !!}</div>
</body>
</html>
