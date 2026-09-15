<?php

namespace App\Services\Global\MicroBilt\Strategies;

use App\Support\Locations;
use App\Services\Global\MicroBilt\MicroBiltService;
use App\Models\Borrower\Borrower;

class BusinessAddressVerification implements VerificationStrategyInterface
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
        return $service->businessAddressVerification(
            $borrower->business_name ?? '',
            $borrower->business_address ?? '',
            $borrower->business_city ?? '',
            Locations::stateCode($borrower->business_state) ?? '',
            $borrower->business_postal_code ?? ''
        );
    }
}
