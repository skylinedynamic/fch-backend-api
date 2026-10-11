<?php

namespace App\Services\Global\MicroBilt\Strategies;

use App\Support\Locations;
use App\Services\Global\MicroBilt\MicroBiltService;
use App\Models\Borrower\Borrower;

class BusinessIdentityVerification implements VerificationStrategyInterface
{

    /**
     * Execute the verification strategy.
     *
     * @param MicroBiltService $service
     * @param Borrower $borrower
     * @return array
     */
    public function execute(MicroBiltService $service, Borrower $borrower): array
    {
        return $service->businessIdentityVerification(
            $borrower->business_name ?? '',
            preg_replace('/[^0-9]/', '', $borrower->business_ein ?? ''),
            $borrower->business_city ?? '',
            Locations::stateCode($borrower->business_state ?? '') ?? ''
        );
    }
}
