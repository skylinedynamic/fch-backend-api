<?php

namespace App\Services\Global\MicroBilt\Strategies;

use App\Services\Global\MicroBilt\MicroBiltService;
use App\Models\Borrower\Borrower;

class EinVerification implements VerificationStrategyInterface
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
        return $service->einVerification(
            // 'BANK OF AMERICA CORP',
            // '20262026'
            $borrower->business_name ?? '',
            preg_replace('/[^0-9]/', '', $borrower->business_ein ?? '')
        );
    }
}
