<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The borrower dashboard's top cards: the amounts come formatted for display
 * ($37.2M), alongside the raw numbers.
 */
class BorrowerDashboardAmountsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_requested_and_funded_totals_come_formatted_for_the_cards(): void
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host Owner',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);
        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);

        foreach ([[30000000, 4000000], [7249000, 326000]] as $i => [$requested, $funded]) {
            DB::table('funding_applications')->insert([
                'application_number' => 'FUND-TEST-' . $i,
                'borrower_id' => $borrower->id,
                'partner_id' => $partner->id,
                'status' => FundingApplication::PENDING,
                'requested_amount' => $requested,
                'total_funded_amount' => $funded,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Passport::actingAs(User::create([
            'name' => 'Odessa Mccall',
            'email' => 'login-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::BORROWER,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'partner_id' => $partner->id,
        ]), [], 'api');

        $this->getJson('/api/v1/borrower/dashboard')
            ->assertOk()
            ->assertJsonPath('data.states.total_requested_amount_formatted', '$37.2M')
            ->assertJsonPath('data.states.total_funded_amount_formatted', '$4.33M')
            // The raw numbers stay, for anything that calculates with them.
            ->assertJsonPath('data.states.total_requested_amount', fn ($value) => (float) $value === 37249000.0);
    }

    /**
     * Defect (BUG-027, CP-MYAPP-01): a funding application the borrower had
     * started (On Hold) was not counted on the dashboard - FUNDING APPLICATIONS
     * said 0 while the funding request said one had been started.
     */
    #[Test]
    public function an_application_started_on_hold_is_counted_and_listed(): void
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host Owner',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);
        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);
        $started = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-ON-HOLD',
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'status' => FundingApplication::ON_HOLD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs(User::create([
            'name' => 'Michael Chen',
            'email' => 'login-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::BORROWER,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'partner_id' => $partner->id,
        ]), [], 'api');

        $this->getJson('/api/v1/borrower/dashboard')
            ->assertOk()
            ->assertJsonPath('data.states.funding_applications', 1)
            ->assertJsonPath('data.latest_funding_applications.0.id', $started);
    }
}
