<?php

namespace Tests\Feature\Auth;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerSignupId;
use App\Models\CreditBuilder\Tier;
use App\Models\User\User;
use App\Services\Borrower\Auth\BorrowerAuthService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Integration-level (real Eloquent, no HTTP) coverage of
 * BorrowerAuthService::registerBorrower(). BorrowerAuthService::login() is
 * intentionally NOT tested — it is dead code; the real borrower login path
 * goes through AuthService::login() instead (see AuthServiceTest).
 */
class BorrowerAuthServiceRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // registerBorrower() looks up the lowest-order Tier and assigns its id
        // to the new Borrower's current_tier — a Tier row must exist first.
        Tier::factory()->create(['order' => 1]);
    }

    private function registrationData(BorrowerSignupId $signup, array $overrides = []): array
    {
        return array_merge([
            'signup_id' => $signup->signup_id,
            'first_name' => 'Jamie',
            'last_name' => 'Rivera',
            'email' => 'jamie.rivera@example.com',
            'phone_number' => '5551234567',
            'state' => 'AZ',
            'country' => 'US',
            'country_code' => 'US',
            'password' => 'Password123!',
        ], $overrides);
    }

    public function test_registers_a_new_borrower_and_user_and_issues_a_token(): void
    {
        $signup = BorrowerSignupId::factory()->create();
        $partnerId = $signup->partner_id;

        $result = (new BorrowerAuthService)->registerBorrower($this->registrationData($signup));

        $this->assertTrue($result['status']);
        $this->assertNotEmpty($result['token']);
        $this->assertInstanceOf(User::class, $result['user']);

        $borrower = Borrower::where('email', 'jamie.rivera@example.com')->first();
        $this->assertNotNull($borrower);
        $this->assertSame('Jamie', $borrower->first_name);
        $this->assertSame('Rivera', $borrower->last_name);
        $this->assertSame($signup->signup_id, $borrower->signup_id);
        $this->assertSame($partnerId, $borrower->partner_id);

        $user = User::where('email', 'jamie.rivera@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Jamie Rivera', $user->name);
        $this->assertSame(User::BORROWER, $user->user_type);
        $this->assertSame(User::STATUS['active'], $user->status);
        $this->assertSame(Borrower::class, $user->entity_type);
        $this->assertSame($borrower->id, $user->entity_id);
        $this->assertTrue(Hash::check('Password123!', $user->password));
    }

    public function test_deletes_the_signup_id_row_after_a_successful_registration(): void
    {
        $signup = BorrowerSignupId::factory()->create();

        (new BorrowerAuthService)->registerBorrower($this->registrationData($signup));

        $this->assertDatabaseMissing('signup_ids', ['signup_id' => $signup->signup_id]);
    }

    public function test_fails_gracefully_when_signup_id_does_not_exist(): void
    {
        $fakeSignup = new BorrowerSignupId(['signup_id' => 'DOES-NOT-EXIST']);

        $result = (new BorrowerAuthService)->registerBorrower($this->registrationData($fakeSignup));

        $this->assertFalse($result['status']);
        $this->assertSame(__('borrower.signup_id_not_found'), $result['message']);
        $this->assertDatabaseMissing('users', ['email' => 'jamie.rivera@example.com']);
        $this->assertDatabaseMissing('borrowers', ['email' => 'jamie.rivera@example.com']);
    }

    public function test_the_issued_token_authenticates_as_the_new_borrower_user(): void
    {
        $signup = BorrowerSignupId::factory()->create();

        $result = (new BorrowerAuthService)->registerBorrower($this->registrationData($signup));

        $response = $this->withHeader('Authorization', 'Bearer '.$result['token'])
            ->postJson('/api/v1/borrower/logout');

        // Just proving the token is a real, usable Passport token for this borrower
        // user (passes auth:api + the borrower role gate) — logout()'s own
        // behavior is exercised in the Functional HTTP tests.
        $response->assertOk();
    }
}
