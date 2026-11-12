<?php

namespace Tests\Feature\Lender;

use App\Http\Requests\Api\V1\FCH\Admin\Lender\StoreLenderRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\UpdateLenderRequest;
use App\Http\Resources\FCH\Lender\LenderResource;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Add/Edit Lender (2026-11-11): a lender's Country, State, City and Address -
 * all optional - are saved and sent back with it.
 */
#[CoversClass(StoreLenderRequest::class)]
#[CoversClass(UpdateLenderRequest::class)]
#[CoversClass(LenderResource::class)]
#[CoversClass(Lender::class)]
class LenderAddressApiTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private const LENDERS = '/api/v1/admin/lenders';

    private const ADDRESS = [
        'country' => 'United States',
        'state' => 'Texas',
        'city' => 'Austin',
        'address' => '500 Congress Ave, Suite 210',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsUser($this->makeStaffUser($this->makeHostPartner()));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kabbage',
            'legal_name' => 'Kabbage Inc.',
            'product_categories' => [LenderProductCategory::create(['name' => 'Term '.uniqid(), 'status' => 'Active'])->id],
            'contact_name' => 'Lee Lender',
            'contact_title' => 'Partnerships',
            'contact_email' => 'lee@kabbage.example.org',
            'contact_phone' => '5552340100',
            'status' => Lender::STATUS_ACTIVE,
        ], $overrides);
    }

    #[Test]
    public function add_lender_saves_and_returns_the_address(): void
    {
        $created = $this->postJson(self::LENDERS, $this->payload(self::ADDRESS))->assertCreated();

        $created->assertJson(['data' => self::ADDRESS]);
        $this->assertDatabaseHas('lenders', ['id' => $created->json('data.id')] + self::ADDRESS);
        $this->getJson(self::LENDERS.'/'.$created->json('data.id'))->assertOk()->assertJson(['data' => self::ADDRESS]);
    }

    #[Test]
    public function every_address_field_is_optional(): void
    {
        $created = $this->postJson(self::LENDERS, $this->payload())->assertCreated();

        foreach (array_keys(self::ADDRESS) as $field) {
            $this->assertNull($created->json("data.{$field}"), $field);
        }

        $this->postJson(self::LENDERS, $this->payload(['country' => 'Canada']))->assertCreated()->assertJsonPath('data.state', null);
        $this->postJson(self::LENDERS, $this->payload(['country' => null, 'state' => '', 'city' => null, 'address' => '']))->assertCreated();
    }

    #[Test]
    public function edit_lender_changes_and_clears_the_address(): void
    {
        $id = $this->postJson(self::LENDERS, $this->payload(self::ADDRESS))->json('data.id');

        $this->putJson(self::LENDERS."/{$id}", $this->payload(['country' => 'Canada', 'state' => 'Ontario', 'city' => 'Toronto', 'address' => '1 King St W']))
            ->assertOk()
            ->assertJson(['data' => ['country' => 'Canada', 'state' => 'Ontario', 'city' => 'Toronto', 'address' => '1 King St W']]);

        $this->putJson(self::LENDERS."/{$id}", $this->payload(['country' => null, 'state' => null, 'city' => null, 'address' => null]))->assertOk();
        $this->assertDatabaseHas('lenders', ['id' => $id, 'country' => null, 'state' => null, 'city' => null, 'address' => null]);
    }

    #[Test]
    public function the_address_fields_have_length_limits(): void
    {
        $this->postJson(self::LENDERS, $this->payload([
            'country' => str_repeat('a', 256),
            'state' => str_repeat('b', 256),
            'city' => str_repeat('c', 256),
            'address' => str_repeat('d', 501),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['country', 'state', 'city', 'address']);

        $this->postJson(self::LENDERS, $this->payload(['address' => str_repeat('d', 500)]))->assertCreated();
    }

    // -- The place must be a real one, from the same list the form offers
    //    (common/locations): the state in the country, the city in the state.

    /**
     * Messages as translation key + values: the provider runs before the app boots.
     *
     * @return iterable<string, array{array<string, ?string>, string, string, array<string, string>}>
     */
    public static function wrongPlaces(): iterable
    {
        yield 'an unknown country' => [['country' => 'Atlantis'], 'country', 'lender.country_unknown', []];
        yield 'a state of another country' => [['country' => 'Canada', 'state' => 'Texas'], 'state', 'lender.state_not_in_country', ['country' => 'Canada']];
        yield 'a state with no country' => [['state' => 'Texas'], 'state', 'lender.state_needs_country', []];
        yield 'a city of another state' => [['country' => 'United States', 'state' => 'Ohio', 'city' => 'Austin'], 'city', 'lender.city_not_in_state', ['state' => 'Ohio']];
        yield 'a city with no state' => [['country' => 'United States', 'city' => 'Austin'], 'city', 'lender.city_needs_state', []];
        yield 'a city whose state is wrong' => [['country' => 'Canada', 'state' => 'Texas', 'city' => 'Austin'], 'city', 'lender.city_not_in_state', ['state' => 'Texas']];
    }

    #[Test]
    #[DataProvider('wrongPlaces')]
    public function add_lender_refuses_a_place_that_does_not_exist(array $place, string $field, string $key, array $values): void
    {
        $this->assertNotSame($key, __($key, $values), "{$key} has no message");

        $this->postJson(self::LENDERS, $this->payload($place))
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}", __($key, $values));

        $this->assertSame(0, Lender::count());
    }

    #[Test]
    #[DataProvider('wrongPlaces')]
    public function edit_lender_refuses_a_place_that_does_not_exist(array $place, string $field, string $key, array $values): void
    {
        $id = $this->postJson(self::LENDERS, $this->payload(self::ADDRESS))->json('data.id');

        $this->putJson(self::LENDERS."/{$id}", $this->payload($place))
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}", __($key, $values));

        $this->assertDatabaseHas('lenders', ['id' => $id] + self::ADDRESS);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function realPlaces(): iterable
    {
        yield 'country, state and city' => [['country' => 'Canada', 'state' => 'Ontario', 'city' => 'Toronto']];
        yield 'country and state' => [['country' => 'United States', 'state' => 'Ohio']];
        yield 'country only' => [['country' => 'Canada']];
        yield 'any case and spacing' => [['country' => ' united states ', 'state' => 'TEXAS', 'city' => 'austin']];
        yield 'an address with no place' => [['address' => '1 King St W']];
    }

    #[Test]
    #[DataProvider('realPlaces')]
    public function a_real_place_is_saved(array $place): void
    {
        $this->postJson(self::LENDERS, $this->payload($place))->assertCreated();

        $this->assertSame(1, Lender::count());
    }
}
