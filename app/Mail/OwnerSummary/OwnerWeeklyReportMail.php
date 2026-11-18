<?php

namespace App\Mail\OwnerSummary;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Services\FCH\Common\OwnerSummary\OwnerReportPresenter;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OwnerWeeklyReportMail extends Mailable implements ShouldQueue
{
    use Queueable, MailableBranding, MailConfig;

    /** Base64: the queue payload is JSON, and raw PDF bytes are not valid UTF-8 (see InvoiceMail). */
    protected string $pdfBase64;

    /** @param array $report OwnerSummaryService::summary() */
    public function __construct(public array $report, string $pdf, int $hostId)
    {
        $this->pdfBase64 = base64_encode($pdf);
        $this->mailPartnerId = $hostId;
    }

    public function pdf(): string
    {
        return base64_decode($this->pdfBase64);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('owner_summary.report.subject', ['period' => $this->periodLabel()]));
    }

    public function content(): Content
    {
        $branding = $this->getBrandingData();

        return new Content(
            view: 'emails.owner_summary.weekly_report',
            with: [
                'siteName' => $branding['siteName'],
                'logoUrl' => $branding['logoUrl'],
                'summary' => new OwnerReportPresenter(
                    $this->report,
                    $branding['siteName'],
                    concatUrl(getPartnerDomain($this->mailPartnerId), '', config('app.frontend_partner_url'))
                ),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $period = $this->report['period'];

        return [
            Attachment::fromData(fn () => $this->pdf(), "Owner-report-{$period['from']}-to-{$period['to']}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    private function periodLabel(): string
    {
        return OwnerSummaryPeriod::rangeLabel($this->report['period']['from'], $this->report['period']['to']);
    }
}
