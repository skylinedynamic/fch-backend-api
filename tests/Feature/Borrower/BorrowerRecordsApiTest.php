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

    /**
     * BUG-017 (Add Client): an empty form - as the admin posts it, an
     * enrollment with no plan - gets every required error in one response,
     * the enrollment plan included.
     */
    #[Test]
    public function an_empty_client_form_gets_every_required_error_at_once(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        // Exactly what the admin's Add Client form posts when Save is clicked on an empty form.
        $emptyForm = [
            'first_name' => '', 'last_name' => '', 'date_of_birth' => '', 'phone_number' => '', 'country_code' => '+1', 'email' => '',
            'business_website' => '', 'business_name' => '', 'business_ein' => '', 'business_state_file_number' => '',
            'business_duns_number' => '', 'business_address' => '', 'business_country' => 'United States', 'business_country_code' => '+1',
            'business_state' => '', 'business_city' => '', 'business_other_city' => '', 'business_postal_code' => '', 'business_phone' => '',
            'business_entity_type' => '', 'business_industry_type' => '', 'business_start_date' => '', 'business_last_4_ssn' => '',
            'home_address' => '', 'country' => 'United States', 'state' => '', 'city' => '', 'other_city' => '', 'postal_code' => '',
            'is_free_trail' => false, 'free_trail_end_date' => '', 'status' => 'OnBoarding', 'notes' => '', 'fico_score' => '',
            'paydex_score' => '', 'bank_accounts' => [], 'entity_type' => 'Borrower', 'enrollment' => ['amount' => 0],
        ];

        $this->postJson('/api/v1/common/borrowers', $emptyForm)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'phone_number', 'enrollment.plan_id']);
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

    /**
     * Defect: R2 BUG-079 - the partner owner changed client 162's phone, then a
     * staff user whose page was opened earlier saved a new home address: the
     * whole form went back, and the phone silently reverted. A save from a page
     * showing older client details is refused (409); a change in another
     * section (business financials) does not make the form stale.
     */
    #[Test]
    public function a_client_info_save_from_an_older_page_is_refused(): void
    {
        $borrower = $this->makeBorrower($this->partner, ['phone_number' => '5555550802', 'home_address' => '1 Old St']);
        $staff = $this->makeStaffUser($this->partner);

        // Both open the client.
        $this->actingAsUser($this->partnerAdmin);
        $loaded = $this->getJson("/api/v1/common/borrowers/{$borrower->id}")->assertOk()->json('data.client_info_version');
        $this->assertNotEmpty($loaded);

        // The owner saves the phone; the response carries the new version.
        $ownerVersion = $this->postJson("/api/v1/common/borrowers/{$borrower->id}", ['first_name' => $borrower->first_name, 'phone_number' => '5555550812', 'expected_version' => $loaded])
            ->assertOk()->json('data.client_info_version');
        $this->assertNotSame($loaded, $ownerVersion);

        // The staff page still holds the old copy.
        $this->actingAsUser($staff);
        $this->postJson("/api/v1/common/borrowers/{$borrower->id}", ['first_name' => $borrower->first_name, 'phone_number' => '5555550802', 'home_address' => '2 New St', 'expected_version' => $loaded])
            ->assertStatus(409)
            ->assertJsonPath('message', __('borrower.changed_elsewhere'));
        $this->assertSame('5555550812', $borrower->fresh()->phone_number);
        $this->assertSame('1 Old St', $borrower->fresh()->home_address);

        // Another section's save leaves the owner's copy current.
        $this->actingAsUser($this->partnerAdmin);
        $this->postJson("/api/v1/common/borrowers/{$borrower->id}/business-financials", ['monthly_revenue' => 42000])->assertOk();
        $this->postJson("/api/v1/common/borrowers/{$borrower->id}", ['first_name' => $borrower->first_name, 'home_address' => '3 Owner St', 'expected_version' => $ownerVersion])->assertOk();
        $this->assertSame('3 Owner St', $borrower->fresh()->home_address);
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

    /**
     * BUG-054: with "Active Only" (archived = '') the owner's client list must
     * not show an archived client. Sent the way the Clients screen sends it.
     */
    #[Test]
    public function an_archived_client_is_left_out_of_the_owners_active_only_list(): void
    {
        $kept = $this->makeBorrower($this->partner);
        $archived = $this->makeBorrower($this->partner);
        $owner = $this->grant($this->makePartnerAdmin($this->host), ['update clients']);
        $this->actingAsUser($owner);

        $this->postJson('/api/v1/common/borrowers/archive', ['ids' => [$archived->id]])->assertOk();

        $ids = collect($this->postJson('/api/v1/common/borrowers/fetch-all-borrowers', [
            'search' => '', 'partner_id' => '', 'assigned_user_id' => '', 'current_tier' => '',
            'status' => '', 'is_free_trail' => '', 'archived' => '',
            // The DataTable default column filters, all empty.
            'filters' => [
                'global' => ['value' => null, 'matchMode' => 'contains'],
                'full_name' => ['operator' => 'and', 'constraints' => [['value' => null, 'matchMode' => 'contains']]],
                'email' => ['operator' => 'and', 'constraints' => [['value' => null, 'matchMode' => 'contains']]],
                'partner_name' => ['operator' => 'and', 'constraints' => [['value' => null, 'matchMode' => 'contains']]],
                'status' => ['operator' => 'and', 'constraints' => [['value' => null, 'matchMode' => 'equals']]],
            ],
            'page' => 1, 'per_page' => 10, 'sort_field' => 'id', 'sort_order' => 'desc',
        ])->assertOk()->json('data'))->pluck('id');

        $this->assertContains($kept->id, $ids);
        $this->assertNotContains($archived->id, $ids);
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

    // ── A client with a pre-qualification or funding application is kept ──

    private const APPLICATIONS_REASON = "This client has a pre-qualification or funding application and cannot be deleted. Please change the client's status instead.";

    private function preQualificationFor(Borrower $borrower): void
    {
        \Illuminate\Support\Facades\DB::table('pre_qualification_requests')->insert([
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'status' => \App\Models\Borrower\PreQualificationRequest::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fundingApplicationFor(Borrower $borrower): void
    {
        \Illuminate\Support\Facades\DB::table('funding_applications')->insert([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'status' => \App\Models\Borrower\FundingApplication::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function applicationsAndInvoices(): array
    {
        return [
            'free client, pre-qualification, no invoice' => ['pre-qualification', null],
            'free client, funding application, no invoice' => ['funding application', null],
            'pre-qualification, unpaid invoice' => ['pre-qualification', Invoice::STATUS_UNPAID],
            'funding application, unpaid invoice' => ['funding application', Invoice::STATUS_UNPAID],
        ];
    }

    /**
     * Defect: only a paid invoice kept a client, so a free client (no paid
     * invoice) who had applied - a pre-qualification or a funding application -
     * could be deleted, taking that application with them.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('applicationsAndInvoices')]
    public function a_client_who_has_applied_is_kept_even_with_no_paid_invoice(string $application, ?string $invoiceStatus): void
    {
        $borrower = $this->makeBorrower($this->partner, ['is_free_trail' => true]);
        $application === 'pre-qualification' ? $this->preQualificationFor($borrower) : $this->fundingApplicationFor($borrower);
        if ($invoiceStatus) {
            $this->invoiceFor($borrower, $invoiceStatus);
        }
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/check-deletable', ['ids' => [$borrower->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false)
            ->assertJsonPath('data.0.reason', self::APPLICATIONS_REASON)
            ->assertJsonPath('data.0.reason_code', 'applications');

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', self::APPLICATIONS_REASON)
            ->assertJsonPath('errors.blocked.0.id', $borrower->id)
            ->assertJsonPath('errors.blocked.0.reason_code', 'applications');

        $this->assertNotSoftDeleted($borrower);
    }

    /**
     * Defect: a free client converted from an embed form - where conversion
     * built their funding application - could be deleted once that application
     * row was gone, because only the funding_applications table was checked.
     * The conversion record still says a funding application was created.
     */
    #[Test]
    public function a_free_client_whose_embed_form_conversion_created_a_funding_application_is_kept(): void
    {
        $borrower = $this->makeBorrower($this->partner, ['is_free_trail' => true]);
        // Their own application row is gone; the stored id now belongs to someone else's.
        $someoneElse = $this->makeBorrower($this->partner);
        $reusedId = \Illuminate\Support\Facades\DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $someoneElse->id,
            'partner_id' => $this->partner->id,
            'status' => \App\Models\Borrower\FundingApplication::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('embedded_form_submissions')->insert([
            'submission_uid' => (string) \Illuminate\Support\Str::uuid(),
            'partner_id' => $this->partner->id,
            'first_name' => 'E2E',
            'last_name' => 'CertOne',
            'email' => 'e2e-' . uniqid() . '@example.test',
            'status' => \App\Models\EmbedForm\EmbedFormSubmission::STATUS_CONVERTED,
            'converted_borrower_id' => $borrower->id,
            'converted_funding_application_id' => $reusedId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/check-deletable', ['ids' => [$borrower->id]])
            ->assertJsonPath('data.0.is_deletable', false)
            ->assertJsonPath('data.0.reason_code', 'applications')
            ->assertJsonPath('data.0.reason', self::APPLICATIONS_REASON);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])->assertStatus(422);
        $this->assertNotSoftDeleted($borrower);
    }

    #[Test]
    public function a_paid_client_is_kept_for_the_paid_invoice_whatever_else_they_have(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $this->invoiceFor($borrower, Invoice::STATUS_PAID);
        $this->fundingApplicationFor($borrower);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/check-deletable', ['ids' => [$borrower->id]])
            ->assertJsonPath('data.0.is_deletable', false)
            ->assertJsonPath('data.0.reason_code', 'paid_invoice')
            ->assertJsonPath('data.0.reason', "This client has related records and cannot be deleted. Please change the client's status instead.");
    }

    #[Test]
    public function a_free_client_who_never_applied_is_still_deleted(): void
    {
        $borrower = $this->makeBorrower($this->partner, ['is_free_trail' => true]);
        $this->invoiceFor($borrower, Invoice::STATUS_UNPAID);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers/check-deletable', ['ids' => [$borrower->id]])
            ->assertJsonPath('data.0.is_deletable', true)
            ->assertJsonPath('data.0.reason_code', null);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])->assertOk();
        $this->assertSoftDeleted($borrower);
    }

    #[Test]
    public function in_a_bulk_delete_a_client_who_applied_is_named_and_nothing_is_deleted(): void
    {
        $applied = $this->makeBorrower($this->partner, ['first_name' => 'Kailash', 'last_name' => 'Bajaj', 'is_free_trail' => true]);
        $this->preQualificationFor($applied);
        $clean = $this->makeBorrower($this->partner, ['is_free_trail' => true]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$applied->id, $clean->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.blocked.0.name', 'Kailash Bajaj');

        $this->assertNotSoftDeleted($applied);
        $this->assertNotSoftDeleted($clean);
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
            ->assertJsonPath('errors.blocked', [['id' => $borrower->id, 'name' => 'Reagan Hardin', 'reason_code' => 'paid_invoice']]);

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
