<?php

namespace Tests\Feature\Lead;

use App\Http\Controllers\Api\V1\FCH\Common\Lead\LeadController;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A converted lead (convert_to set) already became a borrower or a partner of
 * its type, so its Lead Type is read-only; its other details still save.
 */
#[CoversClass(LeadController::class)]
class ConvertedLeadTypeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
    }

    private function hostLead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'partner_id' => $this->host->id,
            'company_name' => 'Lead Co ' . uniqid(),
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => Lead::PARTNER_TYPE_LEAD,
            'status' => Lead::STATUS_LEAD_COMPLETED,
            'probability' => 'High',
        ], $attributes));
    }

    private function leadDetails(array $overrides = []): array
    {
        return array_merge([
            'probability' => 'High',
            'lead_type' => Lead::PARTNER_TYPE_LEAD,
            'status' => Lead::STATUS_LEAD_COMPLETED,
            'company_name' => 'Renamed Co',
            'contact_person' => 'Jane Doe',
            'lead_source' => 'Website',
        ], $overrides);
    }

    #[Test]
    public function a_converted_leads_type_cannot_be_changed(): void
    {
        $lead = $this->hostLead(['convert_to' => Lead::PARTNER_TYPE_LEAD]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails(['lead_type' => Lead::BORROWER_TYPE_LEAD]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lead_type' => __('lead.lead_type_locked')]);

        $this->assertSame(Lead::PARTNER_TYPE_LEAD, $lead->fresh()->lead_type);
    }

    #[Test]
    public function a_converted_leads_other_details_still_save_with_its_type_unchanged(): void
    {
        $lead = $this->hostLead(['convert_to' => Lead::PARTNER_TYPE_LEAD]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails())->assertOk();

        $this->assertSame('Renamed Co', $lead->fresh()->company_name);
        $this->assertSame(Lead::PARTNER_TYPE_LEAD, $lead->fresh()->lead_type);
    }

    #[Test]
    public function a_lead_not_yet_converted_may_still_change_type(): void
    {
        $lead = $this->hostLead(['status' => 'New Lead']);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails(['lead_type' => Lead::BORROWER_TYPE_LEAD]))->assertOk();

        $this->assertSame(Lead::BORROWER_TYPE_LEAD, $lead->fresh()->lead_type);
    }
}
