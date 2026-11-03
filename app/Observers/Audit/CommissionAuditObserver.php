<?php

namespace App\Observers\Audit;

use App\Models\Audit\FundingApplicationAuditEvent as Event;
use App\Models\Commission\Commission;
use App\Services\Audit\FundingApplicationAuditTrail;

/** The commission set or changed on a funding application. */
class CommissionAuditObserver
{
    private const IGNORED = ['borrower_id', 'partner_id', 'funding_application_id'];

    public function __construct(private FundingApplicationAuditTrail $trail)
    {
    }

    public function created(Commission $commission): void
    {
        $this->record($commission, $this->trail->initialValues($commission, self::IGNORED));
    }

    public function updated(Commission $commission): void
    {
        $this->record($commission, $this->trail->changesOf($commission, self::IGNORED));
    }

    private function record(Commission $commission, array $changes): void
    {
        if ($commission->funding_application_id && $changes !== []) {
            $this->trail->record($commission->funding_application_id, Event::EVENT_COMMISSION_CHANGED, $changes, [
                'commission_id' => $commission->id,
            ]);
        }
    }
}
