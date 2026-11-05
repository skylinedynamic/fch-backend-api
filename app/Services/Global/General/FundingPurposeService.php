<?php

namespace App\Services\Global\General;

/**
 * The funding application's Purpose of Funds choices - the one list, in
 * config/funding_application.php (`options.purposes_of_funds`). The embed form
 * reads it from the config; the portals through GET /common/funding-purposes.
 */
class FundingPurposeService
{
    /** @return array<int, string> */
    public function purposes(): array
    {
        return array_values(config('funding_application.options.purposes_of_funds', []));
    }
}
