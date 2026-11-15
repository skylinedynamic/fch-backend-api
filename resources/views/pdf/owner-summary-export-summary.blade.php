@extends('pdf.owner-summary-export-layout')

{{--
    Business Insights › Summary Report, in the design the owner supplied: the
    headline cards (performance, receivables & payables, network), then net
    earnings by stream. One page. Every value comes from OwnerReportPresenter.
--}}
@section('content')
@include('pdf.partials.owner-export-cards')

<div class="section keep">
    @include('pdf.partials.owner-export-heading', ['no' => null, 'heading' => $summary->t('cash_collected'), 'sub' => __('owner_summary.export.net_earnings_sub')])
    @include('pdf.partials.owner-export-streams')
</div>
@endsection
