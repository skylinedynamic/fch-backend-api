<?php

namespace App\Services\FCH\Common\OwnerSummary;

use Carbon\CarbonImmutable;

/**
 * The Telegram summary: the Business Insights screen's six headline cards, in
 * Telegram's HTML (parse_mode=HTML), with the same numbers and labels:
 *
 *     📊 Four Corner · Weekly Summary          (bold)
 *     🗓 1 – 7 Nov 2026
 *
 *     💰 Net Earnings                         (bold)
 *     $14.3K | 📈 +30% | was $11.0K           (value bold, "was" italic)
 *
 *     🏦 Funded Volume
 *     $2.31M | 📉 -22% | was $2.96M
 *     ├ FCH: $1.31M
 *     └ Partners: $1.00M
 *     ...
 *
 * Every value is escaped, so a company name with & or < stays text.
 */
final class OwnerTelegramMessage
{
    private const GAP = ' | ';

    private const TREND_ICONS = ['up' => '📈', 'down' => '📉', 'flat' => '➖'];
    private const NEW_ICON = '🆕';

    /** @param array $summary OwnerSummaryService::summary()'s shape */
    public static function make(array $summary, string $company): string
    {
        $k = $summary['kpis'];
        $money = fn (float|int $value) => OwnerReportPresenter::compactMoney($value);
        $count = fn (int $value) => number_format($value);

        $cards = [
            self::card('💰', 'cash_collected', [
                self::changed($k['cash_collected']['current'], $k['cash_collected']['previous'], $money),
            ]),
            self::card('🏦', 'funded', [
                self::changed($k['funded']['amount'], $k['funded']['previous_amount'], $money),
                ...self::split($money($k['funded']['host']['amount']), $money($k['funded']['partners']['amount'])),
            ]),
            self::card('✅', 'funded_deals', [
                self::changed($k['funded']['deals'], $k['funded']['previous_deals'], $count),
                ...self::split($count($k['funded']['host']['deals']), $count($k['funded']['partners']['deals'])),
            ]),
            self::card('🤝', 'partners', [self::totals($k['partners'])]),
            self::card('👥', 'clients', [
                self::totals($k['clients']),
                ...self::split($count($k['clients']['host']), $count($k['clients']['partners'])),
            ]),
            self::card('🎯', 'kpi_leads', [
                self::changed($k['new_leads']['current'], $k['new_leads']['previous'], $count),
                ...self::split($count($k['new_leads']['host']), $count($k['new_leads']['partners'])),
            ]),
        ];

        return implode("\n\n", [self::title($summary['period'], $company), ...$cards]);
    }

    private static function title(array $period, string $company): string
    {
        $monthly = $period['key'] === OwnerSummaryPeriod::LAST_MONTH;
        $when = $monthly
            ? CarbonImmutable::createFromFormat('!Y-m-d', $period['from'])->format('F Y')
            : OwnerSummaryPeriod::rangeLabel($period['from'], $period['to']);
        $name = __('owner_summary.telegram.' . ($monthly ? 'monthly' : 'weekly'));

        return '📊 <b>' . self::e($company) . ' · ' . self::e($name) . "</b>\n🗓 " . self::e($when);
    }

    /** A card: its icon and label (the screen's), then its lines. */
    private static function card(string $icon, string $label, array $lines): string
    {
        return implode("\n", [$icon . ' <b>' . self::e(__("owner_summary.report.{$label}")) . '</b>', ...$lines]);
    }

    /** "<b>6</b> | 📉 -25% | <i>was 8</i>"; with nothing before, "🆕 new" or "➖ no change". */
    private static function changed(float|int $current, float|int $previous, callable $format): string
    {
        $delta = OwnerReportPresenter::delta($current, $previous);
        $value = '<b>' . self::e($format($current)) . '</b>';

        if ((float) $previous === 0.0) {
            $icon = (float) $current === 0.0 ? self::TREND_ICONS['flat'] : self::NEW_ICON;

            return $value . self::GAP . $icon . ' ' . self::e($delta['text']);
        }

        return $value . self::GAP . self::TREND_ICONS[$delta['tone']] . ' ' . self::e($delta['text'])
            . self::GAP . '<i>' . self::e(__('owner_summary.telegram.was', ['value' => $format($previous)])) . '</i>';
    }

    /** "<b>410</b> | 380 active | 21 new". */
    private static function totals(array $totals): string
    {
        return '<b>' . self::e(number_format($totals['total'])) . '</b>' . self::GAP . self::e(__('owner_summary.telegram.active_new', [
            'active' => number_format($totals['active']),
            'new' => number_format($totals['new']),
        ]));
    }

    /** ["├ FCH: 4", "└ Partners: 2"], under the line it splits. */
    private static function split(string $host, string $partners): array
    {
        return [
            '├ ' . self::e(__('owner_summary.report.by_host')) . ': ' . self::e($host),
            '└ ' . self::e(__('owner_summary.report.by_partners')) . ': ' . self::e($partners),
        ];
    }

    /** Telegram's HTML only needs &, < and > escaped (and quotes inside attributes). */
    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
