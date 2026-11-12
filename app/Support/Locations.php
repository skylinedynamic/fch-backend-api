<?php

namespace App\Support;

/**
 * Countries, states and cities.
 *
 * The one server-side accessor for config/locations.php. It replaces
 * Database\Seeders\LocationHelper, which carried a second, divergent copy of the
 * same data - the config has the "Unknown" entries, the trait never did - and
 * which lived under Database\Seeders despite being used by application services.
 */
class Locations
{
    /**
     * The country that stands in for "the applicant did not say".
     *
     * Its state code is deliberately not a real ISO code, so it matches no
     * lender state restriction.
     */
    public const UNKNOWN = 'Unknown';

    /**
     * Every country, in the config's own shape.
     *
     * @return array<int, array{name: string, code: string, currency: string, phone_code: string, flag: string, states: array}>
     */
    public static function all(): array
    {
        return array_values(config('locations', []));
    }

    /**
     * The same tree keyed by country code, and each country's states keyed by
     * state code.
     */
    public static function map(): array
    {
        $map = [];

        foreach (self::all() as $country) {
            $states = [];

            foreach ($country['states'] ?? [] as $state) {
                $key = $state['code'] ?? $state['name'] ?? null;

                if ($key !== null) {
                    $states[$key] = $state;
                }
            }

            $country['states'] = $states;
            $map[$country['code'] ?? $country['name']] = $country;
        }

        return $map;
    }

    /**
     * A country's states, or an empty array when the code is unknown.
     */
    public static function statesOf(string $countryCode): array
    {
        foreach (self::all() as $country) {
            if (strcasecmp($country['code'] ?? '', $countryCode) === 0) {
                return $country['states'] ?? [];
            }
        }

        return [];
    }

    /**
     * The country with this name (case and outer spaces ignored), or null.
     */
    public static function countryNamed(?string $name): ?array
    {
        $needle = strtolower(trim((string) $name));
        if ($needle === '') {
            return null;
        }

        foreach (self::all() as $country) {
            if (strtolower($country['name'] ?? '') === $needle) {
                return $country;
            }
        }

        return null;
    }

    /**
     * The state with this name in a country (case and outer spaces ignored), or null.
     */
    public static function stateNamed(?array $country, ?string $name): ?array
    {
        $needle = strtolower(trim((string) $name));
        if ($country === null || $needle === '') {
            return null;
        }

        foreach ($country['states'] ?? [] as $state) {
            if (strtolower($state['name'] ?? '') === $needle) {
                return $state;
            }
        }

        return null;
    }

    /**
     * The code for a state name - "Texas" becomes "TX".
     *
     * Matches on the code too, so a value that is already a code passes through
     * unchanged. Returns null when nothing matches, leaving the caller to decide
     * whether that is an error or simply a place we do not model.
     */
    public static function stateCode(?string $stateName): ?string
    {
        if (!$stateName) {
            return null;
        }

        $needle = strtolower(trim($stateName));

        foreach (self::all() as $country) {
            foreach ($country['states'] ?? [] as $state) {
                if (strtolower($state['name'] ?? '') === $needle
                    || strtolower($state['code'] ?? '') === $needle) {
                    return strtoupper($state['code'] ?? '');
                }
            }
        }

        return null;
    }

    /**
     * A random real place, for seeding.
     *
     * "Unknown" is excluded: it exists so an applicant can decline to answer, and
     * seeding it would put a non-place into demo data that reads as real.
     */
    public static function randomLocation(): array
    {
        $countries = array_values(array_filter(
            self::all(),
            fn ($country) => ($country['name'] ?? null) !== self::UNKNOWN
        ));

        $country = $countries[array_rand($countries)];

        $states = array_values(array_filter(
            $country['states'] ?? [],
            fn ($state) => ($state['name'] ?? null) !== self::UNKNOWN
        ));

        $state = $states[array_rand($states)];

        $cities = array_values(array_filter(
            $state['cities'] ?? [],
            fn ($city) => $city !== self::UNKNOWN
        ));

        return [
            'country' => $country['code'] ?? null,
            'country_name' => $country['name'] ?? null,
            'state_code' => $state['code'] ?? null,
            'state' => $state['name'] ?? null,
            'city' => $cities ? $cities[array_rand($cities)] : null,
        ];
    }
}
