<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Lender;

use App\Rules\KnownLocation;

/**
 * Add/Edit Lender's location: all optional. Country, state and city are names
 * from the list the form offers (common/locations) - the state in the country,
 * the city in the state; the address is free text.
 */
trait ValidatesLenderLocation
{
    /** @return array<string, array<int, mixed>> */
    protected function locationRules(): array
    {
        $country = $this->input('country');
        $state = $this->input('state');

        return [
            'country' => ['nullable', 'string', 'max:255', new KnownLocation(KnownLocation::COUNTRY)],
            'state' => ['nullable', 'string', 'max:255', new KnownLocation(KnownLocation::STATE, is_string($country) ? $country : null)],
            'city' => ['nullable', 'string', 'max:255', new KnownLocation(KnownLocation::CITY, is_string($country) ? $country : null, is_string($state) ? $state : null)],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
