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
 * BUG-018: converting a lead whose email is already another partner's client
 * said "A borrower with this email already exists." - telling the partner the
 * person is a client elsewhere. Two partners can both hold a lead for the same
 * email before either converts, so this is reachable.
 *
 * Another partner's client: a neutral message that says nothing about who.
 * The partner's own client: say so - it is their own data.
 * Either way no second borrower is created.
 */
#[CoversClass(LeadController::class)]
class ConvertLeadEmailTakenTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const EMAIL = 'shared-person@example.test';

    private Partner $host;
    private Partner $wanda;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->host = $this->makeHostPartner(['company_name' => 'Host Co']);
        $this->wanda = $this->makeWhiteLabelPartner(['company_name' => 'Wanda Co']);
    }

    private function leadOf(Partner $partner): Lead
    {
        return Lead::create([
            'partner_id' => $partner->id,
            'company_name' => 'Shared Co',
            'contact_person' => 'Sam Shared',
            'email' => self::EMAIL,
            'phone_number' => '(212) 555-0199',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
        ]);
    }

    private function convert(Lead $lead, Partner $as)
    {
        $this->actingAsUser($this->makePartnerAdmin($as));

        return $this->postJson("/api/v1/common/leads/{$lead->id}/convert", [
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->toDateString(),
        ]);
    }

    #[Test]
    public function another_partners_client_gets_a_neutral_message(): void
    {
        $hostLead = $this->leadOf($this->host);
        $wandaLead = $this->leadOf($this->wanda);
        $this->convert($hostLead, $this->host)->assertOk();

        $response = $this->convert($wandaLead, $this->wanda);

        $response->assertJsonPath('status', false)
            ->assertJsonPath('message', __('lead.email_cannot_be_used'));
        $this->assertStringNotContainsStringIgnoringCase('borrower', $response->json('message'));
        $this->assertSame(1, Borrower::where('email', self::EMAIL)->count());
    }

    #[Test]
    public function the_partners_own_client_is_named_as_theirs(): void
    {
        $first = $this->leadOf($this->wanda);
        $this->convert($first, $this->wanda)->assertOk();
        // A second lead of theirs for the same person.
        $second = $this->leadOf($this->wanda);

        $this->convert($second, $this->wanda)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', __('lead.email_already_your_client'));
        $this->assertSame(1, Borrower::where('email', self::EMAIL)->count());
    }
}
