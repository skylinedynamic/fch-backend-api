<?php

namespace Tests\Feature\Lead;

use App\Http\Controllers\Api\V1\FCH\Common\Lead\LeadController;
use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-057: converting a lead that a second tab already converted answered
 * HTTP 500 "A borrower with this email already exists." It is refused with
 * 422 and says why - the lead has already been converted - and nothing new
 * is created. Refusals the partner can act on are never a 500.
 */
#[CoversClass(LeadController::class)]
class ConvertLeadAlreadyConvertedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $wanda;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->makeHostPartner(['company_name' => 'Host Co']);
        $this->wanda = $this->makeWhiteLabelPartner(['company_name' => 'Wanda Co']);
        $this->actingAsUser($this->makePartnerAdmin($this->wanda));
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'partner_id' => $this->wanda->id,
            'company_name' => 'Twice Co',
            'contact_person' => 'Tia Twice',
            'email' => 'tia-' . uniqid() . '@example.test',
            'phone_number' => '(212) 555-0142',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
        ], $attributes));
    }

    private function convert(Lead $lead)
    {
        return $this->postJson("/api/v1/common/leads/{$lead->id}/convert", [
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->toDateString(),
        ]);
    }

    #[Test]
    public function converting_a_converted_lead_again_is_refused_with_422_and_says_so(): void
    {
        $lead = $this->lead();
        $this->convert($lead)->assertOk();

        $this->convert($lead)
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', __('lead.already_converted'));

        $this->assertSame(1, Borrower::where('email', $lead->email)->count());
    }

    #[Test]
    public function an_email_that_is_already_a_client_is_refused_with_422_not_500(): void
    {
        $first = $this->lead(['email' => 'same-person@example.test']);
        $this->convert($first)->assertOk();
        $second = $this->lead(['email' => 'same-person@example.test']);

        $this->convert($second)
            ->assertStatus(422)
            ->assertJsonPath('message', __('lead.email_already_your_client'));
    }
}
