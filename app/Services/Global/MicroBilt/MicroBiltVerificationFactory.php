<?php

namespace App\Services\Global\MicroBilt;

use App\Services\Global\MicroBilt\Strategies\VerificationStrategyInterface;
use App\Services\Global\MicroBilt\Strategies\PhoneVerificationByAddress;
use App\Services\Global\MicroBilt\Strategies\PhoneVerificationByName;

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
            'Phone Verification By Address' => new PhoneVerificationByAddress(),
            'Phone Verification By Name' => new PhoneVerificationByName(),
            default => null,
        };
    }
}
