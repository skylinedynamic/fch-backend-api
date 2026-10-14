<?php

namespace Tests\Feature\Funding;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Readiness factors for a funding option that does not exist. The service's
 * findOrFail() was caught by the controller's generic catch and answered 500
 * "Failed to evaluate readiness factors"; an unknown id is a 404. Found by the
 * borrower portal's E2E (sidebar/funding/funding-review.spec.ts › an unknown
 * funding id shows "Funding Detail Not Found").
 */
class ReadinessFactorsUnknownFundingTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'business_name' => 'Alpha Tech Solutions',
            'email' => 'borrower-' . uniqid() . '@example.com',
            'partner_id' => $partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->borrower = Borrower::findOrFail($borrowerId);

        $user = User::create([
            'name' => 'Michael Chen',
            'email' => $this->borrower->email,
            'password' => bcrypt('secret'),
            'partner_id' => $partnerId,
            'entity_type' => Borrower::class,
            'entity_id' => $borrowerId,
            'status' => User::STATUS['active'],
        ]);

        Passport::actingAs($user, [], 'api');
    }

    #[Test]
    public function an_unknown_funding_id_is_not_found_rather_than_a_server_error(): void
    {
        $this->getJson('/api/v1/common/funding-qualification/999999/readiness-factors')
            ->assertNotFound()
            ->assertJsonPath('status', false);
    }

    #[Test]
    public function an_unknown_funding_id_for_a_client_is_not_found_either(): void
    {
        // The client route is the partner portal's; a borrower's token is
        // refused there (RejectBorrowersFromPartnerPortal), so ask as the partner.
        $partnerUser = User::create([
            'name' => 'Pat Partner',
            'email' => 'partner-user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'partner_id' => $this->borrower->partner_id,
            'entity_type' => Partner::class,
            'entity_id' => $this->borrower->partner_id,
            'status' => User::STATUS['active'],
        ]);
        Passport::actingAs($partnerUser, [], 'api');

        $this->getJson("/api/v1/common/funding-qualification/client-readiness-factors/{$this->borrower->id}/999999")
            ->assertNotFound()
            ->assertJsonPath('status', false);
    }
}
