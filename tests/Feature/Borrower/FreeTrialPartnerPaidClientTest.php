<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-142: a partner on a free trial could create a paid (One Time Payment)
 * client through Add Client - the client and both invoices were created. Only
 * the Convert Lead screen held it back, and the API never checked. A partner
 * on a free trial may now only add Limited Free Plan clients, whichever screen
 * the request comes from.
 */
class FreeTrialPartnerPaidClientTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PAID_VERSION_ONLY = 'Paid clients are only available in the Paid Version. Upgrade your plan to add a paid client, or add this client on the Limited Free Plan.';

    private Partner $host;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->plan = SubscriptionPlan::create([
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1,
            'status' => true,
            'total_amount' => 2497,
        ]);
    }

    private function trialPartnerAdmin(): User
    {
        $partner = $this->makeCertifiedPartner([
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(30)->toDateString(),
        ]);

        return $this->actingAsUser($this->makePartnerAdmin($partner));
    }

    private function client(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'QA-TEST',
            'last_name' => 'OPLEAD23 Paid',
            'email' => 'qa-' . uniqid() . '@example.test',
            'phone_number' => '(555) 234-5678',
            'country' => 'United States',
            'business_country' => 'United States',
        ], $overrides);
    }

    private function paid(): array
    {
        return $this->client([
            'is_free_trail' => false,
            'enrollment' => ['plan_id' => $this->plan->id, 'amount' => 2497, 'enrollment_type' => 'One Time Payment'],
        ]);
    }

    #[Test]
    public function a_partner_on_a_free_trial_cannot_add_a_paid_client(): void
    {
        $this->trialPartnerAdmin();
        $payload = $this->paid();

        $this->postJson('/api/v1/common/borrowers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_free_trail' => self::PAID_VERSION_ONLY]);

        $this->assertDatabaseMissing('borrowers', ['email' => $payload['email']]);
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function a_partner_on_a_free_trial_still_adds_a_limited_free_plan_client(): void
    {
        $this->trialPartnerAdmin();

        $this->postJson('/api/v1/common/borrowers', $this->client(['is_free_trail' => true]))
            ->assertCreated();
    }

    #[Test]
    public function a_partner_on_a_free_trial_cannot_move_a_free_client_onto_a_paid_plan(): void
    {
        $admin = $this->trialPartnerAdmin();
        $borrower = $this->makeBorrower(Partner::find($admin->partner_id), ['is_free_trail' => true]);

        $this->postJson("/api/v1/common/borrowers/{$borrower->id}", array_merge($this->paid(), ['email' => $borrower->email]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_free_trail' => self::PAID_VERSION_ONLY]);

        $this->assertTrue((bool) $borrower->fresh()->is_free_trail);
    }

    /** Convert Lead held this back on screen only; the API now says so too, as a 422. */
    #[Test]
    public function a_partner_on_a_free_trial_cannot_convert_a_lead_into_a_paid_client(): void
    {
        $admin = $this->trialPartnerAdmin();
        $lead = Lead::create([
            'partner_id' => $admin->partner_id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'phone_number' => '(555) 123-4567',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
        ]);

        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", [
            'is_free_trail' => false,
            'plan_id' => $this->plan->id,
            'enrollment_type' => 'One Time Payment',
            'enrollment_amount' => 2497,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_free_trail' => self::PAID_VERSION_ONLY]);

        $this->assertSame(0, Borrower::count());
        $this->assertSame(0, Invoice::count());
    }

    /** The Paid Version check is about the partner's own trial, not anyone else's. */
    #[Test]
    public function a_partner_not_on_a_free_trial_is_not_held_back(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner()));

        $this->postJson('/api/v1/common/borrowers', $this->paid())
            ->assertJsonMissingValidationErrors('is_free_trail');
    }
}
