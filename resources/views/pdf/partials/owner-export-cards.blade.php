{{--
    The headline cards both reports open with: performance (net earnings
    first, filled), receivables & payables, and the network. Each change is a
    badge with its direction; a zero value is greyed.
--}}
@php
    $x = fn (string $key, array $replace = []) => __("owner_summary.export.{$key}", $replace);
    [$cash, $funded, $deals, , , $leads] = $summary->kpis();
    $owed = $summary->owed();
    $commissions = $summary->commissions();
    $community = $summary->community();

    $tones = ['up' => ['pos', '▲'], 'down' => ['neg', '▼']];
    $badge = function (array $kpi) use ($tones): string {
        [$class, $arrow] = $tones[$kpi['tone']] ?? ['neu', '—'];

        return '<span class="badge ' . $class . '">' . $arrow . ' ' . e(ucfirst((string) $kpi['tag'])) . '</span>';
    };
    $isZero = fn (string $value) => (bool) preg_match('/^\$?0$/', $value);
    $newThisPeriod = lcfirst($summary->t('new_period'));

    // A row of cards with even gaps: dompdf has no grid, so each cell pads towards its neighbours.
    $gap = fn (int $i, int $n) => 'width: ' . round(100 / $n, 2) . '%; padding: 0 ' . ($i === $n - 1 ? 0 : 4) . 'px 0 ' . ($i === 0 ? 0 : 4) . 'px;';
    $numbered = $numbered ?? false;
@endphp

{{-- At a glance --}}
<div class="section first keep">
    @include('pdf.partials.owner-export-heading', ['no' => $numbered ? $numbered('glance') : null, 'heading' => $x('sections.glance'), 'sub' => $x('sections_sub.glance')])
    <table class="full">
        <tr>
            @foreach ([$cash, $funded, $deals, $leads] as $i => $kpi)
                <td style="{{ $gap($i, 4) }}">
                    <div class="card {{ $i === 0 ? 'hero' : '' }}">
                        <div class="label">{{ $kpi['label'] }}</div>
                        <div class="value {{ $isZero($kpi['value']) ? 'zero' : '' }}">{{ $kpi['value'] }}</div>
                        <div class="card-note">
                            {!! $badge($kpi) !!}
                            <div class="vs" style="margin-top: 4px;">{{ $kpi['note'] }}</div>
                        </div>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>
</div>

{{-- Receivables & payables --}}
<div class="section keep">
    @include('pdf.partials.owner-export-heading', ['no' => null, 'heading' => $x('receivables'), 'sub' => $x('receivables_sub')])
    <table class="full">
        <tr>
            <td style="{{ $gap(0, 2) }}">
                <div class="card">
                    <div class="label">{{ $x('outstanding') }}</div>
                    <div class="value {{ $isZero($owed['amount']) ? 'zero' : '' }}">{{ $owed['amount'] }}</div>
                    <div class="card-note">{{ $x('awaiting_payment', ['invoices' => $owed['invoices']]) }}</div>
                </div>
            </td>
            <td style="{{ $gap(1, 2) }}">
                <div class="card">
                    <div class="label">{{ __('owner_summary.report.payouts_due') }}</div>
                    <div class="value {{ $isZero($commissions['payouts_due']) ? 'zero' : '' }}">{{ $commissions['payouts_due'] }}</div>
                    <div class="card-note">{{ ucfirst($x('payouts_note')) }}</div>
                </div>
            </td>
        </tr>
    </table>
</div>

{{-- Network --}}
<div class="section keep">
    @include('pdf.partials.owner-export-heading', ['no' => null, 'heading' => $x('network_title'), 'sub' => $x('network_sub')])
    <table class="full">
        <tr>
            @foreach ([__('owner_summary.report.partners') => $community['partners'], __('owner_summary.report.clients') => $community['clients']] as $label => $group)
                <td style="{{ $gap($loop->index, 2) }}">
                    <div class="card">
                        <div class="label">{{ $label }}</div>
                        <div class="value {{ $isZero($group['total']) ? 'zero' : '' }}">{{ $group['total'] }}</div>
                        <div class="card-note">
                            <b>{{ $group['active'] }}</b> {{ $x('active_word') }} &middot;
                            <span class="badge {{ $group['new'] > 0 ? 'pos' : 'neu' }}">+{{ number_format($group['new']) }}</span>
                            <span class="vs">{{ $newThisPeriod }}</span>
                        </div>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>
</div>
