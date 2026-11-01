<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host's Partners screens: /admin/partners*. The admin route group only
 * admits user_type Admin - the host's own team - so white-label and certified
 * partners (user_type Partner, as are their staff) and borrowers never reach it.
 */
#[CoversClass(PartnerController::class)]
class PartnerAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    /** The same wording as a blocked client delete. */
    private const BLOCKED_ONE = "This partner has related records and cannot be deleted. Please change the partner's status instead.";

    private Partner $host;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
    }

    private function newPartnerPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Pat Partner',
            'company_name' => 'Pat Co ' . uniqid(),
            'email' => 'pat-' . uniqid() . '@example.test',
            'phone_number' => '(555) 234-5678',
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'country' => 'United States',
            'partner_domain' => 'pat' . uniqid(),
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->format('Y-m-d'),
        ], $overrides);
    }

    #[Test]
    public function a_partners_phone_zip_and_tax_id_are_checked_against_the_shared_formats(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners', $this->newPartnerPayload([
            'phone_number' => '(124) 982-7457', 'zip_code' => '1234', 'tax_id' => 'not an ein',
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'phone_number' => __('embed_form.phone_invalid'),
            'zip_code' => __('common.postal_code_invalid'),
            'tax_id' => __('common.business_ein_invalid'),
        ]);
    }

    /** The screen writes a Tax ID (an EIN) as XX-XXXXXXX; the nine digits are kept. */
    #[Test]
    public function a_partners_tax_id_is_kept_as_its_nine_digits(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $payload = $this->newPartnerPayload(['tax_id' => '46-7346734']);

        $this->postJson('/api/v1/admin/partners', $payload)->assertSuccessful();

        $this->assertDatabaseHas('partners', ['email' => $payload['email'], 'tax_id' => '467346734']);
    }

    public static function partnerRoutes(): array
    {
        return [
            'list' => ['POST', '/api/v1/admin/partners/fetch-partners'],
            'create' => ['POST', '/api/v1/admin/partners'],
            'details' => ['GET', '/api/v1/admin/partners-details/1'],
            'borrowers' => ['POST', '/api/v1/admin/partners/fetch-borrowers'],
            'delete' => ['POST', '/api/v1/admin/partners/multiple-delete'],
            'tenant config' => ['GET', '/api/v1/admin/partners/tenant-config/1'],
            'next id' => ['GET', '/api/v1/admin/partners/next-id'],
        ];
    }

    #[Test]
    #[DataProvider('partnerRoutes')]
    public function only_the_hosts_team_reaches_the_partner_screens(string $method, string $url): void
    {
        $this->json($method, $url)->assertUnauthorized();

        $wlPartner = $this->makeWhiteLabelPartner();
        foreach ([
            $this->makePartnerAdmin($wlPartner),
            $this->makeStaffUser($wlPartner),
            $this->makePartnerAdmin($this->makeCertifiedPartner()),
            $this->makeBorrowerUser($this->host),
        ] as $outsider) {
            $this->actingAsUser($outsider);
            $this->json($method, $url)->assertForbidden();
        }
    }

    #[Test]
    public function the_host_creates_a_partner_with_its_owner_login(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $payload = $this->newPartnerPayload();

        $id = $this->postJson('/api/v1/admin/partners', $payload)->assertCreated()->json('data.id');

        $partner = Partner::findOrFail($id);
        $this->assertFalse((bool) $partner->is_host);
        $this->assertSame(Partner::PARTNER_TYPE_CERTIFIED, $partner->partner_type);
        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'partner_id' => $id,
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
        ]);
    }

    #[Test]
    public function a_partner_needs_its_owner_contact_type_and_country(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners', ['is_free_trail' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'email', 'phone_number', 'partner_type', 'country']);
    }

    #[Test]
    public function the_partner_list_holds_every_partner_the_host_included(): void
    {
        // A host-exclusion filter exists in PartnerService::list() but is commented out.
        $partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->hostAdmin);

        $ids = collect($this->postJson('/api/v1/admin/partners/fetch-partners')->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$this->host->id, $partner->id], $ids->all());
    }

    /**
     * Defect: Total Partners (and the active count) counted the host's own
     * record, so the card showed one more partner than the host has.
     */
    #[Test]
    public function the_partner_cards_leave_out_the_host_and_deleted_or_archived_partners(): void
    {
        $this->makeCertifiedPartner();
        $this->makeWhiteLabelPartner(['status' => Partner::STATUS_INACTIVE]);
        $this->makeCertifiedPartner()->delete();
        $this->makeCertifiedPartner()->forceFill(['archived_at' => now()])->save();
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/fetch-partners')
            ->assertOk()
            ->assertJsonPath('statistics.total_partners', 2)
            ->assertJsonPath('statistics.active_partners', 1)
            ->assertJsonPath('statistics.inactive_partners', 1);
    }

    #[Test]
    public function the_host_opens_and_edits_a_partner(): void
    {
        $partner = $this->makeCertifiedPartner(['full_name' => 'Old Name']);
        $this->makePartnerAdmin($partner);
        $this->actingAsUser($this->hostAdmin);

        $this->getJson("/api/v1/admin/partners-details/{$partner->id}")->assertOk()->assertJsonPath('data.id', $partner->id);

        $this->putJson("/api/v1/admin/partners/{$partner->id}", $this->newPartnerPayload([
            'full_name' => 'New Name',
            'company_name' => $partner->company_name,
            'email' => $partner->email,
        ]))->assertOk()->assertJsonPath('data.full_name', 'New Name');
    }

    /**
     * R2 BUG-050: an optional field sent empty is cleared. (The admin form used
     * to leave an emptied field out of the request, so the old value stayed;
     * this pins the API side the form now relies on.)
     */
    #[Test]
    public function an_optional_partner_field_sent_empty_is_cleared(): void
    {
        $partner = $this->makeCertifiedPartner(['address_line_1' => '1 Old St', 'contact_person' => 'Old Contact']);
        $this->makePartnerAdmin($partner);
        $this->actingAsUser($this->hostAdmin);

        $this->putJson("/api/v1/admin/partners/{$partner->id}", $this->newPartnerPayload([
            'company_name' => $partner->company_name,
            'email' => $partner->email,
            'address_line_1' => '',
            'contact_person' => '',
        ]))->assertOk();

        $partner->refresh();
        $this->assertNull($partner->address_line_1);
        $this->assertNull($partner->contact_person);
    }

    /** A borrower's login that has been deleted, still holding its email. */
    private function deletedBorrowerLogin(string $email): User
    {
        $login = $this->makeBorrowerUser($this->makeCertifiedPartner(), ['email' => $email], ['email' => $email]);
        $login->entity?->delete();
        $login->delete();

        return $login;
    }

    private function editPartnerEmail(Partner $partner, string $email)
    {
        return $this->putJson("/api/v1/admin/partners/{$partner->id}", $this->newPartnerPayload([
            'full_name' => $partner->full_name,
            'company_name' => $partner->company_name,
            'email' => $email,
        ]));
    }

    #[Test]
    public function a_partner_without_a_login_takes_an_email_a_deleted_borrower_used(): void
    {
        $partner = $this->makeCertifiedPartner();
        $gone = $this->deletedBorrowerLogin('reused@example.test');
        $this->actingAsUser($this->hostAdmin);

        $this->editPartnerEmail($partner, 'reused@example.test')->assertOk();

        $this->assertSame('reused@example.test', $partner->fresh()->email);
        $this->assertSoftDeleted($gone);
    }

    #[Test]
    public function a_partner_with_a_login_is_sent_to_confirm_an_email_a_deleted_borrower_used(): void
    {
        $partner = $this->makeCertifiedPartner();
        $login = $this->makePartnerAdmin($partner, [], ['email' => $partner->email]);
        $this->deletedBorrowerLogin('reused@example.test');
        $this->actingAsUser($this->hostAdmin);

        $this->editPartnerEmail($partner, 'reused@example.test')->assertOk();

        // A login's new email waits for confirmation from the new address.
        $this->assertSame('reused@example.test', $login->fresh()->pending_email);
    }

    #[Test]
    public function a_partner_still_cannot_take_an_email_an_active_login_uses(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->makeUser(['email' => 'in-use@example.test']);
        $this->actingAsUser($this->hostAdmin);

        $this->editPartnerEmail($partner, 'in-use@example.test')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertNotSame('in-use@example.test', $partner->fresh()->email);
    }

    #[Test]
    public function an_unknown_partner_is_not_found(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->getJson('/api/v1/admin/partners-details/999999')->assertNotFound();
        $this->getJson('/api/v1/admin/partners/tenant-config/999999')->assertNotFound();
    }

    #[Test]
    public function a_partners_borrowers_are_listed_for_the_host(): void
    {
        $partner = $this->makeCertifiedPartner();
        $borrower = $this->makeBorrower($partner);
        $this->makeBorrower($this->makeCertifiedPartner());
        $this->actingAsUser($this->hostAdmin);

        $ids = collect($this->postJson('/api/v1/admin/partners/fetch-borrowers', ['partner_id' => $partner->id])
            ->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$borrower->id], $ids->all());
    }

    #[Test]
    public function a_partner_with_no_history_is_deletable_and_is_deleted(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/check-deletable', ['ids' => [$partner->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', true);
        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])->assertOk();

        $this->assertSoftDeleted($partner);
    }

    #[Test]
    public function a_partner_whose_only_user_is_its_own_login_is_deletable(): void
    {
        $partner = $this->makeCertifiedPartner();
        $login = $this->makePartnerAdmin($partner);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/check-deletable', ['ids' => [$partner->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', true);
        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])->assertOk();

        $this->assertSoftDeleted($partner);
        $this->assertNull(User::find($login->id));
    }

    /**
     * Defect: a partner was deleted along with every borrower and user under
     * it. A partner that still has borrowers or users must not be deletable -
     * they have to be removed or moved first.
     */
    #[Test]
    public function a_partner_with_borrowers_cannot_be_deleted(): void
    {
        $partner = $this->makeCertifiedPartner();
        $borrower = $this->makeBorrower($partner);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/check-deletable', ['ids' => [$partner->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false)
            ->assertJsonPath('data.0.reason', self::BLOCKED_ONE);
        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', self::BLOCKED_ONE);

        $this->assertNotSoftDeleted($partner);
        $this->assertNotSoftDeleted($borrower);
    }

    #[Test]
    public function a_partner_with_users_cannot_be_deleted(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->makePartnerAdmin($partner);
        $staff = $this->makeStaffUser($partner);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/check-deletable', ['ids' => [$partner->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false)
            ->assertJsonPath('data.0.reason', self::BLOCKED_ONE);
        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', self::BLOCKED_ONE);

        $this->assertNotSoftDeleted($partner);
        $this->assertNotNull(User::find($staff->id));
    }

    /**
     * Defect: deleting a partner with a paid invoice was refused, but the
     * reason was thrown away and the portal showed only "Could not delete the
     * selected partner(s)." The user could not tell why or what to do instead.
     */
    #[Test]
    public function a_partner_with_a_paid_invoice_is_kept_and_the_user_is_told_why_and_what_to_do(): void
    {
        $partner = $this->makeCertifiedPartner(['full_name' => 'Reagan Hardin']);
        $paid = $this->invoiceFor($partner, Invoice::STATUS_PAID);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', self::BLOCKED_ONE)
            ->assertJsonPath('errors.blocked', [['id' => $partner->id, 'name' => 'Reagan Hardin']]);

        $this->assertNotSoftDeleted($partner);
        $this->assertNotSoftDeleted($paid);
    }

    #[Test]
    public function when_any_selected_partner_has_a_paid_invoice_none_are_deleted_and_each_blocked_one_is_named(): void
    {
        $free = $this->makeCertifiedPartner(['full_name' => 'Free Partner']);
        $first = $this->makeCertifiedPartner(['full_name' => 'Reagan Hardin']);
        $second = $this->makeCertifiedPartner(['full_name' => 'Jessamine Oconnor']);
        $this->invoiceFor($first, Invoice::STATUS_PAID);
        $this->invoiceFor($second, Invoice::STATUS_PAID);
        $this->actingAsUser($this->hostAdmin);

        $response = $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$free->id, $first->id, $second->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Some selected partners have related records and cannot be deleted: Reagan Hardin, Jessamine Oconnor. '
                . 'Please change their status instead, or unselect them to delete the others.');

        $this->assertSame(['Reagan Hardin', 'Jessamine Oconnor'], array_column($response->json('errors.blocked'), 'name'));
        $this->assertNotSoftDeleted($free);
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
    }

    /**
     * Defect: the deletable check looked at "Paid" only, while the delete kept
     * "Success" invoices as paid too - so a partner whose payment was recorded
     * as Success was deleted.
     */
    #[Test]
    public function a_successful_payment_counts_as_paid(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->invoiceFor($partner, Invoice::STATUS_SUCCESS);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/check-deletable', ['ids' => [$partner->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false);
        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])->assertStatus(422);

        $this->assertNotSoftDeleted($partner);
    }

    #[Test]
    public function the_deletable_check_gives_the_same_reason(): void
    {
        $partner = $this->makeCertifiedPartner(['full_name' => 'Reagan Hardin']);
        $this->invoiceFor($partner, Invoice::STATUS_PAID);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/check-deletable', ['ids' => [$partner->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false)
            ->assertJsonPath('data.0.name', 'Reagan Hardin')
            ->assertJsonPath('data.0.reason', self::BLOCKED_ONE);
    }

    /**
     * A partner with only unpaid invoices is deleted, and those invoices go
     * with it, so nothing is left for anyone to pay.
     */
    #[Test]
    public function a_partner_with_only_unpaid_invoices_is_deleted_together_with_those_invoices(): void
    {
        $partner = $this->makeCertifiedPartner();
        $unpaid = $this->invoiceFor($partner, Invoice::STATUS_UNPAID);
        $overdue = $this->invoiceFor($partner, Invoice::STATUS_OVERDUE);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])->assertOk();

        $this->assertSoftDeleted($partner);
        $this->assertSoftDeleted($unpaid);
        $this->assertSoftDeleted($overdue);
    }

    /**
     * Defect: selecting more than one partner and deleting failed with "Could
     * not delete the selected partner(s)." on stage and local - the delete
     * lazy-loaded each partner's logins, refused outside production once there
     * is more than one model.
     */
    #[Test]
    public function the_host_bulk_deletes_several_partners_with_only_unpaid_invoices(): void
    {
        // Real partners have their own login; deleting reads it for each one.
        $first = $this->makeCertifiedPartner();
        $second = $this->makeCertifiedPartner();
        $firstLogin = $this->makePartnerAdmin($first);
        $secondLogin = $this->makePartnerAdmin($second);
        $firstUnpaid = $this->invoiceFor($first, Invoice::STATUS_UNPAID);
        $secondUnpaid = $this->invoiceFor($second, Invoice::STATUS_PENDING);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('message', __('partner.success.deleted'));

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertSoftDeleted($firstUnpaid);
        $this->assertSoftDeleted($secondUnpaid);
        $this->assertNull(User::find($firstLogin->id));
        $this->assertNull(User::find($secondLogin->id));
    }

    /**
     * Defect: deleting any partner with a subscription failed - the delete
     * called Subscription::allPhases(), which went with the
     * subscription_phases table (dropped 2026-08-31).
     */
    #[Test]
    public function a_partner_with_a_subscription_and_only_unpaid_invoices_is_deleted(): void
    {
        $partner = $this->makeCertifiedPartner();
        $subscription = Subscription::create([
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 300,
            'total_subscription_amount' => 300,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $unpaid = $this->invoiceFor($partner, Invoice::STATUS_UNPAID);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])->assertOk();

        $this->assertSoftDeleted($partner);
        $this->assertSoftDeleted($subscription);
        $this->assertSoftDeleted($unpaid);
    }

    /**
     * Defect: a new partner with its own login, a subscription and only unpaid
     * invoices passed the check, then the delete failed with "Could not delete
     * the selected partner(s). Please try again." - deleting the login went
     * through User::safeDeleteWithRelations(), which still called the removed
     * Subscription::allPhases().
     */
    #[Test]
    public function a_partner_with_its_own_login_a_subscription_and_only_unpaid_invoices_is_deleted(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $login = $this->makePartnerAdmin($partner);
        $subscription = Subscription::create([
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 300,
            'total_subscription_amount' => 300,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $unpaid = $this->invoiceFor($partner, Invoice::STATUS_UNPAID);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/check-deletable', ['ids' => [$partner->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', true);
        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])
            ->assertOk()
            ->assertJsonPath('message', __('partner.success.deleted'));

        $this->assertSoftDeleted($partner);
        $this->assertSoftDeleted($subscription);
        $this->assertSoftDeleted($unpaid);
        $this->assertNull(User::find($login->id));
    }

    private function invoiceFor(Partner $partner, string $status): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'customer_name' => $partner->full_name,
            'email' => $partner->email,
            'payment_amount' => 100,
            'currency' => 'USD',
            'status' => $status,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    /**
     * Defect: delete and archive took any id, the host's own included - one
     * click on the wrong row took down the partner every host user, setting
     * and borrower hangs off.
     */
    #[Test]
    public function the_host_cannot_delete_or_archive_itself(): void
    {
        $this->actingAsUser($this->grant($this->hostAdmin, ['update partners']));

        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$this->host->id]]);
        $this->postJson('/api/v1/admin/partners/archive', ['ids' => [$this->host->id]]);

        $host = Partner::find($this->host->id);
        $this->assertNotNull($host);
        $this->assertNull($host->archived_at);

        $this->postJson('/api/v1/admin/partners/check-deletable', ['ids' => [$this->host->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false);
    }

    #[Test]
    public function archiving_needs_the_update_partners_permission(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->hostAdmin);
        $this->postJson('/api/v1/admin/partners/archive', ['ids' => [$partner->id]])->assertForbidden();

        $this->actingAsUser($this->grant($this->hostAdmin, ['update partners']));
        $this->postJson('/api/v1/admin/partners/archive', ['ids' => [$partner->id]])->assertOk();
        $this->assertNotNull($partner->fresh()->archived_at);

        $this->postJson('/api/v1/admin/partners/unarchive', ['ids' => [$partner->id]])->assertOk();
        $this->assertNull($partner->fresh()->archived_at);
    }

    #[Test]
    public function the_next_partner_id_is_offered_for_the_create_form(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->getJson('/api/v1/admin/partners/next-id')->assertOk()->assertJsonStructure(['data' => ['next_id']]);
    }

    #[Test]
    public function a_partners_profile_stats_and_tenant_config_load(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/admin/partners-profile-states-details-count/{$partner->id}")->assertOk();
        $this->getJson("/api/v1/admin/partners/tenant-config/{$partner->id}")->assertOk();
    }
}
