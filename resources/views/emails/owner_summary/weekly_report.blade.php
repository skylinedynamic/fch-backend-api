@extends('emails.layouts.mail')

@section('title', __('owner_summary.report.title') . ' - ' . $siteName)

@php
    $r = fn (string $key, array $replace = []) => __("owner_summary.report.{$key}", $replace);
    [$fundedChart, $cashChart] = $summary->charts();
@endphp

@section('content')
    <div style="margin-bottom: 12px;">
        <span style="font-size: 22px; font-weight: 700; color: #001f3f;">{{ $r('heading') }}</span>
        <span style="margin-left: 6px; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background-color: #fff4de; color: #7a4d00; white-space: nowrap;">{{ $r('tag') }}</span>
        <div style="margin-top: 4px; font-size: 13px; color: #5c6670;">{{ $r('subtitle', ['company' => $siteName]) }}</div>
    </div>

    <p style="margin: 0 0 14px; font-size: 12.5px; line-height: 1.5; color: #5c6670;">
        {{ $r('showing') }} <strong style="color: #212529;">{{ $r('last_week_range', ['period' => $summary->periodLabel()]) }}</strong>
        &middot; {{ $r('compared_with', ['period' => $summary->comparedWithLabel()]) }}
        &middot; {{ $r('times_in', ['timezone' => $summary->timezone()]) }}
        &middot; {{ $r('live_only') }}
    </p>

    @include('owner_summary.sections', ['section' => 'kpis', 'columns' => 3])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 8px 0 18px;">
        <tr>
            <td bgcolor="#001f3f" style="background-color: #001f3f; border-radius: 4px;">
                <a href="{{ $summary->dashboardUrl() }}" style="display: inline-block; padding: 11px 20px; font-size: 13.5px; font-weight: 600; color: #ffffff; text-decoration: none;">{{ $r('open_dashboard') }}</a>
            </td>
        </tr>
    </table>

    @include('owner_summary.sections', ['section' => 'cash'])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'commissions'])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'owed'])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'attention', 'columns' => 1, 'links' => true])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'pipeline'])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'leads'])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'chart', 'chart' => $fundedChart])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'chart', 'chart' => $cashChart])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'network'])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'top_partners'])
    <div style="height: 12px; line-height: 12px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'stalled'])
    <div style="height: 14px; line-height: 14px; font-size: 0;">&nbsp;</div>
    @include('owner_summary.sections', ['section' => 'definitions'])

    <p style="margin: 14px 0 0; font-size: 12.5px; font-weight: 600; color: #212529;">{{ $r('attached') }}</p>
@endsection
