<?php

namespace App\Services\FCH\Common\OwnerSummary;

use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;

/** The weekly report as a PDF laid out like the Owner Summary screen, under the host's branding. */
class OwnerWeeklyReportPdf
{
    public const VIEW = 'pdf.owner-weekly-report';

    // Portrait: cards are kept whole, and landscape's short pages left them half empty.
    private const PAPER = 'letter';
    private const ORIENTATION = 'portrait';

    /** @param array $report OwnerSummaryService::summary() */
    public function render(array $report): string
    {
        return Pdf::loadView(self::VIEW, $this->data($report))
            ->setPaper(self::PAPER, self::ORIENTATION)
            ->output();
    }

    /** @param array $report OwnerSummaryService::summary() */
    public function data(array $report): array
    {
        $branding = FundingApplicationDocumentService::getHostBranding();

        return array_merge($branding, [
            'summary' => new OwnerReportPresenter($report, $branding['companyName']),
            'title' => __('owner_summary.report.title'),
        ]);
    }
}
