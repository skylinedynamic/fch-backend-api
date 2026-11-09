<?php

namespace Tests\Feature\Lead;

use App\Http\Controllers\Api\V1\FCH\Common\CommonController;
use App\Http\Controllers\Api\V1\FCH\Common\Lead\LeadController;
use App\Models\Employee\Employee;
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
 * A lead's owner (salesperson_id) is one of its own partner's team - the users
 * get-all-users lists for that partner. The host working another partner's
 * lead picks from that partner's team, never its own. And a non-host
 * partner's lead is always a Borrower lead.
 */
#[CoversClass(LeadController::class)]
#[CoversClass(CommonController::class)]
class LeadOwnerTeamTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $wlp;
    private User $hostAdmin;
    private User $wlpAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->host = $this->makeHostPartner();
        $this->wlp = $this->makeWhiteLabelPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->wlpAdmin = $this->makePartnerAdmin($this->wlp);
    }

    private function leadOf(Partner $partner, array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'partner_id' => $partner->id,
            'company_name' => 'Lead Co ' . uniqid(),
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
        ], $attributes));
    }

    private function leadDetails(array $overrides = []): array
    {
        return array_merge([
            'probability' => 'High',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'company_name' => 'Fresh Lead Co',
            'contact_person' => 'Jane Doe',
            'lead_source' => 'Website',
        ], $overrides);
    }

    private function teamIds(array $payload = []): array
    {
        return collect($this->postJson('/api/v1/common/get-all-users', $payload)->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    // ---- The team lookup ---------------------------------------------------

    #[Test]
    public function the_host_gets_another_partners_team_by_naming_it(): void
    {
        $wlpStaff = $this->makeStaffUser($this->wlp);
        $this->makeBorrowerUser($this->wlp);
        $this->makeStaffUser($this->host);
        $this->actingAsUser($this->hostAdmin);

        $this->assertSame(collect([$this->wlpAdmin->id, $wlpStaff->id])->sort()->values()->all(), $this->teamIds(['partner_id' => $this->wlp->id]));
    }

    /**
     * Defect: R2 BUG-051 - an employee set to Inactive (or Suspended) was still
     * offered in the client "Assign to" list. A client already assigned to them
     * keeps the name on screen: keep_ids brings that one user back, flagged
     * inactive. The employee's job status does not change the login's status,
     * so active_only has to look at both.
     */
    #[Test]
    public function inactive_and_suspended_employees_are_not_offered_unless_already_assigned(): void
    {
        $active = $this->employeeLogin($this->host, Employee::STATUS_ACTIVE);
        $inactive = $this->employeeLogin($this->host, Employee::STATUS_INACTIVE);
        $suspended = $this->employeeLogin($this->host, Employee::STATUS_SUSPENDED);
        $this->actingAsUser($this->hostAdmin);

        $this->assertSame(collect([$this->hostAdmin->id, $active->id])->sort()->values()->all(), $this->teamIds(['active_only' => true]));

        $users = collect($this->postJson('/api/v1/common/get-all-users', ['active_only' => true, 'keep_ids' => [$inactive->id]])->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([$this->hostAdmin->id, $active->id, $inactive->id], $users->keys()->sort()->values()->all());
        $this->assertTrue($users[$inactive->id]['is_inactive']);
        $this->assertFalse($users[$active->id]['is_inactive']);
        $this->assertFalse($users->has($suspended->id));

        // Naming someone outside the team brings nobody in.
        $this->assertSame(collect([$this->hostAdmin->id, $active->id])->sort()->values()->all(), $this->teamIds(['active_only' => true, 'keep_ids' => [$this->wlpAdmin->id]]));

        // List filters (no active_only) still get everyone, flagged.
        $everyone = collect($this->postJson('/api/v1/common/get-all-users')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(collect([$this->hostAdmin->id, $active->id, $inactive->id, $suspended->id])->sort()->values()->all(), $everyone->keys()->sort()->values()->all());
        $this->assertTrue($everyone[$suspended->id]['is_inactive']);
    }

    private function employeeLogin(Partner $partner, string $jobStatus): User
    {
        $employee = Employee::create([
            'partner_id' => $partner->id,
            'first_name' => 'Staff',
            'last_name' => $jobStatus,
            'email' => 'staff-' . uniqid() . '@example.test',
            'job_status' => $jobStatus,
        ]);

        return $this->makeUser([
            'name' => "Staff {$jobStatus}",
            'email' => $employee->email,
            'user_type' => $partner->is_host ? User::ADMIN : User::PARTNER,
            'partner_id' => $partner->id,
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
        ]);
    }

    #[Test]
    public function without_a_partner_the_host_still_gets_its_own_team(): void
    {
        $hostStaff = $this->makeStaffUser($this->host);
        $this->makeStaffUser($this->wlp);
        $this->actingAsUser($this->hostAdmin);

        $this->assertSame(collect([$this->hostAdmin->id, $hostStaff->id])->sort()->values()->all(), $this->teamIds());
    }

    #[Test]
    public function a_partner_naming_another_partner_still_gets_only_its_own_team(): void
    {
        $certified = $this->makeCertifiedPartner();
        $this->makePartnerAdmin($certified);
        $this->actingAsUser($this->wlpAdmin);

        $this->assertSame([$this->wlpAdmin->id], $this->teamIds(['partner_id' => $certified->id]));
        $this->assertSame([$this->wlpAdmin->id], $this->teamIds(['partner_id' => $this->host->id]));
    }

    // ---- The owner a lead may have -----------------------------------------

    #[Test]
    public function the_host_keeps_or_reassigns_another_partners_lead_within_that_partners_team(): void
    {
        $wlpStaff = $this->makeStaffUser($this->wlp);
        $lead = $this->leadOf($this->wlp, ['salesperson_id' => $this->wlpAdmin->id]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails(['salesperson_id' => $this->wlpAdmin->id]))->assertOk();
        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails(['salesperson_id' => $wlpStaff->id]))->assertOk();

        $this->assertSame($wlpStaff->id, $lead->fresh()->salesperson_id);
    }

    #[Test]
    public function the_host_cannot_make_one_of_its_own_team_the_owner_of_another_partners_lead(): void
    {
        $lead = $this->leadOf($this->wlp, ['salesperson_id' => $this->wlpAdmin->id]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails(['salesperson_id' => $this->hostAdmin->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['salesperson_id' => __('lead.salesperson_not_in_team')]);

        $this->assertSame($this->wlpAdmin->id, $lead->fresh()->salesperson_id);
    }

    #[Test]
    public function a_partner_cannot_make_another_partners_user_or_a_borrower_its_leads_owner(): void
    {
        $borrowerUser = $this->makeBorrowerUser($this->wlp);
        $this->actingAsUser($this->wlpAdmin);

        $this->postJson('/api/v1/common/leads/details', $this->leadDetails(['salesperson_id' => $this->hostAdmin->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['salesperson_id']);
        $this->postJson('/api/v1/common/leads/details', $this->leadDetails(['salesperson_id' => $borrowerUser->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['salesperson_id']);

        $this->postJson('/api/v1/common/leads/details', $this->leadDetails(['salesperson_id' => $this->wlpAdmin->id]))->assertCreated();
    }

    #[Test]
    public function a_lead_whose_owner_is_already_outside_its_team_still_saves_unchanged(): void
    {
        $lead = $this->leadOf($this->wlp, ['salesperson_id' => $this->hostAdmin->id]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails([
            'salesperson_id' => $this->hostAdmin->id,
            'company_name' => 'Renamed Co',
        ]))->assertOk();

        $this->assertSame('Renamed Co', $lead->fresh()->company_name);
    }

    #[Test]
    public function a_lead_may_have_no_owner(): void
    {
        $lead = $this->leadOf($this->wlp);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails(['salesperson_id' => null]))->assertOk();
    }

    // ---- Lead type ---------------------------------------------------------

    #[Test]
    public function a_non_host_partners_lead_is_always_a_borrower_lead(): void
    {
        $lead = $this->leadOf($this->wlp);

        $this->actingAsUser($this->wlpAdmin);
        $this->postJson('/api/v1/common/leads/details', $this->leadDetails(['lead_type' => Lead::PARTNER_TYPE_LEAD]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lead_type' => __('lead.lead_type_borrower_only')]);

        $this->actingAsUser($this->hostAdmin);
        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails(['lead_type' => Lead::PARTNER_TYPE_LEAD]))
            ->assertStatus(422)->assertJsonValidationErrors(['lead_type']);

        $this->assertSame(Lead::BORROWER_TYPE_LEAD, $lead->fresh()->lead_type);
    }

    #[Test]
    public function the_hosts_own_lead_may_be_either_type(): void
    {
        $lead = $this->leadOf($this->host);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/leads/details', $this->leadDetails(['lead_type' => Lead::PARTNER_TYPE_LEAD]))->assertCreated();
        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails(['lead_type' => Lead::PARTNER_TYPE_LEAD]))->assertOk();

        $this->assertSame(Lead::PARTNER_TYPE_LEAD, $lead->fresh()->lead_type);
    }
}
