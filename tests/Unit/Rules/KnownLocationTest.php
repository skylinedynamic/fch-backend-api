<?php

namespace Tests\Unit\Rules;

use App\Rules\KnownLocation;
use App\Support\Locations;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A country, state or city from the list the forms offer (config/locations.php,
 * served as common/locations): the state in its country, the city in its state.
 */
#[CoversClass(KnownLocation::class)]
#[CoversClass(Locations::class)]
class KnownLocationTest extends TestCase
{
    private function passes(KnownLocation $rule, ?string $value): bool
    {
        return Validator::make(['value' => $value], ['value' => [$rule]])->passes();
    }

    #[Test]
    public function a_country_is_found_by_name_ignoring_case_and_spaces(): void
    {
        $this->assertSame('United States', Locations::countryNamed(' united STATES ')['name']);
        $this->assertNull(Locations::countryNamed('Atlantis'));
        $this->assertNull(Locations::countryNamed(''));
        $this->assertNull(Locations::countryNamed(null));
    }

    #[Test]
    public function a_state_is_found_in_its_country_only(): void
    {
        $usa = Locations::countryNamed('United States');

        $this->assertSame('Texas', Locations::stateNamed($usa, 'texas')['name']);
        $this->assertNull(Locations::stateNamed($usa, 'Ontario'));
        $this->assertNull(Locations::stateNamed(null, 'Texas'));
        $this->assertNull(Locations::stateNamed($usa, ' '));
    }

    /** @return iterable<string, array{KnownLocation, ?string, bool}> */
    public static function cases(): iterable
    {
        yield 'a real country' => [new KnownLocation(KnownLocation::COUNTRY), 'Canada', true];
        yield 'an unknown country' => [new KnownLocation(KnownLocation::COUNTRY), 'Atlantis', false];
        yield 'a state in its country' => [new KnownLocation(KnownLocation::STATE, 'Canada'), 'Ontario', true];
        yield 'a state of another country' => [new KnownLocation(KnownLocation::STATE, 'Canada'), 'Texas', false];
        yield 'a state with no country' => [new KnownLocation(KnownLocation::STATE), 'Texas', false];
        yield 'a state of an unknown country (reported on the country)' => [new KnownLocation(KnownLocation::STATE, 'Atlantis'), 'Texas', true];
        yield 'a city in its state' => [new KnownLocation(KnownLocation::CITY, 'United States', 'Texas'), 'AUSTIN', true];
        yield 'a city of another state' => [new KnownLocation(KnownLocation::CITY, 'United States', 'Ohio'), 'Austin', false];
        yield 'a city with no state' => [new KnownLocation(KnownLocation::CITY, 'United States'), 'Austin', false];
        yield 'nothing given (left to nullable)' => [new KnownLocation(KnownLocation::CITY), '  ', true];
    }

    #[Test]
    #[DataProvider('cases')]
    public function only_a_real_place_passes(KnownLocation $rule, ?string $value, bool $passes): void
    {
        $this->assertSame($passes, $this->passes($rule, $value));
    }
}
