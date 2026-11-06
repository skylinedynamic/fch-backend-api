<?php

namespace App\Observers\Audit;

use App\Models\Audit\FundingApplicationAuditEvent as Event;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Services\Audit\FundingApplicationAuditTrail;

/** A co-borrower added to, changed on or removed from a funding application. */
class FundingApplicationCoBorrowerAuditObserver
{
    public function __construct(private FundingApplicationAuditTrail $trail)
    {
    }

    public function created(FundingApplicationCoBorrower $coBorrower): void
    {
        $this->record($coBorrower, Event::EVENT_CO_BORROWER_ADDED, $this->trail->initialValues($coBorrower, ['funding_application_id']));
    }

    public function updated(FundingApplicationCoBorrower $coBorrower): void
    {
        $changes = $this->trail->changesOf($coBorrower, ['funding_application_id']);
        if ($changes !== []) {
            $this->record($coBorrower, Event::EVENT_CO_BORROWER_UPDATED, $changes);
        }
    }

    public function deleted(FundingApplicationCoBorrower $coBorrower): void
    {
        $this->record($coBorrower, Event::EVENT_CO_BORROWER_REMOVED);
    }

    private function record(FundingApplicationCoBorrower $coBorrower, string $event, ?array $changes = null): void
    {
        if (!$coBorrower->funding_application_id) {
            return;
        }

        $this->trail->record($coBorrower->funding_application_id, $event, $changes ?: null, array_filter([
            'co_borrower_id' => $coBorrower->id,
            'name' => trim(implode(' ', array_filter([$coBorrower->first_name, $coBorrower->last_name]))) ?: null,
        ], fn ($value) => $value !== null));
    }
}
