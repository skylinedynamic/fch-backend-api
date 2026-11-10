<?php

namespace Tests\Feature\Profile;

use App\Http\Controllers\Api\V1\Borrower\Profile\PersonalInfoController;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use App\Rules\DateOfBirth;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The phone number and ZIP code on the borrower's own Personal Info.
 *
 * R2 BUG-017: a 9-digit phone "(555) 555-012" was saved (QA build of 21 Oct).
 * The shared UsPhoneNumber / UsPostalCode rules reached stage on 24 Oct; this
 * pins them on this screen. The 6-digit ZIP was cut to 5 by the portal's input
 * mask, which is intended - the API refuses one sent as is.
 */
#[CoversClass(PersonalInfoController::class)]
class BorrowerPersonalInfoFormatsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->makeBorrowerUser($this->makeHostPartner(), ['phone_number' => '5552345678', 'postal_code' => '78701']);
        $this->actingAsUser($this->me);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Craig',
            'last_name' => 'Rice',
            'email' => $this->me->email,
            'phone_number' => '5552345678',
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
    public function a_nine_digit_phone_number_is_refused(): void
    {
        $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload(['phone_number' => '(555) 555-012']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number' => __('embed_form.phone_invalid')]);

        $this->assertSame('5552345678', Borrower::find($this->me->entity_id)->phone_number);
    }

    #[Test]
    public function a_zip_code_must_be_exactly_five_digits(): void
    {
        foreach (['123456', '1234'] as $zip) {
            $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload(['zip_code' => $zip]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['zip_code' => __('common.postal_code_invalid')]);
        }

        $this->assertSame('78701', Borrower::find($this->me->entity_id)->postal_code);
    }

    #[Test]
    public function a_ten_digit_phone_and_five_digit_zip_save(): void
    {
        $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload(['phone_number' => '(555) 555-0123', 'zip_code' => '12345']))
            ->assertOk();

        $this->assertSame('12345', Borrower::find($this->me->entity_id)->postal_code);
    }

    /**
     * Defect: the profile's date of birth was only `before:today`, so a
     * borrower could save a date that made them a child.
     */
    #[Test]
    public function a_date_of_birth_under_the_minimum_age_is_refused(): void
    {
        $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload([
            'date_of_birth' => DateOfBirth::latestAllowedDate()->addDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'date_of_birth' => 'You must be at least 18 years old to continue.',
        ]);

        foreach (['2000-02-30' => 'common.date_of_birth_invalid', now()->addDay()->toDateString() => 'common.date_of_birth_future'] as $dob => $message) {
            $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload(['date_of_birth' => $dob]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['date_of_birth' => __($message)]);
        }
    }

    #[Test]
    public function someone_turning_eighteen_today_can_save_their_profile(): void
    {
        $turning18 = DateOfBirth::latestAllowedDate()->toDateString();

        $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload(['date_of_birth' => $turning18]))
            ->assertOk();

        $this->assertSame($turning18, toDateOnly(Borrower::find($this->me->entity_id)->date_of_birth));
    }
}
