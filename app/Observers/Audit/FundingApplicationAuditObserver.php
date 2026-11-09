<?php

namespace App\Observers\Audit;

use App\Models\Audit\FundingApplicationAuditEvent as Event;
use App\Models\Borrower\FundingApplication;
use App\Services\Audit\FundingApplicationAuditTrail;

/**
 * Records a funding application's own changes in its audit trail: created,
 * each changed field, a status change (with its note), signing and a
 * signature cleared by an edit, removal and restoring. Only records - it
 * never changes what is saved.
 */
class FundingApplicationAuditObserver
{
    private const STATUS_FIELDS = ['status', 'status_note'];

    public function __construct(private FundingApplicationAuditTrail $trail)
    {
    }

    public function created(FundingApplication $application): void
    {
        $this->trail->record($application, Event::EVENT_CREATED, null, array_filter([
            'status' => $application->status,
            'is_partner_prepared' => (bool) $application->is_partner_prepared,
        ], fn ($value) => $value !== null));
    }

    public function updated(FundingApplication $application): void
    {
        $statusChanged = $application->wasChanged('status');

        if ($statusChanged) {
            $this->trail->record(
                $application,
                Event::EVENT_STATUS_CHANGED,
                array_values(array_filter(
                    $this->trail->changesOf($application),
                    fn ($change) => in_array($change['field'], self::STATUS_FIELDS, true)
                ))
            );
        }

        if ($application->wasChanged('signature_data')) {
            $this->signature($application);
        }

        $changes = $this->trail->changesOf($application, $statusChanged ? self::STATUS_FIELDS : []);
        if ($changes !== []) {
            $this->trail->record($application, Event::EVENT_UPDATED, $changes);
        }
    }

    public function deleted(FundingApplication $application): void
    {
        $this->trail->record($application, Event::EVENT_DELETED, null, [
            'permanently' => $application->isForceDeleting(),
        ]);
    }

    public function restored(FundingApplication $application): void
    {
        $this->trail->record($application, Event::EVENT_RESTORED);
    }

    /** Signed, or a signature cleared - the signature data itself stays out of the trail. */
    private function signature(FundingApplication $application): void
    {
        $before = $application->getOriginal('signature_data');
        $after = $application->signature_data;

        if (!empty($after) && empty($before)) {
            $this->trail->record($application, Event::EVENT_SIGNED, null, array_filter([
                'signed_at' => $after['signed_at'] ?? null,
                'application_version' => isset($after['application_version']) ? (string) $after['application_version'] : null,
            ], fn ($value) => $value !== null));
        } elseif (empty($after) && !empty($before)) {
            $this->trail->record($application, Event::EVENT_SIGNATURE_CLEARED);
        }
    }
}
