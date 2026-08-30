<?php

namespace App\Services\Global\MicroBilt;

use App\Services\Global\MicroBilt\Strategies\VerificationStrategyInterface;
use App\Services\Global\MicroBilt\Strategies\EinVerification;
use App\Services\Global\MicroBilt\Strategies\BusinessAddressVerification;
use App\Services\Global\MicroBilt\Strategies\BusinessPhoneVerification;
use App\Services\Global\MicroBilt\Strategies\BusinessIdentityVerification;
use App\Services\Global\MicroBilt\Strategies\ActiveLegalEntityVerification;

class MicroBiltVerificationFactory
{
    /**
     * Create a verification strategy based on the API name.
     *
     * @param string $apiName
     * @return VerificationStrategyInterface|null
     */
    public static function make(string $apiName): ?VerificationStrategyInterface
    {
        return match ($apiName) {
            'EIN Verification Api' => new EinVerification(),
            'Business Address Verification Api' => new BusinessAddressVerification(),
            'Business Phone Verification Api' => new BusinessPhoneVerification(),
            'Business Identity Verification Api' => new BusinessIdentityVerification(),
            'Active Legal Entity Verification Api' => new ActiveLegalEntityVerification(),
            default => null,
        };
    }
}
