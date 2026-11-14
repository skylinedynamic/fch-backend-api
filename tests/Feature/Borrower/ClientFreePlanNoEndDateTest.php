<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-026 follow-up. Decision (2026-11-15): a client's Limited Free Plan
 * has no end date. The client API no longer stores one (it was API-only - the
 * form has no such field), a lead converted to a free client no longer gets a
 * 30-day one, sign-in no longer refuses a client over one, and the dates
 * already saved are cleared. A partner's free trial keeps its end date.
 */
#[CoversClass(AuthService::class)]
class ClientFreePlanNoEndDateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $host = $this->makeHostPartner();
        AppConfiguration::create(['partner_id' => $host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        $this->partner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
    }

    private function newFreeClient(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'QA-TEST R3',
            'last_name' => 'FreeHost',
            'email' => 'free-' . uniqid() . '@example.test',
            'phone_number' => '(555) 234-5678',
            'country' => 'United States',
            'business_country' => 'United States',
            'is_free_trail' => true,
        ], $overrides);
    }

    #[Test]
    public function a_new_free_client_is_saved_without_an_end_date_even_if_one_is_sent(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/borrowers', $this->newFreeClient(['free_trail_end_date' => '2026-11-12']))
            ->assertCreated()->json('data.id');

        $this->assertNull(Borrower::find($id)->free_trail_end_date);
    }

    #[Test]
    public function an_edit_never_gives_a_client_an_end_date(): void
    {
        $client = $this->makeBorrower($this->partner, ['is_free_trail' => true]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/borrowers/{$client->id}", ['first_name' => 'Fresh', 'free_trail_end_date' => '2026-11-10'])
            ->assertOk();

        $this->assertNull($client->fresh()->free_trail_end_date);
    }

    #[Test]
    public function a_lead_converted_to_a_free_client_gets_no_end_date(): void
    {
        $lead = Lead::create([
            'partner_id' => $this->partner->id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'phone_number' => '(555) 123-4567',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
        ]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", ['is_free_trail' => true])->assertOk();

        $this->assertNull(Borrower::where('email', $lead->email)->firstOrFail()->free_trail_end_date);
    }

    #[Test]
    public function a_client_with_an_old_end_date_still_signs_in(): void
    {
        config(['domains.reserved.base_reserved_borrower_portal' => 'app.fch.test']);
        $this->createPersonalAccessClient();
        $client = $this->makeBorrowerUser($this->partner, ['is_free_trail' => true, 'free_trail_end_date' => '2026-01-01']);

        $this->withHeader('Origin', 'https://app.fch.test')
            ->postJson('/api/v1/borrower/login', ['email' => $client->email, 'password' => 'password'])
            ->assertOk();
    }

    #[Test]
    public function the_saved_client_end_dates_are_cleared_and_partner_trials_kept(): void
    {
        $client = $this->makeBorrower($this->partner, ['is_free_trail' => true]);
        DB::table('borrowers')->where('id', $client->id)->update(['free_trail_end_date' => '2026-12-31']);
        $trialPartner = $this->makeCertifiedPartner(['is_free_trail' => true, 'free_trail_end_date' => '2026-12-31']);

        $migration = require database_path('migrations/2026_11_15_000002_clear_client_free_plan_end_dates.php');
        $migration->up();

        $this->assertNull($client->fresh()->free_trail_end_date);
        $this->assertSame('2026-12-31', $trialPartner->fresh()->free_trail_end_date->toDateString());
    }
}
