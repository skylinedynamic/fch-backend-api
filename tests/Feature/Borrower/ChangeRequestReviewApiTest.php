<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\FCH\Admin\IntakeReview\ChangeRequest\ChangeRequestController;
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
 * The host team's side of borrower change requests (/admin/change-requests):
 * a request a borrower files for a locked field (BorrowerProfileApiTest) is
 * applied when approved, left alone when rejected, and decided only once.
 */
#[CoversClass(ChangeRequestController::class)]
class ChangeRequestReviewApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;
    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->reviewer = $this->makeStaffUser($this->makeHostPartner());
        $this->partner = $this->makeWhiteLabelPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
    }

    private function file(array $request): int
    {
        $this->actingAsUser($this->me);
        $this->postJson('/api/v1/borrower/profile/change-request', $request)->assertOk();

        return BorrowerChangeRequest::latest('id')->value('id');
    }

    private function myBorrower(): Borrower
    {
        return Borrower::find($this->me->entity_id);
    }

    #[Test]
    public function the_queue_is_host_team_only(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/admin/change-requests')->assertForbidden();
    }

    #[Test]
    public function an_approved_change_is_applied_to_the_borrower(): void
    {
        $id = $this->file(['field_name' => 'business_name', 'new_value' => 'New Co']);
        $this->actingAsUser($this->reviewer);

        $this->postJson('/api/v1/admin/change-requests', ['status' => BorrowerChangeRequest::STATUS_PENDING])
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
        $this->patchJson("/api/v1/admin/change-requests/{$id}", ['status' => BorrowerChangeRequest::STATUS_APPROVED, 'note' => 'Verified'])->assertOk();

        $this->assertSame('New Co', $this->myBorrower()->business_name);
        $this->assertSame(BorrowerChangeRequest::STATUS_APPROVED, BorrowerChangeRequest::find($id)->status);
        $this->assertSame($this->reviewer->id, (int) BorrowerChangeRequest::find($id)->updated_by);
    }

    #[Test]
    public function an_approved_ssn_change_replaces_the_full_ssn_on_file(): void
    {
        $this->myBorrower()->update(['ssn' => '123456789']);
        $id = $this->file(['field_name' => 'ssn', 'new_value' => '234-56-7890']);
        $this->actingAsUser($this->reviewer);

        $this->patchJson("/api/v1/admin/change-requests/{$id}", ['status' => BorrowerChangeRequest::STATUS_APPROVED])->assertOk();

        $this->assertSame('234567890', $this->myBorrower()->ssn);
        // Still sealed after the decision is written.
        $this->assertStringNotContainsString('7890', (string) BorrowerChangeRequest::find($id)->getRawOriginal('new_value'));
    }

    #[Test]
    public function an_approved_bank_account_change_lands_on_that_account(): void
    {
        $account = BorrowerBankAccount::create(['borrower_id' => $this->me->entity_id, 'bank_name' => 'Old Bank']);
        $id = $this->file(['field_name' => 'bank_name', 'new_value' => 'New Bank', 'entity_id' => $account->id]);
        $this->actingAsUser($this->reviewer);

        $this->patchJson("/api/v1/admin/change-requests/{$id}", ['status' => BorrowerChangeRequest::STATUS_APPROVED])->assertOk();

        $this->assertSame('New Bank', $account->fresh()->bank_name);
    }

    #[Test]
    public function a_rejected_change_is_not_applied_and_a_decision_is_final(): void
    {
        $this->myBorrower()->update(['business_name' => 'Old Co']);
        $id = $this->file(['field_name' => 'business_name', 'new_value' => 'New Co']);
        $this->actingAsUser($this->reviewer);

        $this->patchJson("/api/v1/admin/change-requests/{$id}", ['status' => BorrowerChangeRequest::STATUS_REJECTED, 'note' => 'No proof'])->assertOk();
        $this->patchJson("/api/v1/admin/change-requests/{$id}", ['status' => BorrowerChangeRequest::STATUS_APPROVED])->assertUnprocessable();

        $this->assertSame('Old Co', $this->myBorrower()->business_name);
        $this->assertSame(BorrowerChangeRequest::STATUS_REJECTED, BorrowerChangeRequest::find($id)->status);
    }

    #[Test]
    public function a_decision_must_be_a_known_status(): void
    {
        $id = $this->file(['field_name' => 'business_name', 'new_value' => 'New Co']);
        $this->actingAsUser($this->reviewer);

        $this->patchJson("/api/v1/admin/change-requests/{$id}", ['status' => 'Maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
