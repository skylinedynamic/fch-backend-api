<?php

namespace Tests\Feature\Lead;

use App\Http\Controllers\Api\V1\FCH\Common\Lead\LeadController;
use App\Models\Borrower\Borrower;
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
 * The host partner's view of Leads: the host and its staff see every
 * partner's leads, a White-Label or Certified partner only its own. A host
 * converting another partner's lead does so on that partner's behalf - the
 * borrower always belongs to the lead's own partner, never the host.
 */
#[CoversClass(LeadController::class)]
class HostLeadAccessTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $whiteLabel;
    private Partner $certified;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->host = $this->makeHostPartner(['company_name' => 'Host Co']);
        $this->whiteLabel = $this->makeWhiteLabelPartner(['company_name' => 'WLP Co']);
        $this->certified = $this->makeCertifiedPartner(['company_name' => 'Certified Co']);
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
    }

    private function leadOf(Partner $partner, array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'partner_id' => $partner->id,
            'company_name' => 'Lead Co ' . uniqid(),
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'phone_number' => '(555) 123-4567',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
        ], $attributes));
    }

    private function freeTrialConversion(array $overrides = []): array
    {
        return array_merge([
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->toDateString(),
        ], $overrides);
    }

    private function listedIds(User $user): array
    {
        $this->actingAsUser($user);

        return collect($this->postJson('/api/v1/common/leads/fetch-leads')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    // ---- Visibility --------------------------------------------------------

    #[Test]
    public function the_host_sees_every_partners_leads(): void
    {
        $ids = collect([
            $this->leadOf($this->host),
            $this->leadOf($this->whiteLabel),
            $this->leadOf($this->certified),
        ])->pluck('id')->sort()->values()->all();

        $this->assertSame($ids, $this->listedIds($this->hostAdmin));
        $this->assertSame($ids, collect($this->getJson('/api/v1/common/leads')->assertOk()->json('data'))->pluck('id')->sort()->values()->all());
        $this->postJson('/api/v1/common/leads/fetch-leads')->assertJsonPath('statistics.total_leads', 3);
    }

    #[Test]
    public function the_host_narrows_the_list_to_one_partners_leads(): void
    {
        $wlpLead = $this->leadOf($this->whiteLabel);
        $this->leadOf($this->certified);
        $this->leadOf($this->host);
        $this->actingAsUser($this->hostAdmin);

        $response = $this->postJson('/api/v1/common/leads/fetch-leads', ['partner_id' => $this->whiteLabel->id])->assertOk();

        $this->assertSame([$wlpLead->id], collect($response->json('data'))->pluck('id')->all());
        $response->assertJsonPath('statistics.total_leads', 1);
    }

    #[Test]
    public function a_partner_cannot_use_the_partner_filter_to_reach_another_partners_leads(): void
    {
        $this->leadOf($this->certified);
        $this->actingAsUser($this->makePartnerAdmin($this->whiteLabel));

        $this->postJson('/api/v1/common/leads/fetch-leads', ['partner_id' => $this->certified->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_hosts_staff_see_every_partners_leads_too(): void
    {
        $ids = collect([$this->leadOf($this->host), $this->leadOf($this->whiteLabel)])->pluck('id')->sort()->values()->all();

        $this->assertSame($ids, $this->listedIds($this->makeStaffUser($this->host)));
    }

    #[Test]
    public function white_label_and_certified_partners_see_only_their_own_leads(): void
    {
        $this->leadOf($this->host);
        $wlpLead = $this->leadOf($this->whiteLabel);
        $certifiedLead = $this->leadOf($this->certified);

        $this->assertSame([$wlpLead->id], $this->listedIds($this->makePartnerAdmin($this->whiteLabel)));
        $this->assertSame([$certifiedLead->id], $this->listedIds($this->makePartnerAdmin($this->certified)));
        $this->assertSame([$certifiedLead->id], $this->listedIds($this->makeStaffUser($this->certified)));
    }

    #[Test]
    public function a_lead_shows_which_partner_owns_it(): void
    {
        $lead = $this->leadOf($this->whiteLabel);
        $this->actingAsUser($this->hostAdmin);

        $this->getJson("/api/v1/common/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.partner.id', $this->whiteLabel->id)
            ->assertJsonPath('data.partner.company_name', 'WLP Co');
    }

    #[Test]
    public function the_host_can_open_and_update_another_partners_lead(): void
    {
        $lead = $this->leadOf($this->certified);
        $this->actingAsUser($this->hostAdmin);

        $this->getJson("/api/v1/common/leads/{$lead->id}")->assertOk()->assertJsonPath('data.id', $lead->id);
        $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", [
            'contact_person' => 'Lee Lead', 'phone_number' => '(555) 000-0000', 'email' => 'lee@example.test', 'country' => 'US',
        ])->assertOk();

        $this->assertSame('lee@example.test', $lead->fresh()->email);
        $this->assertSame($this->certified->id, $lead->fresh()->partner_id, 'editing does not move the lead to the host');
    }

    #[Test]
    public function the_host_can_delete_another_partners_lead(): void
    {
        $lead = $this->leadOf($this->whiteLabel);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => [$lead->id]])->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertSoftDeleted($lead);
    }

    // ---- Conversion --------------------------------------------------------

    #[Test]
    public function another_partners_borrower_lead_tells_the_host_it_converts_on_that_partners_behalf(): void
    {
        $lead = $this->leadOf($this->whiteLabel);
        $this->actingAsUser($this->hostAdmin);

        $this->getJson("/api/v1/common/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.convert_on_behalf_of', ['id' => $this->whiteLabel->id, 'company_name' => 'WLP Co'])
            ->assertJsonMissingPath('data.convert_as_options');
        $this->postJson('/api/v1/common/leads/fetch-leads')
            ->assertJsonPath('data.0.convert_on_behalf_of.company_name', 'WLP Co');
    }

    #[Test]
    public function no_lead_converts_on_another_partners_behalf_but_the_hosts_borrower_lead_of_another_partner(): void
    {
        $hostLead = $this->leadOf($this->host);
        $partnerLead = $this->leadOf($this->whiteLabel, ['lead_type' => Lead::PARTNER_TYPE_LEAD]);
        $wlpLead = $this->leadOf($this->whiteLabel);

        $this->actingAsUser($this->hostAdmin);
        $this->getJson("/api/v1/common/leads/{$hostLead->id}")->assertJsonPath('data.convert_on_behalf_of', null);
        $this->getJson("/api/v1/common/leads/{$partnerLead->id}")->assertJsonPath('data.convert_on_behalf_of', null);

        $this->actingAsUser($this->makePartnerAdmin($this->whiteLabel));
        $this->getJson("/api/v1/common/leads/{$wlpLead->id}")->assertJsonPath('data.convert_on_behalf_of', null);
    }

    #[Test]
    public function the_host_converts_another_partners_lead_on_that_partners_behalf(): void
    {
        $lead = $this->leadOf($this->whiteLabel);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", $this->freeTrialConversion())->assertOk();

        $borrower = Borrower::where('email', $lead->email)->firstOrFail();
        $this->assertSame($this->whiteLabel->id, $borrower->partner_id);
        $this->assertSame($this->whiteLabel->id, $borrower->user->partner_id, 'the borrower login belongs to the lead partner too');
        $this->assertSame(Lead::BORROWER_TYPE_LEAD, $lead->fresh()->convert_to);
    }

    #[Test]
    public function the_hosts_staff_convert_another_partners_lead_on_that_partners_behalf_too(): void
    {
        $lead = $this->leadOf($this->certified);
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", $this->freeTrialConversion())->assertOk();

        $this->assertSame($this->certified->id, Borrower::where('email', $lead->email)->firstOrFail()->partner_id);
    }

    #[Test]
    public function the_host_cannot_take_another_partners_lead_as_its_own_borrower(): void
    {
        $lead = $this->leadOf($this->whiteLabel);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", $this->freeTrialConversion([
            'convert_as' => 'host',
            'partner_id' => $this->host->id,
        ]))->assertOk();

        $this->assertSame($this->whiteLabel->id, Borrower::where('email', $lead->email)->firstOrFail()->partner_id);
    }

    #[Test]
    public function the_hosts_own_lead_converts_under_the_host(): void
    {
        $lead = $this->leadOf($this->host);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", $this->freeTrialConversion())->assertOk();

        $this->assertSame($this->host->id, Borrower::where('email', $lead->email)->firstOrFail()->partner_id);
    }

    #[Test]
    public function a_partners_own_lead_converts_under_that_partner(): void
    {
        $lead = $this->leadOf($this->whiteLabel);
        $this->actingAsUser($this->makePartnerAdmin($this->whiteLabel));

        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", $this->freeTrialConversion())->assertOk();

        $this->assertSame($this->whiteLabel->id, Borrower::where('email', $lead->email)->firstOrFail()->partner_id);
    }
}
