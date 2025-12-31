<?php

namespace App\Services\Global\MicroBilt\Strategies;

use App\Services\Global\MicroBilt\MicroBiltService;
use App\Models\Borrower\Borrower;

class PhoneVerificationByAddress implements VerificationStrategyInterface
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
        return $service->verifyPhoneByAddress(
            $borrower->business_phone ?? '',
            $borrower->business_address ?? '',
            $borrower->business_city ?? '',
            $borrower->business_state ?? '',
            $borrower->business_postal_code ?? ''
        );
    }
}
