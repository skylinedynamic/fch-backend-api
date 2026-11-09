<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\Borrower\Profile\ChangeRequestController;
use App\Http\Requests\Api\V1\Borrower\Profile\StoreChangeRequest;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerBankAccount;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Round 2 BUG-039: a change request with the value already on file, and a
 * second request for a field that already had one waiting for review, were
 * both accepted - the host saw three pending Business Phone requests for one
 * client at once, one of them 0122 -> 0122. Both are refused now, and the
 * portal can ask which fields are waiting so it can say so up front.
 */
#[CoversClass(StoreChangeRequest::class)]
#[CoversClass(ChangeRequestController::class)]
class ChangeRequestDuplicateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const SUBMIT = '/api/v1/borrower/profile/change-request';
    private const PENDING = '/api/v1/borrower/profile/change-requests/pending';

    private Partner $partner;
    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
        $this->myBorrower()->update([
            'business_phone' => '(555) 555-0122',
            'business_email' => 'office@boco.example.org',
            'business_name' => 'Bo Co',
            'monthly_revenue' => 12000,
        ]);
        $this->actingAsUser($this->me);
    }

    private function myBorrower(): Borrower
    {
        return Borrower::find($this->me->entity_id);
    }

    private function submit(string $field, mixed $value, array $extra = []): TestResponse
    {
        return $this->postJson(self::SUBMIT, ['field_name' => $field, 'new_value' => $value] + $extra);
    }

    private function refusal(TestResponse $response): ?string
    {
        return $response->assertUnprocessable()->json('errors.new_value');
    }

    private function pendingCount(string $field): int
    {
        return BorrowerChangeRequest::where('borrower_id', $this->me->entity_id)
            ->where('field_name', $field)
            ->where('status', BorrowerChangeRequest::STATUS_PENDING)
            ->count();
    }

    #[Test]
    public function the_value_already_on_file_is_refused_however_it_is_written(): void
    {
        foreach (['(555) 555-0122', '555-555-0122', '5555550122'] as $same) {
            $this->assertSame(__('profile.change_request_same_value'), $this->refusal($this->submit('business_phone', $same)));
        }
        $this->assertSame(__('profile.change_request_same_value'), $this->refusal($this->submit('business_email', ' Office@BoCo.example.org ')));
        $this->assertSame(__('profile.change_request_same_value'), $this->refusal($this->submit('monthly_revenue', '12000.00')));

        $this->assertSame(0, BorrowerChangeRequest::where('borrower_id', $this->me->entity_id)->count());
    }

    #[Test]
    public function the_same_ssn_is_refused(): void
    {
        $borrower = $this->myBorrower();
        $borrower->ssn = '123-45-6789';
        $borrower->save();

        $this->assertSame(__('profile.change_request_same_value'), $this->refusal($this->submit('ssn', '123456789')));
    }

    #[Test]
    public function a_different_value_is_taken(): void
    {
        $this->submit('business_phone', '(555) 555-0188')->assertOk();

        $this->assertSame(1, $this->pendingCount('business_phone'));
    }

    #[Test]
    public function a_second_request_for_a_field_waiting_for_review_is_refused(): void
    {
        $this->submit('business_phone', '(555) 555-0188')->assertOk();

        $this->assertSame(__('profile.change_request_already_pending'), $this->refusal($this->submit('business_phone', '(555) 555-0199')));
        $this->assertSame(1, $this->pendingCount('business_phone'));
    }

    #[Test]
    public function once_the_request_is_reviewed_a_new_one_may_be_filed(): void
    {
        $this->submit('business_phone', '(555) 555-0188')->assertOk();
        BorrowerChangeRequest::where('field_name', 'business_phone')->update(['status' => BorrowerChangeRequest::STATUS_REJECTED]);

        $this->submit('business_phone', '(555) 555-0199')->assertOk();
    }

    #[Test]
    public function a_request_waiting_on_another_field_does_not_block(): void
    {
        $this->submit('business_phone', '(555) 555-0188')->assertOk();

        $this->submit('business_name', 'Bo Company')->assertOk();
    }

    #[Test]
    public function each_bank_account_is_its_own_field(): void
    {
        $first = BorrowerBankAccount::create(['borrower_id' => $this->me->entity_id, 'bank_name' => 'First Bank']);
        $second = BorrowerBankAccount::create(['borrower_id' => $this->me->entity_id, 'bank_name' => 'Second Bank']);

        $this->submit('bank_name', 'First National', ['entity_id' => $first->id])->assertOk();

        $this->submit('bank_name', 'Second National', ['entity_id' => $second->id])->assertOk();
        $this->assertSame(__('profile.change_request_already_pending'), $this->refusal($this->submit('bank_name', 'Another', ['entity_id' => $first->id])));
        $this->assertSame(__('profile.change_request_same_value'), $this->refusal($this->submit('bank_name', 'Second Bank', ['entity_id' => $second->id])));
    }

    #[Test]
    public function the_portal_can_ask_which_fields_are_waiting_for_review(): void
    {
        $account = BorrowerBankAccount::create(['borrower_id' => $this->me->entity_id, 'bank_name' => 'First Bank']);
        $this->submit('business_phone', '(555) 555-0188')->assertOk();
        $this->submit('bank_name', 'First National', ['entity_id' => $account->id])->assertOk();
        $this->submit('business_name', 'Bo Company')->assertOk();
        BorrowerChangeRequest::where('field_name', 'business_name')->update(['status' => BorrowerChangeRequest::STATUS_APPROVED]);

        // Someone else's pending request is not mine.
        $other = $this->makeBorrowerUser($this->partner);
        BorrowerChangeRequest::create([
            'partner_id' => $this->partner->id, 'borrower_id' => $other->entity_id, 'field_name' => 'business_email',
            'field_type' => 'string', 'visible_field_name' => 'Business Email Address', 'db_field_name' => 'business_email',
            'entity_type' => Borrower::class, 'entity_id' => $other->entity_id, 'old_value' => 'a@x.test', 'new_value' => 'b@x.test',
            'status' => BorrowerChangeRequest::STATUS_PENDING,
        ]);

        $pending = $this->getJson(self::PENDING)->assertOk()->json('data');

        $idOf = fn (string $field) => BorrowerChangeRequest::where('borrower_id', $this->me->entity_id)
            ->where('field_name', $field)->where('status', BorrowerChangeRequest::STATUS_PENDING)->value('id');
        $this->assertEqualsCanonicalizing([
            ['id' => $idOf('business_phone'), 'field_name' => 'business_phone', 'entity_id' => null],
            ['id' => $idOf('bank_name'), 'field_name' => 'bank_name', 'entity_id' => $account->id],
        ], $pending);
    }

    private function cancel(int $id): TestResponse
    {
        return $this->deleteJson("/api/v1/borrower/profile/change-requests/{$id}");
    }

    private function pendingId(string $field): int
    {
        return BorrowerChangeRequest::where('borrower_id', $this->me->entity_id)->where('field_name', $field)->value('id');
    }

    #[Test]
    public function the_borrower_cancels_a_pending_request_and_it_is_gone_for_good(): void
    {
        $this->submit('business_phone', '(555) 555-0188')->assertOk();
        $id = $this->pendingId('business_phone');

        $this->cancel($id)->assertOk()->assertJsonPath('message', __('profile.change_request_cancelled'));

        // Force deleted - not even a trashed row is kept.
        $this->assertNull(BorrowerChangeRequest::withTrashed()->find($id));
        $this->assertSame([], $this->getJson(self::PENDING)->assertOk()->json('data'));
        // The field takes a new request.
        $this->submit('business_phone', '(555) 555-0199')->assertOk();
    }

    #[Test]
    public function a_reviewed_request_cannot_be_cancelled(): void
    {
        $this->submit('business_phone', '(555) 555-0188')->assertOk();
        $id = $this->pendingId('business_phone');
        BorrowerChangeRequest::whereKey($id)->update(['status' => BorrowerChangeRequest::STATUS_APPROVED]);

        $this->cancel($id)->assertUnprocessable()->assertJsonPath('message', __('profile.change_request_already_processed'));
        $this->assertNotNull(BorrowerChangeRequest::find($id));
    }

    #[Test]
    public function another_borrowers_request_cannot_be_cancelled(): void
    {
        $other = $this->makeBorrowerUser($this->partner);
        $theirs = BorrowerChangeRequest::create([
            'partner_id' => $this->partner->id, 'borrower_id' => $other->entity_id, 'field_name' => 'business_email',
            'field_type' => 'string', 'visible_field_name' => 'Business Email Address', 'db_field_name' => 'business_email',
            'entity_type' => Borrower::class, 'entity_id' => $other->entity_id, 'old_value' => 'a@x.test', 'new_value' => 'b@x.test',
            'status' => BorrowerChangeRequest::STATUS_PENDING,
        ]);

        $this->cancel($theirs->id)->assertNotFound();
        $this->cancel(999999)->assertNotFound();
        $this->assertNotNull(BorrowerChangeRequest::find($theirs->id));
    }
}
