@extends('pdf.owner-summary-export-layout')

{{--
    Business Insights › Details Report, in the same design as the summary: a
    cover page with its contents, then every section of the screen in full -
    the headline cards, then barred, numbered sections of banded tables -
    every stalled deal included. Every value comes from OwnerReportPresenter,
    in the period's own words.
--}}
@php
    $sections = ['glance', 'money', 'attention', 'trends', 'pipeline', 'partners', 'stalled', 'definitions'];
    $no = fn (string $key) => sprintf('%02d', array_search($key, $sections, true) + 1);
    $stalledAfter = \App\Services\FCH\Common\OwnerSummary\OwnerSummaryService::STALLED_AFTER_DAYS;
@endphp

@section('cover')
<div class="cover">
    @if ($logoBase64)
        <img src="{{ $logoBase64 }}" alt="{{ $companyName }}" style="height: {{ round($logoPrintHeight * 1.5) }}px; width: {{ round($logoPrintWidth * 1.5) }}px;">
    @else
        <div style="font-size: 18pt; font-weight: 700;">{{ $companyName }}</div>
    @endif

    <div style="height: 96px;"></div>
    <div class="eyebrow" style="font-size: 8pt;">{{ $title }}</div>
    <div style="margin-top: 6px; font-size: 30pt; font-weight: 700; line-height: 1.1; color: #0f1f2e;">{{ $typeLabel }}</div>
    <div style="margin-top: 14px; width: 56px; height: 4px; background-color: #1f8a94; border-radius: 2px;"></div>
    <div style="margin-top: 14px; font-size: 11pt; color: #4a5b6b;">{{ $periodName }} &middot; <b style="color: #0f1f2e;">{{ $summary->periodLabel() }}</b></div>

    <div style="height: 26px;"></div>
    <div style="padding-left: 16px; border-left: 3px solid #1f8a94;">
        @include('pdf.partials.owner-export-meta', ['withGenerated' => true])
    </div>

    <div style="height: 34px;"></div>
    <div class="label" style="padding-bottom: 6px; border-bottom: 1px solid #0f1f2e;">{{ __('owner_summary.export.contents') }}</div>
    <table class="full">
        @foreach ($sections as $key)
            <tr>
                <td style="width: 32px; padding: 6px 0; border-bottom: 1px solid #e3e8ec; font-size: 10pt; font-weight: 700; color: #1a7680;">{{ $no($key) }}</td>
                <td style="padding: 6px 0; border-bottom: 1px solid #e3e8ec;">
                    <span style="font-size: 9.5pt; font-weight: 700; color: #0f1f2e;">{{ __("owner_summary.export.sections.{$key}") }}</span>
                    <span style="font-size: 7.5pt; color: #4a5b6b;"> &mdash; {{ __("owner_summary.export.sections_sub.{$key}", ['days' => $stalledAfter]) }}</span>
                </td>
            </tr>
        @endforeach
    </table>

    <div class="note" style="margin-top: 24px;">
        {{ __('owner_summary.export.confidential', ['company' => $companyName]) }}. {{ __('owner_summary.export.cover_note') }}
    </div>
</div>
@endsection

@section('content')
@php
    $x = fn (string $key, array $replace = []) => __("owner_summary.export.{$key}", $replace);
    $r = fn (string $key, array $replace = []) => $summary->t($key, $replace);
    $head = fn (string $key) => ['no' => $no($key), 'heading' => $x("sections.{$key}"), 'sub' => $x("sections_sub.{$key}", ['days' => $stalledAfter])];

    $commissions = $summary->commissions();
    $owed = $summary->owed();
    $pipeline = $summary->pipeline();
    $leads = $summary->leadSources();
    $network = $summary->network();
    $partners = $summary->topPartners();
    $stalled = $summary->stalled();
@endphp

{{-- 01 At a glance: the summary's cards, then every metric --}}
@include('pdf.partials.owner-export-cards', ['numbered' => $no])
<div class="section keep">
    <div class="sub-title" style="margin-top: 0;">{{ $x('all_metrics') }}</div>
    @include('pdf.partials.owner-export-metrics')
</div>

{{-- 02 Money in & owed --}}
<div class="section">
    <div class="keep">
        @include('pdf.partials.owner-export-heading', $head('money'))
        <div class="sub-title" style="margin-top: 0;">{{ $r('cash_collected') }}</div>
        @include('pdf.partials.owner-export-streams', ['withNotes' => true])
        <div class="note">{{ $r('cash_note', ['company' => $summary->company]) }}</div>
    </div>

    <div class="keep">
        <div class="sub-title">{{ $r('commissions') }} <span class="aside">&middot; {{ $r('commissions_sub') }}</span></div>
        <table class="full tbl">
            <tr><th>{{ $x('item') }}</th><th class="r">{{ $x('amount') }}</th></tr>
            <tr><td>{{ $r('host_share') }}</td><td class="r">{{ $commissions['host_share'] }}</td></tr>
            <tr class="even"><td>{{ $r('partner_share') }}</td><td class="r">{{ $commissions['partner_share'] }}</td></tr>
            <tr><td>{{ $r('paid_to_partners') }}</td><td class="r">{{ $commissions['paid_to_partners'] }}</td></tr>
            <tr class="even"><td>{{ $r('paid_to_staff') }}</td><td class="r">{{ $commissions['paid_to_staff'] }}</td></tr>
            <tr class="total"><td>{{ $r('payouts_due') }}</td><td class="r">{{ $commissions['payouts_due'] }}</td></tr>
        </table>
    </div>

    <div class="keep">
        <div class="sub-title">{{ $r('owed_to', ['company' => $summary->company]) }} <span class="aside">&middot; {{ $r('owed_sub') }}</span></div>
        <table class="full tbl">
            <tr><th>{{ $x('item') }}</th><th class="r">{{ $x('amount') }}</th></tr>
            @foreach ($owed['parts'] as $part)
                <tr class="{{ $loop->even ? 'even' : '' }}"><td>{{ $part['label'] }}</td><td class="r">{{ $part['detail'] }}</td></tr>
            @endforeach
            <tr class="total"><td>{{ $owed['invoices'] }}</td><td class="r">{{ $owed['amount'] }}</td></tr>
        </table>
    </div>
</div>

{{-- 03 Needs attention: every item --}}
<div class="section keep">
    @include('pdf.partials.owner-export-heading', $head('attention'))
    <table class="full tbl">
        <tr><th style="width: 40%;">{{ $x('item') }}</th><th>{{ $x('detail') }}</th><th class="r">{{ $x('count') }}</th></tr>
        @foreach ($summary->attention() as $item)
            <tr class="{{ $loop->even ? 'even' : '' }}">
                <td><span class="dot {{ $item['count'] > 0 ? '' : 'off' }}"></span>{{ $item['label'] }}</td>
                <td class="muted">{{ $item['sub'] }}</td>
                <td class="r {{ $item['count'] > 0 ? 'strong' : 'muted' }}">{{ $item['count'] }}</td>
            </tr>
        @endforeach
    </table>
</div>

{{-- 04 Trends --}}
<div class="section keep">
    @include('pdf.partials.owner-export-heading', $head('trends'))
    @include('pdf.partials.owner-export-trend')
</div>

{{-- 05 Pipeline & lead sources --}}
<div class="section">
    <div class="keep">
        @include('pdf.partials.owner-export-heading', $head('pipeline'))
        <div class="sub-title" style="margin-top: 0;">{{ $r('pipeline') }} <span class="aside">&middot; {{ $pipeline['summary'] }}</span></div>
        <table class="full tbl">
            <tr>
                <th style="width: 28%;">{{ $x('stage') }}</th>
                <th>{{ $x('statuses') }}</th>
                <th class="r" style="width: 12%;">{{ $x('open') }}</th>
                <th class="r" style="width: 16%;">{{ $r('requested') }}</th>
            </tr>
            @foreach ($pipeline['stages'] as $stage)
                <tr class="{{ $loop->even ? 'even' : '' }}">
                    <td>{{ $stage['label'] }}</td>
                    <td class="muted">{{ $stage['statuses'] }}</td>
                    <td class="r">{{ $stage['count'] }}</td>
                    <td class="r">{{ $stage['requested'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="keep">
        <div class="sub-title">{{ $x('reached') }}</div>
        <table class="full tbl">
            <tr>
                @foreach ($pipeline['activity'] as $activity)
                    <th class="r" style="width: {{ floor(100 / max(1, count($pipeline['activity']))) }}%;">{{ $activity['label'] }}</th>
                @endforeach
            </tr>
            <tr>
                @foreach ($pipeline['activity'] as $activity)
                    <td class="r strong">{{ $activity['count'] }}</td>
                @endforeach
            </tr>
        </table>
        <div class="note">{{ $pipeline['note'] }}</div>
    </div>

    <div class="keep">
        <div class="sub-title">{{ $r('leads') }} <span class="aside">&middot; {{ $leads['summary'] }}</span></div>
        <table class="full tbl">
            <tr><th>{{ $x('source') }}</th><th class="r" style="width: 12%;">{{ $r('leads_column') }}</th><th class="r" style="width: 16%;">{{ $x('share') }}</th></tr>
            @forelse ($leads['rows'] as $row)
                <tr class="{{ $loop->even ? 'even' : '' }}"><td>{{ $row['name'] }}</td><td class="r">{{ $row['count'] }}</td><td class="r">{{ $row['percent'] }}%</td></tr>
            @empty
                <tr><td colspan="3" class="muted">{{ $r('no_leads') }}</td></tr>
            @endforelse
        </table>
    </div>

    <div class="keep">
        <div class="sub-title">{{ $r('other_ways') }}</div>
        <table class="full tbl">
            <tr><th>{{ $x('channel') }}</th><th class="r" style="width: 12%;">{{ $x('count') }}</th></tr>
            <tr><td>{{ $r('prequal_requests') }}</td><td class="r">{{ $leads['prequalifications'] }}</td></tr>
            <tr class="even"><td>{{ $r('embedded_applications') }}</td><td class="r">{{ $leads['embedded_applications'] }}</td></tr>
        </table>
    </div>
</div>

{{-- 06 Partners --}}
<div class="section">
    <div class="keep">
        @include('pdf.partials.owner-export-heading', $head('partners'))
        <div class="sub-title" style="margin-top: 0;">{{ $r('network') }}</div>
        <table class="full tbl">
            <tr><th>{{ $x('item') }}</th><th class="r">{{ $x('count') }}</th></tr>
            @foreach ([
                [$r('white_label'), $network['white_label']],
                [$r('certified'), $network['certified']],
                [$r('active_line'), $network['active']],
                [$r('new_period'), $network['new']],
                [$r('on_trial'), $network['on_trial']],
                [$r('suspended'), $network['suspended']],
            ] as [$label, $value])
                <tr class="{{ $loop->even ? 'even' : '' }}"><td>{{ $label }}</td><td class="r">{{ $value }}</td></tr>
            @endforeach
        </table>
    </div>

    <div class="keep">
        <div class="sub-title">{{ $r('top_partners') }} <span class="aside">&middot; {{ $r('top_partners_sub') }}</span></div>
        <table class="full tbl">
            <tr>
                <th style="width: 28%;">{{ $r('partner') }}</th>
                <th>{{ $r('type') }}</th>
                <th class="r">{{ $r('leads_column') }}</th>
                <th class="r">{{ $r('funded_column') }}</th>
                <th class="r">{{ $r('volume') }}</th>
                <th class="r">{{ $r('commission_paid') }}</th>
            </tr>
            @forelse ($partners as $partner)
                <tr class="{{ $loop->even ? 'even' : '' }}">
                    <td class="strong">{{ $partner['name'] }}</td>
                    <td class="muted">{{ $partner['type'] }}</td>
                    <td class="r">{{ $partner['leads'] }}</td>
                    <td class="r">{{ $partner['funded'] }}</td>
                    <td class="r">{{ $partner['volume'] }}</td>
                    <td class="r">{{ $partner['paid'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">{{ $r('no_partners') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- 07 Stalled deals: all of them --}}
<div class="section">
    @include('pdf.partials.owner-export-heading', $head('stalled'))
    <div class="sub-title" style="margin-top: 0;">{{ $stalled['summary'] }}@if ($stalledCount > 0) <span class="aside">&middot; {{ $x('stalled_all', ['count' => $stalledCount]) }}</span>@endif</div>
    <table class="full tbl">
        <tr>
            <th>{{ $r('application') }}</th>
            <th>{{ $r('business') }}</th>
            <th>{{ $r('partner') }}</th>
            <th>{{ $r('status') }}</th>
            <th class="r">{{ $r('requested') }}</th>
            <th class="r">{{ $r('days_idle') }}</th>
        </tr>
        @forelse ($stalled['rows'] as $deal)
            <tr class="{{ $loop->even ? 'even' : '' }}">
                <td class="strong">{{ $deal['application'] }}</td>
                <td>{{ $deal['business'] }}</td>
                <td class="muted">{{ $deal['partner'] }}</td>
                <td class="muted">{{ $deal['status'] }}</td>
                <td class="r">{{ $deal['requested'] }}</td>
                <td class="r {{ $deal['long'] ? 'strong' : '' }}">{{ $deal['idle'] }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ $r('no_stalled') }}</td></tr>
        @endforelse
    </table>
</div>

{{-- 08 Definitions --}}
<div class="section keep">
    @include('pdf.partials.owner-export-heading', $head('definitions'))
    <div style="padding: 10px 12px; border: 1px solid #dde3e8; border-radius: 2px; background-color: #f5f8fa; font-size: 7.5pt; line-height: 1.6; color: #374857;">
        <p style="margin: 0 0 5px;"><b style="color: #0f1f2e;">{{ $r('definitions_title') }}</b> {{ $r('definitions_timezone', ['timezone' => $summary->timezone()]) }}</p>
        <p style="margin: 0 0 5px;">{{ $r('definitions', ['company' => $summary->company, 'days' => $stalledAfter]) }}</p>
        <p style="margin: 0;">{{ $r('definitions_missing') }}</p>
    </div>
</div>
@endsection
