<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Api\V1\Global\Website\Lead\LeadController;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The website contact page asks "What brings you here today?". The answer comes
 * as `interest` - the lead type itself, `Borrower` ("Explore becoming a client")
 * or `Partner` ("Explore partnership opportunities") - and is saved as the lead's type.
 * The home page form does not ask, so it sends none and stays a Borrower lead.
 */
#[CoversClass(LeadController::class)]
class WebsiteLeadInterestTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
    }

    private function contactForm(array $overrides = []): array
    {
        return array_merge([
            'contact_person' => 'Visitor Vee',
            'email' => 'vee-' . uniqid() . '@example.test',
        ], $overrides);
    }

    #[Test]
    public function a_visitor_exploring_becoming_a_client_is_a_borrower_lead(): void
    {
        $this->postJson('/api/v1/website/leads', $this->contactForm(['interest' => Lead::BORROWER_TYPE_LEAD]))
            ->assertOk();

        $this->assertSame(Lead::BORROWER_TYPE_LEAD, Lead::sole()->lead_type);
    }

    #[Test]
    public function a_visitor_exploring_partnership_is_a_partner_lead(): void
    {
        $this->postJson('/api/v1/website/leads', $this->contactForm(['interest' => Lead::PARTNER_TYPE_LEAD]))
            ->assertOk();

        $lead = Lead::sole();
        $this->assertSame(Lead::PARTNER_TYPE_LEAD, $lead->lead_type);
        $this->assertSame(Lead::LEAD_SOURCE_WEBSITE, $lead->lead_source);
        $this->assertSame($this->host->id, $lead->partner_id);
    }

    #[Test]
    public function a_lead_without_an_answer_stays_a_borrower_lead(): void
    {
        $this->postJson('/api/v1/website/leads', $this->contactForm())->assertOk();

        $this->assertSame(Lead::BORROWER_TYPE_LEAD, Lead::sole()->lead_type);
    }

    #[Test]
    public function an_answer_that_is_not_one_of_the_two_choices_is_refused(): void
    {
        foreach (['Explore partnership opportunities', 'partnership', 'partner', 'anything'] as $interest) {
            $this->postJson('/api/v1/website/leads', $this->contactForm(['interest' => $interest]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['interest']);
        }

        $this->assertSame(0, Lead::count());
    }

    #[Test]
    public function the_lead_type_cannot_be_set_directly_from_the_website(): void
    {
        $this->postJson('/api/v1/website/leads', $this->contactForm(['lead_type' => Lead::PARTNER_TYPE_LEAD]))
            ->assertOk();

        $this->assertSame(Lead::BORROWER_TYPE_LEAD, Lead::sole()->lead_type);
    }
}
