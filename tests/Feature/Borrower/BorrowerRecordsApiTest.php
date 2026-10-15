<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\CreditBuilder\Tier;
use App\Models\Setting\EmailConfiguration;
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
        // The host is a white-label partner type too, so it passes the same
        // branding/email setup check any white-label partner does.
        AppConfiguration::create(['partner_id' => $this->host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        EmailConfiguration::create(['partner_id' => $this->host->id, 'key' => 'is_active', 'value' => '1']);
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
