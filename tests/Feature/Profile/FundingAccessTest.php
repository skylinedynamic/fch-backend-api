<?php

namespace Tests\Feature\Profile;

use App\Http\Controllers\Api\V1\Borrower\Profile\ProfileCompletionController;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower's Funding page stays closed until FCH has uploaded their credit
 * report (Admin → client → Credit Profile → save). Without it every funding
 * criterion reads "Failed", so the portal shows a locked page instead.
 * Reported on GET /borrower/profile/completion as `funding`.
 */
#[CoversClass(ProfileCompletionController::class)]
class FundingAccessTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $me;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner());
        $this->me = $this->makeBorrowerUser($this->makeWhiteLabelPartner());
    }

    private function borrower(): Borrower
    {
        return Borrower::find($this->me->entity_id);
    }

    /** @return array{unlocked: bool, credit_profile_submitted: bool} */
    private function fundingAccess(): array
    {
        $this->actingAsUser($this->me);

        return $this->getJson('/api/v1/borrower/profile/completion')->assertOk()->json('data.funding');
    }

    /** What the borrower's credit profile form does on submit (CreditProfileRequestService). */
    private function submitCreditProfile(): void
    {
        CreditProfileRequest::create([
            'borrower_id' => $this->borrower()->id,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'address' => '1 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '73301',
            'ssn' => '123456789',
            'date_of_birth' => '1986-09-01',
            'phone' => '5125550100',
            'email' => 'bo@example.test',
        ]);
        $this->borrower()->update(['credit_profile_updated' => true]);
    }

    /** The admin saves the borrower's credit report, the way the admin panel does. */
    private function uploadCreditReport(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->postJson('/api/v1/common/borrowers/save-credit-profile', [
            'borrower_id' => $this->borrower()->id,
            'metrics' => ['fico_score' => 712, 'paydex_score' => 80],
        ])->assertOk();
    }

    #[Test]
    public function funding_is_locked_before_anything_is_in(): void
    {
        $this->assertSame(['unlocked' => false, 'credit_profile_submitted' => false], $this->fundingAccess());
    }

    #[Test]
    public function submitting_the_credit_profile_is_not_enough(): void
    {
        // The form sets credit_profile_updated by itself; the report is still to come.
        $this->submitCreditProfile();

        $this->assertSame(['unlocked' => false, 'credit_profile_submitted' => true], $this->fundingAccess());
    }

    #[Test]
    public function funding_opens_once_the_admin_uploads_the_credit_report(): void
    {
        $this->submitCreditProfile();
        $this->uploadCreditReport();

        $this->assertSame(['unlocked' => true, 'credit_profile_submitted' => true], $this->fundingAccess());
    }

    #[Test]
    public function a_report_uploaded_without_a_borrower_request_opens_it_too(): void
    {
        // An admin can attach a credit report without the borrower submitting the form.
        $this->uploadCreditReport();

        $this->assertSame(['unlocked' => true, 'credit_profile_submitted' => true], $this->fundingAccess());
    }
}
