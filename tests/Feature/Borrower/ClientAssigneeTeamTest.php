<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-055: a client's "Assign to" is one of its own partner's team - the users
 * get-all-users lists for that partner (User::teamOf). The rule was only
 * `exists:users,id`, so the host could save one of its own team on a partner's
 * client. A client that already has an assignee outside the team keeps them,
 * so an older record still saves.
 */
#[CoversClass(BorrowerController::class)]
class ClientAssigneeTeamTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $hostAdmin;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        // The host is never held to the white-label setup check (see BorrowerRecordsApiTest).
        AppConfiguration::create(['partner_id' => $this->host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
    }

    private function newClient(array $overrides = []): array
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

    private function assertNotInTeam($response): void
    {
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assign_id' => __('borrower_validation.assign_id_not_in_team')]);
    }

    // ---- Creating a client -------------------------------------------------

    #[Test]
    public function the_host_assigns_its_own_client_to_one_of_its_team(): void
    {
        $hostStaff = $this->makeStaffUser($this->host);
        $this->actingAsUser($this->hostAdmin);

        $id = $this->postJson('/api/v1/common/borrowers', $this->newClient(['assign_id' => $hostStaff->id]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($hostStaff->id, Borrower::find($id)->assign_id);
    }

    #[Test]
    public function a_partner_cannot_assign_its_client_to_the_hosts_team(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->assertNotInTeam($this->postJson('/api/v1/common/borrowers', $this->newClient(['assign_id' => $this->hostAdmin->id])));
        $this->assertSame(0, Borrower::count());
    }

    #[Test]
    public function a_borrowers_login_is_never_an_assignee(): void
    {
        $borrowerLogin = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->assertNotInTeam($this->postJson('/api/v1/common/borrowers', $this->newClient(['assign_id' => $borrowerLogin->id])));
    }

    #[Test]
    public function the_host_creating_for_a_partner_assigns_from_that_partners_team(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->assertNotInTeam($this->postJson('/api/v1/common/borrowers', $this->newClient([
            'partner_id' => $this->partner->id,
            'assign_id' => $this->hostAdmin->id,
        ])));

        $this->postJson('/api/v1/common/borrowers', $this->newClient([
            'partner_id' => $this->partner->id,
            'assign_id' => $this->partnerAdmin->id,
        ]))->assertCreated();
    }

    #[Test]
    public function no_assignee_is_still_allowed(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers', $this->newClient(['assign_id' => null]))->assertCreated();
    }

    // ---- Editing a client --------------------------------------------------

    #[Test]
    public function the_host_reassigns_a_partners_client_within_that_partners_team(): void
    {
        $partnerStaff = $this->makeStaffUser($this->partner);
        $client = $this->makeBorrower($this->partner, ['assign_id' => $this->partnerAdmin->id]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/borrowers/{$client->id}", ['first_name' => 'Ada', 'assign_id' => $partnerStaff->id])->assertOk();

        $this->assertSame($partnerStaff->id, $client->fresh()->assign_id);
    }

    #[Test]
    public function the_host_cannot_assign_a_partners_client_to_its_own_team(): void
    {
        $client = $this->makeBorrower($this->partner, ['assign_id' => $this->partnerAdmin->id]);
        $this->actingAsUser($this->hostAdmin);

        $this->assertNotInTeam($this->postJson("/api/v1/common/borrowers/{$client->id}", ['first_name' => 'Ada', 'assign_id' => $this->hostAdmin->id]));

        $this->assertSame($this->partnerAdmin->id, $client->fresh()->assign_id);
    }

    #[Test]
    public function a_client_keeps_an_assignee_from_before_the_rule(): void
    {
        // Saved before the rule existed: a host user on a partner's client.
        $client = $this->makeBorrower($this->partner, ['assign_id' => $this->hostAdmin->id]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/borrowers/{$client->id}", ['first_name' => 'Renamed', 'assign_id' => $this->hostAdmin->id])->assertOk();

        $this->assertSame('Renamed', $client->fresh()->first_name);
        $this->assertSame($this->hostAdmin->id, $client->fresh()->assign_id);
    }
}
