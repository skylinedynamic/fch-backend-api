<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerStatusAgentRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner profile's Status & Agent tab: GET/PUT
 * /admin/partners/{id}/status-agent, kept apart from the Partner information
 * update so neither tab's save is held up by the other's fields.
 *
 * Defect: the tab used to send the whole partner through PUT /admin/partners/{id},
 * so a status or agent change on partner 74 failed with "Enter a valid 10-digit
 * US phone number" - its saved phone, (185) 877-2814, is not a valid US number -
 * a field not even on that tab.
 */
#[CoversClass(PartnerController::class)]
#[CoversClass(UpdatePartnerStatusAgentRequest::class)]
class PartnerStatusAgentApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner());
    }

    private function url(Partner $partner): string
    {
        return "/api/v1/admin/partners/{$partner->id}/status-agent";
    }

    #[Test]
    public function the_tab_loads_the_partners_status_and_its_agents_contact_details(): void
    {
        $agent = $this->makeUser(['name' => 'Alex Agent', 'email' => 'alex@example.test', 'phone_number' => '(555) 234-5678']);
        $partner = $this->makeCertifiedPartner(['status' => Partner::STATUS_SUSPENDED, 'assign_to' => $agent->id]);
        $this->actingAsUser($this->hostAdmin);

        $this->getJson($this->url($partner))
            ->assertOk()
            ->assertJsonPath('data.id', $partner->id)
            ->assertJsonPath('data.status', Partner::STATUS_SUSPENDED)
            ->assertJsonPath('data.assign_to', $agent->id)
            ->assertJsonPath('data.agent.name', 'Alex Agent')
            ->assertJsonPath('data.agent.email', 'alex@example.test')
            ->assertJsonPath('data.agent.phone_number', '(555) 234-5678');
    }

    #[Test]
    public function a_partner_with_no_agent_loads_with_an_empty_agent(): void
    {
        $partner = $this->makeCertifiedPartner(['assign_to' => null]);
        $this->actingAsUser($this->hostAdmin);

        $this->getJson($this->url($partner))
            ->assertOk()
            ->assertJsonPath('data.assign_to', null)
            ->assertJsonPath('data.agent', null);
    }

    #[Test]
    public function status_and_agent_save_even_when_the_partners_saved_phone_is_not_valid(): void
    {
        $agent = $this->makeUser();
        $partner = $this->makeCertifiedPartner([
            'status' => Partner::STATUS_ACTIVE,
            'phone_number' => '(185) 877-2814',
            'contact_person_phone_number' => '(134) 187-5765',
        ]);
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($partner), [
            'status' => Partner::STATUS_SUSPENDED,
            'assign_to' => $agent->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Partner::STATUS_SUSPENDED)
            ->assertJsonPath('data.assign_to', $agent->id);

        $partner->refresh();
        $this->assertSame(Partner::STATUS_SUSPENDED, $partner->status);
        $this->assertSame($agent->id, (int) $partner->assign_to);
        // Nothing else on the partner is touched.
        $this->assertSame('(185) 877-2814', $partner->phone_number);
    }

    #[Test]
    public function an_agent_without_an_email_or_phone_can_be_picked(): void
    {
        $agent = $this->makeUser(['phone_number' => null]);
        $partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->hostAdmin);

        // The tab's read-only agent email/mobile boxes are not part of the save.
        $this->putJson($this->url($partner), [
            'status' => Partner::STATUS_ACTIVE,
            'assign_to' => $agent->id,
            'agentEmail' => '',
            'agentPhone' => '',
        ])->assertOk()->assertJsonPath('data.agent.phone_number', null);
    }

    #[Test]
    public function a_status_and_an_agent_must_be_picked(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($partner), ['status' => '', 'assign_to' => ''])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please select a status.')
            ->assertJsonPath('errors.status', 'Please select a status.')
            ->assertJsonPath('errors.assign_to', 'Please select an agent.');
    }

    #[Test]
    public function only_a_known_status_and_an_existing_user_are_accepted(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($partner), ['status' => 'Deleted', 'assign_to' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('errors.status', 'Please select a valid status.')
            ->assertJsonPath('errors.assign_to', 'The selected agent does not exist.');
    }

    #[Test]
    public function only_the_hosts_team_reaches_the_tab(): void
    {
        // No per-permission gate (decided 2026-11-08): the admin route group's
        // role check admits the host's team and turns everyone else away.
        $partner = $this->makeCertifiedPartner(['status' => Partner::STATUS_ACTIVE]);
        $this->actingAsUser($this->makePartnerAdmin($partner));

        $this->getJson($this->url($partner))->assertForbidden();
        $this->putJson($this->url($partner), [
            'status' => Partner::STATUS_INACTIVE,
            'assign_to' => $this->hostAdmin->id,
        ])->assertForbidden();

        $this->assertSame(Partner::STATUS_ACTIVE, $partner->fresh()->status);
    }

    #[Test]
    public function an_unknown_partner_is_not_found(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->getJson('/api/v1/admin/partners/999999/status-agent')->assertNotFound();
        $this->putJson('/api/v1/admin/partners/999999/status-agent', [
            'status' => Partner::STATUS_ACTIVE,
            'assign_to' => $this->hostAdmin->id,
        ])->assertNotFound();
    }

    #[Test]
    public function the_partner_information_save_leaves_status_and_agent_alone(): void
    {
        $agent = $this->makeUser();
        $partner = $this->makeWhiteLabelPartner(['status' => Partner::STATUS_ACTIVE, 'assign_to' => $agent->id]);
        $this->actingAsUser($this->hostAdmin);

        $this->putJson("/api/v1/admin/partners/{$partner->id}", [
            'full_name' => 'New Name',
            'status' => Partner::STATUS_INACTIVE,
            'assign_to' => $this->hostAdmin->id,
        ])->assertOk();

        $partner->refresh();
        $this->assertSame('New Name', $partner->full_name);
        $this->assertSame(Partner::STATUS_ACTIVE, $partner->status);
        $this->assertSame($agent->id, (int) $partner->assign_to);
    }
}
