<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\CreditBuilder\Tier;
use App\Models\Invoice\Invoice;
use App\Models\Subscription\Subscription;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner portal's client records: creating, editing, listing, archiving
 * and deleting borrowers (/common/borrowers*), plus the lookups the client
 * screens fill their dropdowns from. Who may reach which borrower is pinned in
 * BorrowerTenantIsolationTest; this is what each action does.
 */
#[CoversClass(BorrowerController::class)]
class BorrowerRecordsApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
    }

    private function newBorrowerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada-' . uniqid() . '@example.test',
            'phone_number' => '(555) 234-5678',
            'country' => 'United States',
            'business_country' => 'United States',
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->toDateString(),
        ], $overrides);
    }

    #[Test]
    public function creating_a_borrower_also_creates_their_login_under_the_creators_partner(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $payload = $this->newBorrowerPayload();

        $id = $this->postJson('/api/v1/common/borrowers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Ada')
            ->json('data.id');

        $borrower = Borrower::findOrFail($id);
        $this->assertSame($this->partner->id, $borrower->partner_id);
        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'user_type' => User::BORROWER,
            'entity_type' => Borrower::class,
            'entity_id' => $id,
            'partner_id' => $this->partner->id,
        ]);
    }

    #[Test]
    public function a_non_host_partner_cannot_create_a_borrower_under_another_partner(): void
    {
        $other = $this->makeCertifiedPartner();
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['partner_id' => $other->id]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($this->partner->id, Borrower::find($id)->partner_id);
    }

    #[Test]
    public function the_host_may_create_a_borrower_for_a_partner(): void
    {
        // The host is never held to the white-label setup check, though it is
        // seeded as a white-label partner type (Controller::checkWhiteLabelConfiguration).
        AppConfiguration::create(['partner_id' => $this->host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $id = $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['partner_id' => $this->partner->id]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($this->partner->id, Borrower::find($id)->partner_id);
    }

    #[Test]
    public function an_email_already_in_use_is_refused(): void
    {
        $existing = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['email' => $existing->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function a_borrower_needs_a_name_email_phone_and_countries_and_a_paid_one_an_enrollment(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers', ['is_free_trail' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'first_name', 'last_name', 'email', 'phone_number', 'country', 'business_country', 'enrollment',
            ]);
    }

    #[Test]
    public function a_phone_or_ssn_in_the_wrong_shape_is_refused(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['phone_number' => 'call me']))
            ->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
        $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['ssn' => '12-345']))
            ->assertStatus(422)->assertJsonValidationErrors(['ssn']);
    }

    #[Test]
    public function a_clients_ssn_is_the_full_number_kept_encrypted(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['ssn' => '123-45-6789']))
            ->assertCreated()
            ->json('data.id');

        $borrower = Borrower::findOrFail($id);
        $this->assertSame('123456789', $borrower->ssn);
        $this->assertStringNotContainsString('6789', (string) $borrower->getRawOriginal('ssn_encrypted'));
    }

    /**
     * Client add and edit take the full SSN only (as `ssn`); the old
     * last-four-only field is not a way in any more, on either.
     */
    #[Test]
    public function the_last_four_digits_alone_are_not_taken_for_a_client(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['last_4_ssn' => '6789']))
            ->assertCreated()
            ->json('data.id');
        $this->assertNull(Borrower::findOrFail($id)->ssn);

        $borrower = $this->makeBorrower($this->partner, ['ssn' => '123456789']);
        $this->postJson("/api/v1/common/borrowers/{$borrower->id}", ['first_name' => 'New', 'last_4_ssn' => '1111'])->assertOk();
        $this->assertSame('123456789', $borrower->fresh()->ssn);
    }

    /**
     * The screen writes an EIN as XX-XXXXXXX; the number is the nine digits,
     * and that is what is kept - whichever way it arrived.
     */
    #[Test]
    public function a_clients_ein_is_kept_as_its_nine_digits(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['business_ein' => '53-4534534']))
            ->assertCreated()
            ->json('data.id');
        $this->assertSame('534534534', Borrower::findOrFail($id)->business_ein);

        $this->postJson("/api/v1/common/borrowers/{$id}", ['first_name' => 'Ada', 'business_ein' => '12-3456789'])->assertOk();
        $this->assertSame('123456789', Borrower::findOrFail($id)->business_ein);

        $this->postJson("/api/v1/common/borrowers/{$id}", ['first_name' => 'Ada', 'business_ein' => 'AB-CDEFGHI'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_ein' => __('common.business_ein_invalid')]);
    }

    #[Test]
    public function a_new_clients_credit_scores_must_be_a_fico_score_and_a_whole_business_score(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['fico_score' => 9000, 'paydex_score' => -5]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'fico_score' => __('common.fico_score_invalid', ['min' => 300, 'max' => 850]),
                'paydex_score' => __('common.business_credit_score_invalid'),
            ]);

        $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload(['fico_score' => 720, 'paydex_score' => 0]))
            ->assertSuccessful();
    }

    #[Test]
    public function a_white_label_partner_must_finish_branding_and_email_setup_before_adding_clients(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner()));

        $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload())
            ->assertForbidden()
            ->assertJsonPath('requires_configuration', true);
    }

    /**
     * Defect (O5): the host is seeded as a white-label type, so the same check
     * kept the host from adding clients until it set up a partner's branding
     * and email - which the host, running on the platform's own, never needs.
     */
    #[Test]
    public function the_host_is_not_held_to_the_white_label_setup(): void
    {
        $host = Partner::where('is_host', true)->first()
            ?? $this->makeHostPartner();
        $host->update(['partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL]);
        $this->actingAsUser($this->makePartnerAdmin($host));

        $response = $this->postJson('/api/v1/common/borrowers', $this->newBorrowerPayload());

        $this->assertNotTrue($response->json('requires_configuration'));
    }

    #[Test]
    public function editing_a_borrower_saves_the_change(): void
    {
        $borrower = $this->makeBorrower($this->partner, ['first_name' => 'Old']);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/borrowers/{$borrower->id}", ['first_name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'New');

        $this->assertSame('New', $borrower->fresh()->first_name);
    }

    #[Test]
    public function editing_business_financials_saves_them(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/borrowers/{$borrower->id}/business-financials", [
            'monthly_revenue' => 42000,
            'number_of_employees' => 12,
        ])->assertOk();

        $borrower->refresh();
        $this->assertEquals(42000, $borrower->monthly_revenue);
        $this->assertSame(12, (int) $borrower->number_of_employees);
    }

    #[Test]
    public function the_list_holds_the_partners_borrowers_and_filters_by_status(): void
    {
        $active = $this->makeBorrower($this->partner, ['status' => Borrower::STATUS_ACTIVE]);
        $this->makeBorrower($this->partner, ['status' => Borrower::STATUS_INACTIVE]);
        $this->makeBorrower($this->makeCertifiedPartner());
        $this->actingAsUser($this->partnerAdmin);

        $all = collect($this->postJson('/api/v1/common/borrowers/fetch-all-borrowers')->assertOk()->json('data'));
        $this->assertCount(2, $all);

        $filtered = collect($this->postJson('/api/v1/common/borrowers/fetch-all-borrowers', ['status' => Borrower::STATUS_ACTIVE])
            ->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$active->id], $filtered->all());
    }

    #[Test]
    public function the_host_can_narrow_the_list_to_one_partner(): void
    {
        $mine = $this->makeBorrower($this->partner);
        $this->makeBorrower($this->makeCertifiedPartner());
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $ids = collect($this->postJson('/api/v1/common/borrowers/fetch-all-borrowers', ['partner_id' => $this->partner->id])
            ->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$mine->id], $ids->all());
    }

    #[Test]
    public function archiving_and_unarchiving_need_the_update_clients_permission(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/archive', ['ids' => [$borrower->id]])->assertForbidden();
        $this->postJson('/api/v1/common/borrowers/unarchive', ['ids' => [$borrower->id]])->assertForbidden();
    }

    #[Test]
    public function an_archived_borrower_can_be_unarchived(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $this->actingAsUser($this->grant($this->partnerAdmin, ['update clients']));

        $this->postJson('/api/v1/common/borrowers/archive', ['ids' => [$borrower->id]])->assertOk();
        $this->assertNotNull($borrower->fresh()->archived_at);

        $this->postJson('/api/v1/common/borrowers/unarchive', ['ids' => [$borrower->id]])->assertOk();
        $this->assertNull($borrower->fresh()->archived_at);
    }

    #[Test]
    public function a_borrower_with_no_history_is_reported_deletable_and_is_deleted(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/check-deletable', ['ids' => [$borrower->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $borrower->id)
            ->assertJsonPath('data.0.is_deletable', true);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])->assertOk();

        $this->assertSoftDeleted($borrower);
    }

    private function invoiceFor(Borrower $borrower, string $status): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $borrower->partner_id,
            'borrower_id' => $borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'customer_name' => $borrower->full_name,
            'email' => $borrower->email,
            'payment_amount' => 100,
            'currency' => 'USD',
            'status' => $status,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    /**
     * Defect: deleting a client with a paid invoice was refused, but the
     * reason was thrown away and the portal showed only "Failed to delete
     * borrower(s)." The user could not tell why or what to do instead.
     */
    #[Test]
    public function a_client_with_a_paid_invoice_is_kept_and_the_user_is_told_why_and_what_to_do(): void
    {
        $borrower = $this->makeBorrower($this->partner, ['first_name' => 'Reagan', 'last_name' => 'Hardin']);
        $paid = $this->invoiceFor($borrower, Invoice::STATUS_PAID);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', "This client has related records and cannot be deleted. Please change the client's status instead.")
            ->assertJsonPath('errors.blocked', [['id' => $borrower->id, 'name' => 'Reagan Hardin']]);

        $this->assertNotSoftDeleted($borrower);
        $this->assertNotSoftDeleted($paid);
    }

    #[Test]
    public function when_any_selected_client_has_a_paid_invoice_none_are_deleted_and_each_blocked_one_is_named(): void
    {
        $free = $this->makeBorrower($this->partner, ['first_name' => 'Free', 'last_name' => 'Client']);
        $first = $this->makeBorrower($this->partner, ['first_name' => 'Reagan', 'last_name' => 'Hardin']);
        $second = $this->makeBorrower($this->partner, ['first_name' => 'Jessamine', 'last_name' => 'Oconnor']);
        $this->invoiceFor($first, Invoice::STATUS_PAID);
        $this->invoiceFor($second, Invoice::STATUS_PAID);
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$free->id, $first->id, $second->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Some selected clients have related records and cannot be deleted: Reagan Hardin, Jessamine Oconnor. '
                . 'Please change their status instead, or unselect them to delete the others.');

        $this->assertSame(['Reagan Hardin', 'Jessamine Oconnor'], array_column($response->json('errors.blocked'), 'name'));
        $this->assertNotSoftDeleted($free);
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
    }

    /**
     * Defect: the deletable check looked at "Paid" only, while the delete kept
     * "Success" invoices as paid too - so a client whose payment was recorded
     * as Success was deleted and their paid record left behind.
     */
    #[Test]
    public function a_successful_payment_counts_as_paid(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $this->invoiceFor($borrower, Invoice::STATUS_SUCCESS);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/check-deletable', ['ids' => [$borrower->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false);
        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])->assertStatus(422);

        $this->assertNotSoftDeleted($borrower);
    }

    #[Test]
    public function the_deletable_check_gives_the_same_reason(): void
    {
        $borrower = $this->makeBorrower($this->partner, ['first_name' => 'Reagan', 'last_name' => 'Hardin']);
        $this->invoiceFor($borrower, Invoice::STATUS_PAID);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/check-deletable', ['ids' => [$borrower->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false)
            ->assertJsonPath('data.0.name', 'Reagan Hardin')
            ->assertJsonPath('data.0.reason', "This client has related records and cannot be deleted. Please change the client's status instead.");
    }

    /**
     * A client with only unpaid invoices is deleted, and those invoices go with
     * them, so nothing is left for anyone to pay.
     */
    #[Test]
    public function a_client_with_only_unpaid_invoices_is_deleted_together_with_those_invoices(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $unpaid = $this->invoiceFor($borrower, Invoice::STATUS_UNPAID);
        $overdue = $this->invoiceFor($borrower, Invoice::STATUS_OVERDUE);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])->assertOk();

        $this->assertSoftDeleted($borrower);
        $this->assertSoftDeleted($unpaid);
        $this->assertSoftDeleted($overdue);
    }

    /**
     * Defect: selecting more than one client and deleting failed with "Failed
     * to delete borrower(s)." on stage and local - the delete lazy-loaded each
     * client's login, which is refused outside production once there is more
     * than one model. Production happened to work.
     */
    #[Test]
    public function a_partner_bulk_deletes_several_clients_with_only_unpaid_invoices(): void
    {
        // Real clients have a login; deleting reads it for each one.
        $firstLogin = $this->makeBorrowerUser($this->partner);
        $secondLogin = $this->makeBorrowerUser($this->partner);
        $first = Borrower::findOrFail($firstLogin->entity_id);
        $second = Borrower::findOrFail($secondLogin->entity_id);
        $firstUnpaid = $this->invoiceFor($first, Invoice::STATUS_UNPAID);
        $secondUnpaid = $this->invoiceFor($second, Invoice::STATUS_PENDING);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('message', __('borrower.success.deleted'));

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertSoftDeleted($firstUnpaid);
        $this->assertSoftDeleted($secondUnpaid);
        $this->assertSoftDeleted($firstLogin);
        $this->assertSoftDeleted($secondLogin);
    }

    /**
     * Defect: deleting any client with a subscription failed with "Failed to
     * delete borrower(s)." - the delete called Subscription::allPhases(),
     * which went with the subscription_phases table (dropped 2026-08-31).
     */
    #[Test]
    public function a_client_with_a_subscription_and_only_unpaid_invoices_is_deleted(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $subscription = Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 300,
            'total_subscription_amount' => 300,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $unpaid = $this->invoiceFor($borrower, Invoice::STATUS_UNPAID);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])->assertOk();

        $this->assertSoftDeleted($borrower);
        $this->assertSoftDeleted($subscription);
        $this->assertSoftDeleted($unpaid);
    }

    /**
     * Defect: a client with a login and a subscription could not be deleted -
     * deleting the login went through User::safeDeleteWithRelations(), which
     * still called the removed Subscription::allPhases().
     */
    #[Test]
    public function a_client_with_a_login_and_a_subscription_is_deleted(): void
    {
        $login = $this->makeBorrowerUser($this->partner);
        $borrower = Borrower::findOrFail($login->entity_id);
        $subscription = Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 300,
            'total_subscription_amount' => 300,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $unpaid = $this->invoiceFor($borrower, Invoice::STATUS_UNPAID);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])->assertOk();

        $this->assertSoftDeleted($borrower);
        $this->assertSoftDeleted($subscription);
        $this->assertSoftDeleted($unpaid);
        $this->assertSoftDeleted($login);
    }

    #[Test]
    public function the_host_admin_gets_the_same_rule_for_a_partners_client(): void
    {
        $paid = $this->makeBorrower($this->partner, ['first_name' => 'Reagan', 'last_name' => 'Hardin']);
        $unpaid = $this->makeBorrower($this->partner);
        $this->invoiceFor($paid, Invoice::STATUS_PAID);
        $this->invoiceFor($unpaid, Invoice::STATUS_UNPAID);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$paid->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', $paid->notDeletableReason());
        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$unpaid->id]])->assertOk();

        $this->assertNotSoftDeleted($paid);
        $this->assertSoftDeleted($unpaid);
    }

    #[Test]
    public function deleting_needs_a_list_of_existing_ids(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', [])->assertStatus(422);
        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [999999]])->assertStatus(422);
    }

    #[Test]
    public function the_assignable_users_list_holds_only_the_partners_own_users(): void
    {
        // The list is borrower logins (and users with no entity), not staff.
        $mine = $this->makeBorrowerUser($this->partner);
        $theirs = $this->makeBorrowerUser($this->makeCertifiedPartner());
        $this->actingAsUser($this->partnerAdmin);

        $ids = collect($this->postJson('/api/v1/common/borrowers-get-all-users')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($mine->id, $ids->all());
        $this->assertNotContains($theirs->id, $ids->all());
    }

    /**
     * Defect: the list scopes by the caller's partner and skips the scope for
     * the host - so a host's borrower calling it got the name, email and phone
     * of every user on the platform.
     */
    #[Test]
    public function a_borrower_gets_no_users_from_the_assignable_users_list(): void
    {
        $this->makeStaffUser($this->host);
        $this->makeBorrowerUser($this->host);
        $this->actingAsUser($this->makeBorrowerUser($this->host));

        // Turned away at the partner-portal gate; the service also returns an
        // empty list for a borrower should one ever reach it.
        $this->postJson('/api/v1/common/borrowers-get-all-users')->assertForbidden();
        $this->assertCount(0, app(\App\Services\FCH\Common\Borrower\BorrowerService::class)->getAllUsers());
    }

    #[Test]
    public function the_tier_list_is_available_to_the_client_screens(): void
    {
        Tier::create(['name' => 'Tier 0', 'title' => 'Foundation', 'code' => 'T0']);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-tiers')->assertOk()->assertJsonPath('status', true);
    }
}
