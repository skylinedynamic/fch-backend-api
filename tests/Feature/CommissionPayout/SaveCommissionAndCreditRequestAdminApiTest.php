<?php

namespace Tests\Feature\CommissionPayout;

use App\Http\Controllers\Api\V1\FCH\Admin\Commission\CommissionController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditProfileRequest\CreditProfileRequestAdminController;
use App\Http\Requests\Api\V1\FCH\Admin\Commission\SaveCommissionRequest;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Two host-team actions: setting the commission on a funding application
 * (/admin/funding-application/save-commission) and opening a client's
 * credit-profile request (/admin/credit-profile-requests/{id}).
 */
#[CoversClass(CommissionController::class)]
#[CoversClass(CreditProfileRequestAdminController::class)]
#[CoversClass(SaveCommissionRequest::class)]
class SaveCommissionAndCreditRequestAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->reviewer = $this->makeStaffUser($this->makeHostPartner());
        $this->partner = $this->makeWhiteLabelPartner();
    }

    private function application(array $attributes = []): FundingApplication
    {
        return FundingApplication::forceCreate(array_merge([
            'borrower_id' => $this->makeBorrower($this->partner)->id,
            'status' => FundingApplication::CLOSED_COMPLETED,
            'requested_amount' => 100000,
        ], $attributes));
    }

    #[Test]
    public function the_commission_is_split_between_host_and_partner(): void
    {
        $application = $this->application();
        $this->actingAsUser($this->reviewer);

        $this->postJson('/api/v1/admin/funding-application/save-commission', [
            'funding_application_id' => $application->id,
            'total_commission' => 10,
            'admin_commission' => 40,
            'partner_commission' => 60,
        ])->assertOk();

        $commission = Commission::where('funding_application_id', $application->id)->firstOrFail();
        $this->assertSame($this->partner->id, (int) $commission->partner_id);
        $this->assertEquals(10000, $commission->total_commission_amount);
        $this->assertEquals(6000, $commission->partner_commission_amount);
        $this->assertEquals(4000, $commission->admin_commission_amount);
        $this->assertSame(Commission::STATUS_DRAFT, $commission->commission_status);
    }

    #[Test]
    public function with_no_partner_share_the_host_keeps_it_all_and_a_resave_replaces_it(): void
    {
        $application = $this->application();
        $this->actingAsUser($this->reviewer);
        $payload = ['funding_application_id' => $application->id, 'total_commission' => 5, 'admin_commission' => 30];

        $this->postJson('/api/v1/admin/funding-application/save-commission', $payload + ['partner_commission' => 70])->assertOk();
        $this->postJson('/api/v1/admin/funding-application/save-commission', $payload)->assertOk();

        $commission = Commission::where('funding_application_id', $application->id)->sole();
        $this->assertEquals(100, $commission->admin_commission);
        $this->assertEquals(5000, $commission->admin_commission_amount);
        $this->assertEquals(0, $commission->partner_commission_amount);
    }

    #[Test]
    public function a_commission_rate_is_bounded(): void
    {
        $this->actingAsUser($this->reviewer);

        $this->postJson('/api/v1/admin/funding-application/save-commission', [
            'funding_application_id' => $this->application()->id,
            'total_commission' => 150,
            'admin_commission' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['total_commission', 'admin_commission']);
    }

    /**
     * Defect: a partner client's success fee could be split so that the admin
     * and partner rates did not add up to 100% (e.g. admin 1%, partner 91%) -
     * the closing modal let either be cleared without the other.
     */
    #[Test]
    public function the_admin_and_partner_rates_must_add_up_to_100(): void
    {
        $application = $this->application();
        $this->actingAsUser($this->reviewer);

        $this->postJson('/api/v1/admin/funding-application/save-commission', [
            'funding_application_id' => $application->id,
            'total_commission' => 10,
            'admin_commission' => 1,
            'partner_commission' => 91,
        ])->assertUnprocessable()->assertJsonValidationErrors(['partner_commission' => __('application.commission_split_must_total_100')]);

        $this->assertFalse(Commission::where('funding_application_id', $application->id)->exists());

        $this->postJson('/api/v1/admin/funding-application/save-commission', [
            'funding_application_id' => $application->id,
            'total_commission' => 10,
            'admin_commission' => 9.5,
            'partner_commission' => 90.5,
        ])->assertOk();
    }

    #[Test]
    public function commissions_are_set_by_the_host_team_only(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/admin/funding-application/save-commission', [
            'funding_application_id' => $this->application()->id,
            'total_commission' => 10,
            'admin_commission' => 10,
        ])->assertForbidden();
    }

    #[Test]
    public function the_host_opens_a_credit_profile_request(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $request = CreditProfileRequest::create([
            'borrower_id' => $borrower->id,
            'first_name' => 'Client',
            'last_name' => 'One',
            'address' => '1 Main St',
            'city' => 'Austin',
            'state' => 'Texas',
            'zip' => '78701',
            'ssn' => '123456789',
            'date_of_birth' => '1990-01-01',
            'phone' => '5125550100',
            'email' => 'client@example.org',
        ]);
        $this->actingAsUser($this->reviewer);

        $this->getJson("/api/v1/admin/credit-profile-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonPath('data.credit_authorizations', []);
        $this->getJson('/api/v1/admin/credit-profile-requests/999999')->assertNotFound();
    }
}
