<?php

namespace Tests\Feature\Setting;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerDocumentsChecklistController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\TenantConfigController;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Two partner-scoped settings: the partner's own custom domains
 * (/common/partner/tenant-config - what the domain resolver routes by) and a
 * client's document checklist (/common/borrower-documents-checklist/*).
 */
#[CoversClass(TenantConfigController::class)]
#[CoversClass(BorrowerDocumentsChecklistController::class)]
class TenantConfigAndChecklistTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner(['partner_domain' => 'portal.mine.test']);
    }

    private function domains(array $overrides = []): array
    {
        return array_merge([
            'active_custom_url' => true,
            'partner_domain' => 'portal.new-mine.test',
            'borrower_domain' => 'clients.new-mine.test',
        ], $overrides);
    }

    #[Test]
    public function a_partner_admin_reads_and_sets_its_own_domains(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson('/api/v1/common/partner/tenant-config')->assertOk();
        $this->postJson('/api/v1/common/partner/tenant-config', $this->domains())->assertOk();

        $this->assertSame('portal.new-mine.test', $this->partner->fresh()->partner_domain);
    }

    /**
     * Defect: the partner was taken from the user's entity_id - the partner's
     * id for a partner admin, but the *employee* id for a staff user, so a
     * staff member read and rewrote the domains of whichever partner happened
     * to have that id (a domain hijack of another tenant).
     */
    #[Test]
    public function a_staff_users_change_lands_on_their_own_partner_and_no_other(): void
    {
        // Line the ids up the way they collide in production: the staff
        // member's employee id equals another partner's id.
        $victim = $this->makeWhiteLabelPartner(['partner_domain' => 'portal.victim.test']);
        $staff = $this->makeStaffUser($this->partner);
        Employee::whereKey($staff->entity_id)->update(['id' => $victim->id]);
        $staff->update(['entity_id' => $victim->id]);
        $this->actingAsUser($staff->fresh());

        $this->postJson('/api/v1/common/partner/tenant-config', $this->domains(['partner_domain' => 'portal.hijack.test']))->assertOk();

        $this->assertSame('portal.victim.test', $victim->fresh()->partner_domain);
        $this->assertSame('portal.hijack.test', $this->partner->fresh()->partner_domain);
    }

    #[Test]
    public function a_partner_reads_and_saves_its_own_clients_checklist(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson("/api/v1/common/borrower-documents-checklist/{$borrower->id}")->assertOk();
        $this->postJson("/api/v1/common/borrower-documents-checklist/save/{$borrower->id}", ['document_type_ids' => []])
            ->assertSuccessful();
    }

    /**
     * Defect: both looked the borrower up with a bare find().
     */
    #[Test]
    public function another_partners_clients_checklist_is_out_of_reach(): void
    {
        $theirs = $this->makeBorrower($this->makeCertifiedPartner());
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson("/api/v1/common/borrower-documents-checklist/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/v1/common/borrower-documents-checklist/save/{$theirs->id}", ['document_type_ids' => []])->assertNotFound();
    }
}
