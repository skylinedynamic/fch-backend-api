<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\Borrower\Auth\BorrowerUserDetailController;
use App\Http\Controllers\Api\V1\Borrower\Common\BorrowerController;
use App\Http\Controllers\Api\V1\Borrower\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\Borrower\Profile\BusinessInfoController;
use App\Http\Controllers\Api\V1\Borrower\Profile\ChangeRequestController;
use App\Http\Controllers\Api\V1\Borrower\Profile\FinancialInfoController;
use App\Http\Controllers\Api\V1\Borrower\Profile\ProfileCompletionController;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerBankAccount;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower portal's own-profile screens: business and financial info,
 * change requests for locked fields, profile completion, the dashboard, the
 * signed-in user's details and their advisor. All of it is the signed-in
 * borrower's own record.
 */
#[CoversClass(BusinessInfoController::class)]
#[CoversClass(FinancialInfoController::class)]
#[CoversClass(ChangeRequestController::class)]
#[CoversClass(ProfileCompletionController::class)]
#[CoversClass(DashboardController::class)]
#[CoversClass(BorrowerUserDetailController::class)]
#[CoversClass(BorrowerController::class)]
class BorrowerProfileApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
    }

    private function myBorrower(): Borrower
    {
        return Borrower::find($this->me->entity_id);
    }

    private function business(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Bo Co',
            'business_ein' => '12-3456789',
            'business_email' => 'office@boco.example.org',
            'business_address' => '1 Main St',
            'business_country' => 'United States',
            'business_country_code' => 'US',
            'business_city' => 'Austin',
            'business_state' => 'TX',
            'business_zip_code' => '73301',
            'business_entity_type' => 'LLC',
            'business_industry_type' => 'Retail',
            'business_start_date' => '2020-01-15',
        ], $overrides);
    }

    #[Test]
    public function a_borrower_saves_and_reads_their_business_info(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/business-info', $this->business())->assertOk();
        $this->getJson('/api/v1/borrower/profile/business-info')->assertOk()->assertJsonPath('status', true);

        $this->assertSame('Bo Co', $this->myBorrower()->business_name);
        $this->assertSame('73301', $this->myBorrower()->business_postal_code);
    }

    /**
     * Defect (O26): a failure returned the raw exception message - SQL and all -
     * to the borrower; the translated message sat below an earlier return.
     */
    #[Test]
    public function a_failed_business_info_save_does_not_show_the_internal_error(): void
    {
        $this->mock(\App\Services\Borrower\Profile\BusinessInfoService::class)
            ->shouldReceive('updateBusinessInfo')
            ->andThrow(new \RuntimeException('SQLSTATE[42S22]: Unknown column borrowers.secret'));
        $this->actingAsUser($this->me);

        $response = $this->postJson('/api/v1/borrower/profile/business-info', $this->business())->assertStatus(500);

        $response->assertJsonPath('message', __('borrower.business_info_update_failed'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    #[Test]
    public function business_info_is_validated(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/business-info', $this->business(['business_ein' => 'AB-CDEFGHI', 'business_zip_code' => '7330']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_ein', 'business_zip_code']);
    }

    #[Test]
    public function business_phone_and_duns_must_be_in_their_shared_formats(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/business-info', $this->business(['business_phone' => '(124) 982-7457', 'duns_number' => '12345678']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_phone' => __('embed_form.phone_invalid'), 'duns_number' => __('common.duns_number_invalid')]);
    }

    #[Test]
    public function a_borrower_syncs_their_bank_accounts_and_cannot_touch_anyone_elses(): void
    {
        $kept = BorrowerBankAccount::create(['borrower_id' => $this->me->entity_id, 'bank_name' => 'Old Bank']);
        $dropped = BorrowerBankAccount::create(['borrower_id' => $this->me->entity_id, 'bank_name' => 'Gone Bank']);
        $theirs = BorrowerBankAccount::create(['borrower_id' => $this->makeBorrower($this->partner)->id, 'bank_name' => 'Their Bank']);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/financial-info', [
            'currency' => 'USD',
            'monthly_revenue' => 10000,
            'annual_revenue' => 120000,
            'average_monthly_deposits' => 9000,
            'annual_personal_income' => 80000,
            'bank_accounts' => [
                // A saved bank name is locked (BUG-054): sent back unchanged.
                // Renaming it is covered by LockedProfileFieldsTest.
                ['id' => $kept->id, 'bank_name' => 'Old Bank'],
                ['id' => $theirs->id, 'bank_name' => 'Hijacked'],
                ['bank_name' => 'New Bank'],
            ],
        ])->assertOk();

        $this->assertSame('Old Bank', $kept->fresh()->bank_name);
        $this->assertNull($dropped->fresh());
        $this->assertSame('Their Bank', $theirs->fresh()->bank_name);
        $this->assertEqualsCanonicalizing(['Old Bank', 'New Bank'], BorrowerBankAccount::where('borrower_id', $this->me->entity_id)->pluck('bank_name')->all());

        $this->getJson('/api/v1/borrower/profile/financial-info')->assertOk();
    }

    #[Test]
    public function a_change_request_records_the_old_and_new_value(): void
    {
        $this->myBorrower()->update(['business_name' => 'Old Name']);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'business_name', 'new_value' => 'New Name'])->assertOk();

        $this->assertDatabaseHas('borrower_change_requests', [
            'borrower_id' => $this->me->entity_id,
            'partner_id' => $this->partner->id,
            'field_name' => 'business_name',
            'old_value' => 'Old Name',
            'new_value' => 'New Name',
            'entity_type' => Borrower::class,
            'entity_id' => $this->me->entity_id,
            'status' => BorrowerChangeRequest::STATUS_PENDING,
        ]);
    }

    /**
     * BY DESIGN (BUG-071): a business email may be any address, including one
     * another account signs in with. Nothing is mailed to it - mail goes to the
     * client's own sign-in email, and the business email is only shown on the
     * profile and printed on documents - and two clients of one company may
     * share an office address. Do not add a uniqueness rule for it.
     */
    #[Test]
    public function a_business_email_may_be_another_accounts_email_by_design(): void
    {
        $other = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'business_email', 'new_value' => $other->email])
            ->assertOk();

        $this->assertDatabaseHas('borrower_change_requests', [
            'borrower_id' => $this->me->entity_id,
            'field_name' => 'business_email',
            'new_value' => $other->email,
        ]);
    }

    /** BUG-007: a change request may not set a business start date in the future. */
    #[Test]
    public function a_business_start_date_change_request_in_the_future_is_refused(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'business_start_date', 'new_value' => now()->addYear()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_value');
    }

    #[Test]
    public function a_change_request_value_is_validated_by_the_fields_own_rule(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'business_email', 'new_value' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_value');
        $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'no_such_field', 'new_value' => 'x'])->assertStatus(400);
    }

    #[Test]
    public function a_personal_credit_score_change_request_must_be_a_fico_score(): void
    {
        $this->actingAsUser($this->me);

        foreach ([299, 851, 0, '720.5'] as $score) {
            $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'personal_credit_score', 'new_value' => $score])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['new_value' => __('common.fico_score_invalid', ['min' => 300, 'max' => 850])]);
        }

        $this->assertDatabaseMissing('borrower_change_requests', ['field_name' => 'personal_credit_score']);
    }

    /**
     * The SSN is asked for in full, as everywhere else, and both the number on
     * file and the requested one are kept encrypted in the request - never as
     * plain digits in borrower_change_requests.
     */
    #[Test]
    public function an_ssn_change_request_is_a_full_ssn_kept_encrypted(): void
    {
        $this->myBorrower()->update(['ssn' => '123456789']);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'ssn', 'new_value' => '234-56-7890'])->assertOk();

        $request = BorrowerChangeRequest::where('field_name', 'ssn')->firstOrFail();
        $this->assertSame('123456789', $request->old_value);
        $this->assertSame('234567890', $request->new_value);
        $this->assertStringNotContainsString('7890', (string) $request->getRawOriginal('new_value'));
        $this->assertStringNotContainsString('6789', (string) $request->getRawOriginal('old_value'));
    }

    #[Test]
    public function an_ssn_change_request_must_be_a_valid_full_ssn(): void
    {
        $this->actingAsUser($this->me);

        foreach (['6789', '12-345', '000-12-3456', ''] as $bad) {
            $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'ssn', 'new_value' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('new_value');
        }

        // The last four on their own are no longer a field that can be changed.
        $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'last_4_ssn', 'new_value' => '6789'])->assertStatus(400);

        $this->assertDatabaseCount('borrower_change_requests', 0);
    }

    #[Test]
    public function a_bank_account_change_request_names_one_of_my_accounts(): void
    {
        $mine = BorrowerBankAccount::create(['borrower_id' => $this->me->entity_id, 'bank_name' => 'My Bank']);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'bank_name', 'new_value' => 'My New Bank', 'entity_id' => $mine->id])
            ->assertOk();

        $this->assertDatabaseHas('borrower_change_requests', ['entity_id' => $mine->id, 'old_value' => 'My Bank', 'new_value' => 'My New Bank']);
    }

    /**
     * Defect: a bank-account change request was accepted for any account id
     * - another borrower's, one that does not exist, or none at all (when it
     * was filed against the borrower's own id as if that were an account) -
     * and queued for the host as a pending change that does nothing, or the
     * wrong thing, when approved.
     */
    #[Test]
    public function a_bank_account_change_request_for_an_account_that_is_not_mine_is_refused(): void
    {
        $theirs = BorrowerBankAccount::create(['borrower_id' => $this->makeBorrower($this->partner)->id, 'bank_name' => 'Their Bank']);
        $this->actingAsUser($this->me);

        foreach ([['entity_id' => $theirs->id], ['entity_id' => 999999], []] as $target) {
            $this->postJson('/api/v1/borrower/profile/change-request', ['field_name' => 'bank_name', 'new_value' => 'Hijack Bank'] + $target)
                ->assertStatus(400);
        }

        $this->assertDatabaseCount('borrower_change_requests', 0);
    }

    #[Test]
    public function the_profile_completion_dashboard_and_user_details_load(): void
    {
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/profile/completion')->assertOk();
        $this->getJson('/api/v1/borrower/dashboard')->assertOk();
        $this->getJson('/api/v1/borrower/user-details')->assertOk();
    }

    #[Test]
    public function the_advisor_is_the_user_assigned_to_me(): void
    {
        $this->actingAsUser($this->me);
        $this->getJson('/api/v1/borrower/get-advisor')->assertNotFound();

        $advisor = $this->makeStaffUser($this->partner);
        $this->myBorrower()->update(['assign_id' => $advisor->id]);

        $this->getJson('/api/v1/borrower/get-advisor')->assertOk()->assertJsonPath('data.id', $advisor->id);
    }
}
