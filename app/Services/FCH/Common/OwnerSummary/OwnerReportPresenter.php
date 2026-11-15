<?php

namespace App\Services\FCH\Common\OwnerSummary;

use App\Models\Partner\Partner;
use Carbon\CarbonImmutable;

/**
 * Turns OwnerSummaryService::summary() into what the weekly report prints, in
 * the words of the Owner Summary screen. MIRROR: react-fch
 * src/pages/owner-summary/components (format.ts and each card). Change the two
 * together, or the email will stop saying what the screen says.
 *
 * The PDF and the email body share resources/views/owner_summary/sections, fed
 * from here, so they cannot drift apart either.
 */
class OwnerReportPresenter
{
    public const CHART_BAR_AREA = 90;

    public const TONES = [
        'up' => ['background' => '#e8f5ef', 'color' => '#0b6b4c'],
        'down' => ['background' => '#fde8e7', 'color' => '#b42318'],
        'flat' => ['background' => '#f1f3f5', 'color' => '#3d4650'],
        'warn' => ['background' => '#fef0c7', 'color' => '#93370d'],
        'wl' => ['background' => '#eef2fe', 'color' => '#2f4fb8'],
        'cert' => ['background' => '#f1eefc', 'color' => '#4b3bb8'],
        'status' => ['background' => '#eef2fe', 'color' => '#2f4fb8'],
    ];

    public const COUNT_COLORS = ['red' => '#b42318', 'amber' => '#b54708', 'plain' => '#001f3f', 'zero' => '#adb5bd'];

    private const STAGE_COLORS = [
        'intake' => '#001f3f',
        'underwriting' => '#1f4a7a',
        'approved' => '#3a6ea5',
        'with_lender' => '#5b8cc2',
        'closing' => '#0b6b4c',
        'on_hold' => '#adb5bd',
    ];

    private const SOURCE_COLORS = ['#001f3f', '#1f4a7a', '#3a6ea5', '#8fb3d9', '#f7b84b', '#c9ced4', '#adb5bd', '#dee2e6', '#e9ecef', '#f1f3f5'];

    private const LONG_IDLE_DAYS = 30;

    public function __construct(
        private readonly array $report,
        public readonly string $company,
        private readonly string $portalUrl = '',
    ) {
    }

    // ---------------------------------------------------------------- format

    public static function money(float|int|null $value): string
    {
        return '$' . number_format((float) $value);
    }

    public static function compactMoney(float|int|null $value): string
    {
        $value = (float) $value;
        $size = abs($value);

        return match (true) {
            $size >= 1000000 => '$' . number_format($value / 1000000, 2) . 'M',
            $size >= 1000 => '$' . number_format($value / 1000, 1) . 'K',
            default => self::money($value),
        };
    }

    /** @return array{text: string, tone: string} */
    public static function delta(float|int $current, float|int $previous): array
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0
                ? ['text' => __('owner_summary.report.no_change'), 'tone' => 'flat']
                : ['text' => __('owner_summary.report.new'), 'tone' => 'up'];
        }

        $percent = (int) round(($current - $previous) / $previous * 100);

        return match (true) {
            $percent === 0 => ['text' => '0%', 'tone' => 'flat'],
            $percent > 0 => ['text' => "+{$percent}%", 'tone' => 'up'],
            default => ['text' => "{$percent}%", 'tone' => 'down'],
        };
    }

    public static function percent(float|int $part, float|int $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }

    private static function count(int $value, string $unit): string
    {
        return trans_choice("owner_summary.report.units.{$unit}", $value, ['count' => number_format($value)]);
    }

    private static function monthShort(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC')->format('M');
    }

    // --------------------------------------------------------------- headers

    public function periodLabel(): string
    {
        return OwnerSummaryPeriod::rangeLabel($this->report['period']['from'], $this->report['period']['to']);
    }

    public function comparedWithLabel(): string
    {
        return OwnerSummaryPeriod::rangeLabel($this->report['period']['previous_from'], $this->report['period']['previous_to']);
    }

    public function timezone(): string
    {
        return $this->report['period']['timezone'];
    }

    public function dashboardUrl(): string
    {
        return $this->url('owner-summary');
    }

    private function url(string $path): string
    {
        return rtrim($this->portalUrl, '/') . '/' . ltrim($path, '/');
    }

    // -------------------------------------------------------------- sections

    public function kpis(): array
    {
        $k = $this->report['kpis'];
        $cash = self::delta($k['cash_collected']['current'], $k['cash_collected']['previous']);
        $funded = self::delta($k['funded']['amount'], $k['funded']['previous_amount']);
        $deals = self::delta($k['funded']['deals'], $k['funded']['previous_deals']);
        $leads = self::delta($k['new_leads']['current'], $k['new_leads']['previous']);
        $before = fn (string $value) => __('owner_summary.report.vs_before', ['value' => $value]);
        $row = fn (string $label, string $value) => ['label' => __("owner_summary.report.{$label}"), 'value' => $value];
        $split = fn (string $host, string $partners) => [$row('by_host', $host), $row('by_partners', $partners)];
        $card = fn (string $label, string $value, array $rows, array $change = [], ?string $note = null) => [
            'label' => __("owner_summary.report.{$label}"),
            'value' => $value,
            'tag' => $change['text'] ?? null,
            'tone' => $change['tone'] ?? null,
            'note' => $note,
            'rows' => $rows,
        ];
        $conversion = $k['lead_conversion'];
        $newThisPeriod = fn (int $new) => $row('new_period', number_format($new));

        return [
            $card('cash_collected', self::compactMoney($k['cash_collected']['current']), [], $cash, $before(self::compactMoney($k['cash_collected']['previous']))),
            $card(
                'funded',
                self::compactMoney($k['funded']['amount']),
                $split(self::compactMoney($k['funded']['host']['amount']), self::compactMoney($k['funded']['partners']['amount'])),
                $funded,
                $before(self::compactMoney($k['funded']['previous_amount']))
            ),
            $card(
                'funded_deals',
                number_format($k['funded']['deals']),
                $split(number_format($k['funded']['host']['deals']), number_format($k['funded']['partners']['deals'])),
                $deals,
                $before(number_format($k['funded']['previous_deals']))
            ),
            $card('partners', number_format($k['partners']['total']), [$row('active', number_format($k['partners']['active'])), $newThisPeriod($k['partners']['new'])]),
            $card(
                'clients',
                number_format($k['clients']['total']),
                $split(number_format($k['clients']['host']), number_format($k['clients']['partners'])),
                [],
                __('owner_summary.report.clients_note', ['active' => number_format($k['clients']['active']), 'new' => number_format($k['clients']['new'])])
            ),
            $card(
                'kpi_leads',
                number_format($k['new_leads']['current']),
                $split(number_format($k['new_leads']['host']), number_format($k['new_leads']['partners'])),
                $leads,
                $before(number_format($k['new_leads']['previous']))
            ),
            $card(
                'lead_conversion',
                self::rate(
                    $conversion['host']['funded'] + $conversion['partners']['funded'],
                    $conversion['host']['leads'] + $conversion['partners']['leads']
                ),
                $split(self::rateOf($conversion['host']), self::rateOf($conversion['partners'])),
                [],
                __('owner_summary.report.lead_conversion_note')
            ),
        ];
    }

    /** "12%", or a dash when there were no leads to convert. */
    private static function rate(int $funded, int $leads): string
    {
        return $leads > 0 ? round($funded / $leads * 100) . '%' : '—';
    }

    /** "20% (3 of 15)". */
    private static function rateOf(array $conversion): string
    {
        return $conversion['leads'] > 0
            ? self::rate($conversion['funded'], $conversion['leads']) . " ({$conversion['funded']} of {$conversion['leads']})"
            : '—';
    }

    public function attention(): array
    {
        $a = $this->report['attention'];
        $stalledAfter = OwnerSummaryService::STALLED_AFTER_DAYS;
        $trialDays = OwnerSummaryService::TRIAL_ENDING_DAYS;
        $t = fn (string $key, array $replace = []) => __("owner_summary.report.attention_items.{$key}", $replace);

        $items = [
            [$t('unpaid', ['days' => OwnerSummaryService::UNPAID_AGE_DAYS]), $t('unpaid_sub', ['amount' => self::money($a['unpaid_over_30_days']['amount'])]), $a['unpaid_over_30_days']['invoices'], 'red', 'invoices'],
            [$t('failed'), $t('failed_sub', ['amount' => self::money($a['failed_invoices']['amount'])]), $a['failed_invoices']['invoices'], 'red', 'invoices'],
            [$t('owing'), $t('owing_sub'), $a['partners_owing'], 'red', 'partners'],
            [$t('suspended'), $t('suspended_sub'), $a['suspended_partners'], 'red', 'partners'],
            [$t('payments'), $t('payments_sub', ['failed' => $a['failed_payments'], 'disputed' => $a['disputed_payments']]), $a['failed_payments'] + $a['disputed_payments'], 'amber', 'payments'],
            [$t('manual'), $t('manual_sub'), $a['manual_payments_to_approve'], 'amber', 'payments'],
            [$t('payouts'), $t('payouts_sub', ['amount' => self::money($a['payouts_due']['amount'])]), $a['payouts_due']['deals'], 'amber', 'commissions'],
            [$t('stalled'), $t('stalled_sub', ['days' => $stalledAfter]), $a['stalled_deals'], 'amber', 'intake-review/funding-application'],
            [$t('embedded'), $t('embedded_sub'), $a['unreviewed_embedded_applications'], 'plain', 'embed-form/loan-applications'],
            [$t('prequal'), $t('prequal_sub'), $a['pending_prequalifications'], 'plain', 'intake-review/pre-qualification'],
            [
                $t('trials'),
                self::count($a['trials_ending']['partners'], 'partner') . ' · ' . self::count($a['trials_ending']['clients'], 'client') . ' ' . $t('trials_sub', ['days' => $trialDays]),
                $a['trials_ending']['partners'] + $a['trials_ending']['clients'],
                'plain',
                'partners',
            ],
        ];

        return array_map(fn (array $item) => [
            'label' => $item[0],
            'sub' => $item[1],
            'count' => $item[2],
            'tone' => $item[2] > 0 ? $item[3] : 'zero',
            'url' => $this->url($item[4]),
        ], $items);
    }

    public function attentionWaiting(): array
    {
        return array_values(array_filter($this->attention(), fn (array $item) => $item['count'] > 0));
    }

    public function streams(): array
    {
        $rows = $this->report['cash_by_stream'];

        return [
            'rows' => array_map(fn (array $row) => [
                'label' => __("owner_summary.streams.{$row['stream']}", ['company' => $this->company]),
                'note' => __("owner_summary.stream_notes.{$row['stream']}", ['company' => $this->company]),
                'payments' => $row['payments'],
                'amount' => self::money($row['amount']),
            ], $rows),
            'payments' => self::count((int) array_sum(array_column($rows, 'payments')), 'payment'),
            'amount' => self::money(array_sum(array_column($rows, 'amount'))),
        ];
    }

    public function commissions(): array
    {
        $c = $this->report['commissions'];

        return [
            'host_share' => self::money($c['host_share_booked']),
            'partner_share' => self::money($c['partner_share']),
            'paid_to_partners' => self::money($c['paid_to_partners']),
            'paid_to_staff' => self::money($c['paid_to_staff']),
            'payouts_due' => self::money($c['payouts_due']),
        ];
    }

    public function owed(): array
    {
        $r = $this->report['receivables'];
        $parts = [
            [__('owner_summary.report.unpaid_over', ['days' => OwnerSummaryService::UNPAID_AGE_DAYS]), '#b42318', $r['unpaid_over_30_days']],
            [__('owner_summary.report.unpaid_under', ['days' => OwnerSummaryService::UNPAID_AGE_DAYS]), '#8fb3d9', $r['unpaid_recent']],
            [__('owner_summary.report.payment_failed'), '#f7b84b', $r['failed']],
        ];
        $amount = array_sum(array_map(fn (array $part) => $part[2]['amount'], $parts));
        $invoices = (int) array_sum(array_map(fn (array $part) => $part[2]['invoices'], $parts));

        return [
            'amount' => self::compactMoney($amount),
            'invoices' => self::count($invoices, 'invoice'),
            'parts' => array_map(fn (array $part) => [
                'label' => $part[0],
                'color' => $part[1],
                'percent' => self::percent($part[2]['amount'], $amount),
                'detail' => $part[2]['invoices'] . ' · ' . self::money($part[2]['amount']),
            ], $parts),
        ];
    }

    public function pipeline(): array
    {
        $p = $this->report['pipeline'];
        $most = max(1, ...array_column($p['groups'], 'count'));
        $stage = fn (string $key) => __("owner_summary.report.stages.{$key}");
        $span = fn (array $statuses) => count($statuses) > 1
            ? __('owner_summary.report.status_span', ['first' => $statuses[0], 'last' => $statuses[count($statuses) - 1]])
            : ($statuses[0] ?? '');
        $activity = [...array_column($p['groups'], 'key'), OwnerSummaryService::ACTIVITY_FUNDED];

        return [
            'summary' => __('owner_summary.report.open_summary', ['count' => $p['open']['count'], 'amount' => self::compactMoney($p['open']['requested'])]),
            'stages' => array_map(fn (array $group) => [
                'label' => $stage($group['key']),
                'statuses' => $span($group['statuses']),
                'count' => $group['count'],
                'requested' => self::money($group['requested']),
                'percent' => self::percent($group['count'], $most),
                'color' => self::STAGE_COLORS[$group['key']] ?? '#001f3f',
            ], $p['groups']),
            'activity' => array_map(fn (string $key) => [
                'label' => __('owner_summary.report.reached', ['stage' => mb_strtolower($stage($key))]),
                'count' => $p['activity'][$key] ?? 0,
            ], $activity),
            'note' => __('owner_summary.report.activity_note') . ' ' . ($p['median_days_to_fund'] === null
                ? __('owner_summary.report.none_funded')
                : __('owner_summary.report.median', ['days' => $p['median_days_to_fund']])),
        ];
    }

    public function leadSources(): array
    {
        $s = $this->report['lead_sources'];
        $leads = array_values(array_filter($s['leads'], fn (array $row) => $row['count'] > 0));
        usort($leads, fn (array $a, array $b) => $b['count'] <=> $a['count']);
        $total = (int) array_sum(array_column($leads, 'count'));

        $rows = [];
        foreach ($leads as $i => $row) {
            $rows[] = [
                'name' => $row['source'] === OwnerSummaryService::LEAD_SOURCE_NOT_SET ? __('owner_summary.report.source_not_set') : $row['source'],
                'count' => $row['count'],
                'percent' => self::percent($row['count'], $total),
                'color' => self::SOURCE_COLORS[$i % count(self::SOURCE_COLORS)],
            ];
        }

        return [
            'summary' => __('owner_summary.report.leads_summary', ['count' => $total]),
            'rows' => $rows,
            'prequalifications' => $s['prequalifications'],
            'embedded_applications' => $s['embedded_applications'],
        ];
    }

    public function charts(): array
    {
        return [
            $this->chart('funded', __('owner_summary.report.chart_funded'), __('owner_summary.report.chart_funded_sub')),
            $this->chart('cash', __('owner_summary.report.chart_cash'), __('owner_summary.report.chart_cash_sub', ['company' => $this->company])),
        ];
    }

    private function chart(string $field, string $title, string $sub): array
    {
        $points = $this->report['monthly'];
        $most = max(1, ...array_column($points, $field));
        $last = array_key_last($points);

        return [
            'title' => $title,
            'sub' => $sub,
            'trend' => $this->growth($points, $field),
            'bars' => array_map(fn (int $i) => [
                'month' => self::monthShort($points[$i]['month']),
                'value' => $points[$i][$field] > 0 ? self::compactMoney($points[$i][$field]) : '—',
                'height' => (int) round($points[$i][$field] / $most * self::CHART_BAR_AREA),
                'current' => $i === $last,
            ], array_keys($points)),
        ];
    }

    /** @return array{text: string, tone: string}|null */
    private function growth(array $points, string $field): ?array
    {
        $first = null;
        foreach ($points as $point) {
            if ($point[$field] > 0) {
                $first = $point;
                break;
            }
        }
        $last = $points[array_key_last($points)] ?? null;

        if (!$first || !$last || $first['month'] === $last['month']) {
            return null;
        }

        $ratio = $last[$field] / $first[$field];
        $since = __('owner_summary.report.since', ['month' => self::monthShort($first['month'])]);

        if ($ratio >= 2) {
            return ['text' => number_format($ratio, 1) . "× {$since}", 'tone' => 'up'];
        }

        $percent = (int) round(($ratio - 1) * 100);

        return [
            'text' => ($percent > 0 ? '+' : '') . "{$percent}% {$since}",
            'tone' => $percent > 0 ? 'up' : ($percent < 0 ? 'down' : 'flat'),
        ];
    }

    public function network(): array
    {
        $n = $this->report['partners'];
        $active = $this->report['kpis']['active_partners'];

        return [
            'white_label' => $n['white_label'],
            'certified' => $n['certified'],
            'active' => __('owner_summary.report.of_total', ['count' => $active['current'], 'total' => $active['total']]),
            'active_percent' => self::percent($active['current'], $active['total']),
            'new' => $n['new'],
            'on_trial' => __('owner_summary.report.on_trial_value', [
                'count' => $n['on_trial'],
                'ending' => $this->report['attention']['trials_ending']['partners'],
                'days' => OwnerSummaryService::TRIAL_ENDING_DAYS,
            ]),
            'suspended' => $n['suspended'],
        ];
    }

    public function topPartners(): array
    {
        return array_map(function (array $partner) {
            $certified = $partner['partner_type'] === Partner::PARTNER_TYPE_CERTIFIED;

            return [
                'name' => $partner['company_name'] ?? '—',
                'type' => __($certified ? 'owner_summary.report.certified' : 'owner_summary.report.white_label'),
                'type_tone' => $certified ? 'cert' : 'wl',
                'leads' => $partner['leads'],
                'funded' => $partner['funded_deals'],
                'volume' => self::money($partner['funded_amount']),
                'paid' => self::money($partner['commission_paid']),
            ];
        }, $this->report['top_partners']);
    }

    public function stalled(): array
    {
        $a = $this->report['attention'];
        $deals = $this->report['stalled_deals'];

        return [
            'summary' => self::count($a['stalled_deals'], 'deal') . ' · ' . __('owner_summary.report.requested_amount', ['amount' => self::compactMoney($a['stalled_requested'])]),
            'sub' => __('owner_summary.report.stalled_sub', ['days' => OwnerSummaryService::STALLED_AFTER_DAYS]),
            'showing' => __('owner_summary.report.stalled_showing', ['shown' => count($deals), 'total' => $a['stalled_deals']]),
            'rows' => array_map(fn (array $deal) => [
                'application' => $deal['application_number'] ?? "#{$deal['id']}",
                'business' => $deal['client'] ?? '—',
                'partner' => $deal['partner'] ?? '—',
                'status' => $deal['status'],
                'requested' => self::money($deal['requested_amount']),
                'idle' => __('owner_summary.report.days', ['count' => $deal['days_in_status']]),
                'long' => $deal['days_in_status'] >= self::LONG_IDLE_DAYS,
            ], $deals),
        ];
    }
}
