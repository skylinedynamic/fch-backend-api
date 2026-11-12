<?php

namespace App\Rules;

use App\Support\Locations;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A country, state or city that is a real place in the list the forms offer
 * (common/locations, config/locations.php): the state in the given country,
 * the city in the given state. Names are matched ignoring case and outer
 * spaces. An empty value is left to `nullable`.
 */
class KnownLocation implements ValidationRule
{
    public const COUNTRY = 'country';

    public const STATE = 'state';

    public const CITY = 'city';

    /**
     * @param  self::COUNTRY|self::STATE|self::CITY  $level
     */
    public function __construct(
        private string $level,
        private ?string $country = null,
        private ?string $state = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        $country = Locations::countryNamed($this->country);

        if ($this->level === self::COUNTRY) {
            if (Locations::countryNamed($value) === null) {
                $fail(__('lender.country_unknown'));
            }

            return;
        }

        if ($this->level === self::STATE) {
            if (trim((string) $this->country) === '') {
                $fail(__('lender.state_needs_country'));
            } elseif ($country !== null && Locations::stateNamed($country, $value) === null) {
                // An unknown country is reported on the country itself.
                $fail(__('lender.state_not_in_country', ['country' => $country['name']]));
            }

            return;
        }

        if (trim((string) $this->state) === '') {
            $fail(__('lender.city_needs_state'));

            return;
        }

        $state = Locations::stateNamed($country, $this->state);
        $cities = array_map(fn ($city) => strtolower((string) $city), $state['cities'] ?? []);

        if (! in_array(strtolower($value), $cities, true)) {
            $fail(__('lender.city_not_in_state', ['state' => trim((string) $this->state)]));
        }
    }
}
