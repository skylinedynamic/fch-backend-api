<?php

namespace Tests\Feature\Profile;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A borrower's middle name, through the profile screen that owns it.
 *
 * `borrowers.middle_name` has existed since 2026_09_15, and the funding
 * application has collected it all along - but the borrower's own profile never
 * did. The column was there, the form was not, and neither the resource nor the
 * form request mentioned the field, so there was no way for a borrower to see or
 * correct it.
 *
 * The cases worth pinning are the empty ones. A middle name is optional in a way
 * the names either side of it are not: requiring one would lock anyone without
 * one out of saving their own profile, and letting a blank one through to the
 * display name would leave a double space in the middle of it.
 */
class BorrowerMiddleNameTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Craig',
            'middle_name' => 'Alan',
            'last_name' => 'Rice',
            'email' => 'craig@example.test',
        ]);

        $this->user = User::create([
            'name' => 'Craig Alan Rice',
            'email' => 'craig@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            // BorrowerMiddleware gates the whole route group on this, not on
            // entity_type - without it every request is a 403.
            'user_type' => User::BORROWER,
            'partner_id' => $partner->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]);

        Passport::actingAs($this->user);
    }

    /** A complete, valid profile payload, with the middle name overridable. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Craig',
            'middle_name' => 'Alan',
            'last_name' => 'Rice',
            'email' => 'craig@example.test',
            'phone_number' => '5551234567',
            'country' => 'United States',
            'country_code' => '+1',
            'state' => 'Texas',
            'city' => 'Austin',
            'zip_code' => '78701',
            'home_address' => '100 Congress Ave',
            'ssn' => '123-45-6789',
            'date_of_birth' => '1985-04-12',
        ], $overrides);
    }

    #[Test]
    public function the_profile_returns_the_stored_middle_name(): void
    {
        $response = $this->getJson('/api/v1/borrower/profile/personal-info');

        $response->assertOk();
        $this->assertSame('Alan', $response->json('data.middle_name'));
    }

    #[Test]
    public function a_borrower_can_save_their_middle_name(): void
    {
        $response = $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload([
            'middle_name' => 'Alexander',
        ]));

        $response->assertOk();
        $this->assertSame('Alexander', $this->borrower->fresh()->middle_name);
    }

    /**
     * The field has to be addable, not just editable - a borrower who has never
     * had one recorded is the common case.
     */
    #[Test]
    public function a_borrower_with_no_middle_name_can_add_one(): void
    {
        $this->borrower->update(['middle_name' => null]);

        $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload([
            'middle_name' => 'Alan',
        ]))->assertOk();

        $this->assertSame('Alan', $this->borrower->fresh()->middle_name);
    }

    /**
     * And removable. Clearing the box must clear the column, not be ignored as
     * "nothing supplied".
     */
    #[Test]
    public function clearing_the_field_clears_the_stored_middle_name(): void
    {
        $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload([
            'middle_name' => '',
        ]))->assertOk();

        $this->assertEmpty($this->borrower->fresh()->middle_name);
    }

    /**
     * Omitting it entirely is valid: it is the one name field that is optional,
     * and a borrower without one must still be able to save the rest.
     */
    #[Test]
    public function the_profile_saves_when_no_middle_name_is_supplied(): void
    {
        $payload = $this->payload();
        unset($payload['middle_name']);

        $this->postJson('/api/v1/borrower/profile/personal-info', $payload)->assertOk();

        $this->assertSame('Craig', $this->borrower->fresh()->first_name);
    }

    #[Test]
    public function an_over_long_middle_name_is_rejected(): void
    {
        $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload([
            'middle_name' => str_repeat('a', 256),
        ]))->assertStatus(422);
    }

    /**
     * The display name carries it too - it was built by concatenating first and
     * last, so a middle name was silently dropped from every place that name is
     * shown.
     */
    #[Test]
    public function the_display_name_includes_the_middle_name(): void
    {
        $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload([
            'middle_name' => 'Alexander',
        ]))->assertOk();

        $this->assertSame('Craig Alexander Rice', $this->user->fresh()->name);
    }

    /**
     * Without a middle name that same concatenation used to leave two spaces in
     * the middle of the name.
     */
    #[Test]
    public function the_display_name_has_no_gap_when_there_is_no_middle_name(): void
    {
        $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload([
            'middle_name' => '',
        ]))->assertOk();

        $this->assertSame('Craig Rice', $this->user->fresh()->name);
    }
}
