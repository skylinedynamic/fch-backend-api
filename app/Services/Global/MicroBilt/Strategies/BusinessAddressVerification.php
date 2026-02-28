<?php

namespace App\Services\Global\MicroBilt\Strategies;

use App\Services\Global\MicroBilt\MicroBiltService;
use App\Models\Borrower\Borrower;
use Database\Seeders\LocationHelper;

class BusinessAddressVerification implements VerificationStrategyInterface
{
    use LocationHelper;
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
            $this->getStateCode($borrower->business_state) ?? '',
            $borrower->business_postal_code ?? ''
        );
    }
}
