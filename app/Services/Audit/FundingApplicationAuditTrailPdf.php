<?php

namespace App\Services\Audit;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Models\Borrower\FundingApplication;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use App\Support\Audit\AuditLabels;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A funding application's audit trail as a PDF: the same rows as the CSV
 * export, under the host's branding like the application PDF itself.
 */
class FundingApplicationAuditTrailPdf
{
    public const VIEW = 'pdf.audit-trail';

    public function __construct(private FundingApplicationAuditTrail $trail)
    {
    }

    /** @param iterable<FundingApplicationAuditEvent> $events */
    public function render(int $applicationId, iterable $events): string
    {
        return Pdf::loadView(self::VIEW, $this->data($applicationId, $events))
            ->setPaper(config('audit.pdf_paper'), config('audit.pdf_orientation'))
            ->output();
    }

    /** @param iterable<FundingApplicationAuditEvent> $events */
    public function data(int $applicationId, iterable $events): array
    {
        $application = FundingApplication::withTrashed()->find($applicationId);

        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'id' => $event->id,
                'occurred_at' => $event->occurred_at?->utc()->format(config('audit.date_format')),
                'event' => AuditLabels::event($event->event),
                'actor' => $event->actor_name ?: __('audit.system'),
                'actor_type' => $event->actor_type,
                'ip_address' => $event->ip_address,
                'changes' => collect($event->changes ?? [])->map(fn ($c) => [
                    'label' => AuditLabels::field($c['field']),
                    'old' => $c['old'] ?? __('audit.empty_value'),
                    'new' => $c['new'] ?? __('audit.empty_value'),
                ])->all(),
                'details' => AuditLabels::context($event->context),
                'file_hash' => $event->file_hash,
                'legacy' => (bool) $event->is_legacy,
            ];
        }

        return array_merge(
            $application ? FundingApplicationDocumentService::getBrandingData($application) : [],
            [
                'title' => __('audit.pdf.title'),
                'columns' => __('audit.csv'),
                'rows' => $rows,
                'integrity' => $this->trail->verify($applicationId),
            ]
        );
    }
}
