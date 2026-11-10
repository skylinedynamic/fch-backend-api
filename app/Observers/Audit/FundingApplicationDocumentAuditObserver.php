<?php

namespace App\Observers\Audit;

use App\Models\Audit\FundingApplicationAuditEvent as Event;
use App\Models\Borrower\FundingApplicationDocument;
use App\Services\Audit\FundingApplicationAuditTrail;

/**
 * A document added to or removed from a funding application, recorded by its
 * name, type, size and SHA-256. No copy of the file is kept: the hash says
 * which file it was.
 */
class FundingApplicationDocumentAuditObserver
{
    public function __construct(private FundingApplicationAuditTrail $trail)
    {
    }

    public function created(FundingApplicationDocument $document): void
    {
        if (!$document->funding_application_id) {
            return;
        }

        $this->trail->record(
            $document->funding_application_id,
            Event::EVENT_DOCUMENT_ADDED,
            null,
            $this->context($document),
            $this->trail->fingerprint($document->disk, $document->path)
        );
    }

    public function deleted(FundingApplicationDocument $document): void
    {
        if (!$document->funding_application_id) {
            return;
        }

        // The services delete the file before the record, so the hash comes
        // from when it was added; else from the file if it is still there.
        $hash = Event::where('funding_application_id', $document->funding_application_id)
            ->where('event', Event::EVENT_DOCUMENT_ADDED)
            ->whereNotNull('file_hash')
            ->where('context->document_id', $document->id)
            ->latest('id')
            ->value('file_hash')
            ?? $this->trail->fingerprint($document->disk, $document->path);

        $this->trail->record(
            $document->funding_application_id,
            Event::EVENT_DOCUMENT_REMOVED,
            null,
            $this->context($document) + ['permanently' => $document->isForceDeleting()],
            $hash
        );
    }

    private function context(FundingApplicationDocument $document): array
    {
        return array_filter([
            'document_id' => $document->id,
            'name' => $document->original_name ?: $document->name,
            'document_type_id' => $document->borrower_doc_type_id,
            'mime' => $document->mime,
            'size' => $document->size !== null ? (string) $document->size : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
