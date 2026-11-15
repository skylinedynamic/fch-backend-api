<?php

namespace App\Services\FCH\Common\OwnerSummary;

use App\Models\User\User;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * Business Insights › Export PDF. Two reports of the same summary the screen
 * shows, under the host's branding:
 *  - summary: one short page - the figures at a glance and net earnings by
 *    stream, for a quick read or to pass on;
 *  - details: a cover page with its contents, then every section of the screen in
 *    full, every stalled deal included.
 *
 * Both read OwnerReportPresenter, so they say exactly what the screen and the
 * weekly email say, in the period's own words ("this month", not "last week").
 */
class OwnerSummaryExportPdf
{
    public const TYPE_SUMMARY = 'summary';
    public const TYPE_DETAILS = 'details';
    public const TYPES = [self::TYPE_SUMMARY, self::TYPE_DETAILS];

    public const VIEWS = [
        self::TYPE_SUMMARY => 'pdf.owner-summary-export-summary',
        self::TYPE_DETAILS => 'pdf.owner-summary-export-details',
    ];

    /** The details report lists every stalled deal, up to this many (a book that size is its own problem). */
    public const DETAILS_STALLED_LIMIT = 200;

    /** The document logo's printed height on every page, and the widest it may run (px). */
    public const LOGO_HEIGHT = 40;
    public const LOGO_MAX_WIDTH = 240;

    private const PAPER = 'letter';
    private const ORIENTATION = 'portrait';

    /** "Page 2 of 6", bottom right in the running footer (points; letter is 612 × 792). */
    private const PAGE_NUMBER_RIGHT = 29;
    private const PAGE_NUMBER_FROM_BOTTOM = 26;
    private const PAGE_NUMBER_SIZE = 7.5;
    private const PAGE_NUMBER_COLOR = [0.29, 0.36, 0.42];

    /** The layout's @page top and bottom margins (96px and 62px), in points - where the running header and footer sit. */
    private const MARGIN_TOP_PT = 72;
    private const MARGIN_BOTTOM_PT = 52;

    public function __construct(private readonly OwnerSummaryService $service)
    {
    }

    /** The summary behind a report: the screen's, with every stalled deal for the details one. */
    public function report(User $owner, string $type, OwnerSummaryPeriod $period, string $environment): array
    {
        $stalledLimit = $type === self::TYPE_DETAILS ? self::DETAILS_STALLED_LIMIT : OwnerSummaryService::STALLED_LIST_LIMIT;

        return $this->service->summary($owner, $period, $environment, $stalledLimit);
    }

    /** @param array $report OwnerSummaryService::summary() */
    public function render(string $type, array $report): string
    {
        return $this->dompdf($type, $report)->output();
    }

    /** How many pages a report runs to - the summary report is meant to be one. */
    public function pageCount(string $type, array $report): int
    {
        return $this->dompdf($type, $report)->getCanvas()->get_page_count();
    }

    private function dompdf(string $type, array $report): \Dompdf\Dompdf
    {
        $dompdf = Pdf::loadView(self::VIEWS[$type], $this->data($type, $report))
            ->setPaper(self::PAPER, self::ORIENTATION)
            ->getDomPDF();
        $dompdf->render();

        // dompdf's CSS has no total page count, so "Page 2 of 6" goes on the
        // canvas - on every page but the details report's cover. dompdf also
        // repeats fixed elements on every page, so the cover's running header
        // and footer are painted over: they sit in the page margins, which the
        // cover's own content never reaches.
        $firstNumbered = $type === self::TYPE_DETAILS ? 2 : 1;
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $dompdf->getCanvas()->page_script(function (int $page, int $pages, \Dompdf\Canvas $canvas, \Dompdf\FontMetrics $metrics) use ($firstNumbered, $font) {
            if ($page < $firstNumbered) {
                $canvas->filled_rectangle(0, 0, $canvas->get_width(), self::MARGIN_TOP_PT, [1, 1, 1]);
                $canvas->filled_rectangle(0, $canvas->get_height() - self::MARGIN_BOTTOM_PT, $canvas->get_width(), self::MARGIN_BOTTOM_PT, [1, 1, 1]);

                return;
            }
            $text = __('owner_summary.export.page') . " {$page} " . __('owner_summary.export.of') . " {$pages}";
            $canvas->text(
                $canvas->get_width() - self::PAGE_NUMBER_RIGHT - $metrics->getTextWidth($text, $font, self::PAGE_NUMBER_SIZE),
                $canvas->get_height() - self::PAGE_NUMBER_FROM_BOTTOM,
                $text,
                $font,
                self::PAGE_NUMBER_SIZE,
                self::PAGE_NUMBER_COLOR
            );
        });

        return $dompdf;
    }

    /** @param array $report OwnerSummaryService::summary() */
    public function data(string $type, array $report): array
    {
        $branding = FundingApplicationDocumentService::getHostBranding();
        $period = $report['period'];
        $periodKey = in_array($period['key'] ?? null, OwnerSummaryPeriod::KEYS, true) ? $period['key'] : OwnerSummaryPeriod::CUSTOM;
        $generatedAt = CarbonImmutable::now($period['timezone']);

        return array_merge($branding, [
            'summary' => new OwnerReportPresenter($report, $branding['companyName']),
            'type' => $type,
            'title' => __('owner_summary.export.title'),
            'typeLabel' => __("owner_summary.export.types.{$type}"),
            'periodName' => __("owner_summary.report.period_columns.{$periodKey}"),
            'environmentLabel' => __('owner_summary.export_environments.' . ($report['environment'] ?? OwnerSummaryService::ENVIRONMENT_PRODUCTION)),
            'generatedOn' => $generatedAt->format('j M Y, g:i A'),
            'stalledCount' => (int) ($report['attention']['stalled_deals'] ?? 0),
            ...self::logoSize($branding['logoWidth'] ?? 0, $branding['logoHeight'] ?? 0),
        ]);
    }

    /**
     * The logo's printed size: LOGO_HEIGHT tall in its own proportions, or
     * shorter for a very wide logo so it keeps to LOGO_MAX_WIDTH.
     *
     * @return array{logoPrintWidth: int, logoPrintHeight: int}
     */
    private static function logoSize(int|float $width, int|float $height): array
    {
        if ($width <= 0 || $height <= 0) {
            return ['logoPrintWidth' => self::LOGO_MAX_WIDTH / 2, 'logoPrintHeight' => self::LOGO_HEIGHT];
        }
        $printWidth = (int) round(self::LOGO_HEIGHT * $width / $height);

        return $printWidth <= self::LOGO_MAX_WIDTH
            ? ['logoPrintWidth' => $printWidth, 'logoPrintHeight' => self::LOGO_HEIGHT]
            : ['logoPrintWidth' => self::LOGO_MAX_WIDTH, 'logoPrintHeight' => (int) round(self::LOGO_MAX_WIDTH * $height / $width)];
    }

    /** business-insights-summary-2026-11-01-to-2026-11-15.pdf */
    public function filename(string $type, array $report): string
    {
        return "business-insights-{$type}-{$report['period']['from']}-to-{$report['period']['to']}.pdf";
    }
}
