<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $typeLabel }} · {{ $companyName }}</title>
{{--
    The frame both Business Insights exports share, in the design the owner
    supplied: a teal accent bar, the document logo with the report and period,
    barred section headings, cards for headline figures, and tables with
    banded rows and a tinted total. Text is kept dark enough to read when
    printed (secondary text #4a5b6b, muted #5b6b7a).

    A report with a `cover` section (the details report) gets that page
    first; OwnerSummaryExportPdf paints the running header and footer out of
    it. One without gets the title block.

    dompdf: tables instead of flex or grid, solid fills instead of gradients,
    DejaVu Sans, weight 700 only. "Page X of Y" is drawn at the bottom right
    by OwnerSummaryExportPdf, in line with the footer.
--}}
<style>
    @page { margin: 96px 48px 62px 48px; }
    body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.45; color: #0f1f2e; }
    table { border-collapse: collapse; }
    td, th { vertical-align: top; }
    .full { width: 100%; }
    .keep { page-break-inside: avoid; }
    .muted { color: #5b6b7a; }
    .strong { font-weight: 700; }

    .accent { position: fixed; top: -96px; left: -48px; right: -48px; height: 6px; background-color: #1f8a94; }
    .runhead { position: fixed; top: -80px; left: 0; right: 0; height: 52px; }
    .runmeta { text-align: right; font-size: 7pt; line-height: 1.6; color: #4a5b6b; }
    .runmeta b { color: #0f1f2e; }
    .foot { position: fixed; bottom: -42px; left: 0; right: 0; padding-top: 8px; border-top: 1px solid #cfd7de; font-size: 7pt; color: #4a5b6b; }
    .foot b { color: #0f1f2e; }

    .title { border-top: 1px solid #cfd7de; }
    .eyebrow { font-size: 6.5pt; font-weight: 700; letter-spacing: 1.6px; text-transform: uppercase; color: #1a7680; }
    .h1 { margin-top: 3px; font-size: 17pt; font-weight: 700; line-height: 1.05; color: #0f1f2e; }
    .period { margin-top: 4px; font-size: 8.5pt; color: #4a5b6b; }
    .period b { color: #0f1f2e; }
    .meta-label { padding: 1px 12px 1px 0; font-size: 6pt; font-weight: 700; letter-spacing: 1.1px; text-transform: uppercase; color: #5b6b7a; }
    .meta-value { padding: 0; font-size: 7.5pt; color: #0f1f2e; }

    /* Sections: a barred heading with its description on the right. */
    .section { margin-top: 18px; }
    .section.first { margin-top: 4px; }
    .sh { margin-bottom: 7px; }
    .sh-title { font-size: 10.5pt; font-weight: 700; color: #0f1f2e; }
    .sh-no { color: #1a7680; padding-right: 5px; }
    .sh-bar { display: inline-block; width: 3px; height: 11px; margin-right: 7px; background-color: #1f8a94; border-radius: 2px; }
    .sh-sub { text-align: right; font-size: 7.5pt; color: #4a5b6b; vertical-align: bottom; }
    .sub-title { margin: 12px 0 5px; font-size: 8.5pt; font-weight: 700; color: #0f1f2e; }
    .sub-title .aside { font-weight: 400; color: #4a5b6b; }
    .note { margin-top: 5px; font-size: 7pt; line-height: 1.5; color: #4a5b6b; }

    /* Cards */
    .card { border: 1px solid #dde3e8; border-radius: 2px; padding: 10px 12px 9px; background-color: #ffffff; }
    .card.hero { border-color: #125f68; background-color: #125f68; color: #ffffff; }
    .label { font-size: 6.5pt; font-weight: 700; letter-spacing: 1.1px; text-transform: uppercase; color: #5b6b7a; }
    .hero .label { color: #d6eaec; }
    .value { margin: 4px 0 5px; font-size: 17pt; font-weight: 700; line-height: 1.1; color: #0f1f2e; }
    .value.zero { color: #5b6b7a; }
    .hero .value, .hero .value.zero { color: #ffffff; }
    .card-note { font-size: 7.5pt; color: #374857; }
    .hero .card-note { color: #e3f1f2; }
    .badge { font-size: 7pt; font-weight: 700; padding: 1px 5px; border-radius: 2px; }
    .badge.pos { background-color: #e4f4ec; color: #146c45; }
    .badge.neg { background-color: #fdeceb; color: #a31f15; }
    .badge.neu { background-color: #eef1f4; color: #4a5b6b; }
    .hero .badge.neu { background-color: #3a7a82; color: #ffffff; }
    .vs { font-size: 7pt; color: #4a5b6b; }
    .hero .vs { color: #d6eaec; }

    /* Tables: dark rule under the headings, banded rows, a tinted total. */
    .tbl th { padding: 0 9px 6px; font-size: 6.5pt; font-weight: 700; letter-spacing: 1.1px; text-transform: uppercase; text-align: left; color: #4a5b6b; border-bottom: 1px solid #0f1f2e; }
    .tbl td { padding: 6px 9px; border-bottom: 1px solid #e3e8ec; color: #0f1f2e; }
    .tbl tr.even td { background-color: #f5f8fa; }
    .tbl td.muted { color: #5b6b7a; }
    .tbl tr.total td { padding: 7px 9px; font-weight: 700; border-top: 1px solid #0f1f2e; border-bottom: none; background-color: #e6f3f4; }
    .tbl .r { text-align: right; white-space: nowrap; }
    .tbl th.r { text-align: right; }
    .dot { display: inline-block; width: 6px; height: 6px; margin-right: 7px; border-radius: 3px; background-color: #1f8a94; }
    .dot.off { background-color: #cfd7de; }
</style>
</head>
<body>

@hasSection('cover')
    @yield('cover')
    <div style="page-break-after: always;"></div>
@endif

<div class="accent"></div>

<div class="runhead">
    <table class="full">
        <tr>
            <td style="vertical-align: middle;">
                @if ($logoBase64)
                    <img src="{{ $logoBase64 }}" alt="{{ $companyName }}" style="height: {{ $logoPrintHeight }}px; width: {{ $logoPrintWidth }}px;">
                @else
                    <span style="font-size: 12pt; font-weight: 700; letter-spacing: 1px;">{{ $companyName }}</span>
                @endif
            </td>
            <td class="runmeta" style="vertical-align: middle;">
                <b>{{ $companyName }}</b><br>
                {{ $title }} &middot; {{ $typeLabel }}<br>
                {{ $periodName }}, {{ $summary->periodLabel() }}
            </td>
        </tr>
    </table>
</div>

<div class="foot">
    <table class="full">
        <tr>
            <td style="width: 44%;"><b>{{ $companyName }}</b> &middot; {{ $title }} &middot; {{ $typeLabel }}</td>
            <td style="width: 44%; white-space: nowrap;">{{ __('owner_summary.export.generated_on', ['date' => $generatedOn]) }} &middot; {{ $summary->timezone() }}</td>
            <td></td>
        </tr>
    </table>
</div>

@unless (View::hasSection('cover'))
    {{-- Title --}}
    <table class="full title">
        <tr>
            <td style="width: 56%; padding: 11px 0 10px; vertical-align: bottom;">
                <div class="eyebrow">{{ $title }}</div>
                <div class="h1">{{ $typeLabel }}</div>
                <div class="period">{{ $periodName }} &middot; <b>{{ $summary->periodLabel() }}</b></div>
            </td>
            <td style="padding: 11px 0 10px; vertical-align: bottom;">
                <div style="padding-left: 22px; border-left: 1px solid #e3e8ec;">
                    @include('pdf.partials.owner-export-meta')
                </div>
            </td>
        </tr>
    </table>
@endunless

@yield('content')
</body>
</html>
