<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Models\Partner\Partner;
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
            'phone_number' => '(555) 123-4567',
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'country' => 'United States',
            'partner_domain' => 'pat' . uniqid(),
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->format('Y-m-d'),
        ], $overrides);
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
            ->assertJsonPath('data.0.reason', __('partner.error.has_borrowers_or_users'));
        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])->assertStatus(400);

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
            ->assertJsonPath('data.0.reason', __('partner.error.has_borrowers_or_users'));
        $this->postJson('/api/v1/admin/partners/multiple-delete', ['ids' => [$partner->id]])->assertStatus(400);

        $this->assertNotSoftDeleted($partner);
        $this->assertNotNull(User::find($staff->id));
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
