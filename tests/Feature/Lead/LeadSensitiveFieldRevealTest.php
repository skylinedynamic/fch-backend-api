<?php

namespace Tests\Feature\Lead;

use App\Http\Controllers\Api\V1\FCH\Common\Lead\LeadController;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-004: a lead's SSN (and Business EIN) left the API in full on every
 * read of the lead - its details and the lead list - and the host opens every
 * partner's leads, so the host had every partner's lead SSNs. Leads now follow
 * the client rule (BUG-014): the numbers are masked, a form that sends the
 * mask back keeps the number on file, and the full number is fetched one field
 * at a time by anyone who can open the lead, with a record of who looked.
 */
#[CoversClass(LeadController::class)]
class LeadSensitiveFieldRevealTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private const SSN = '123456789';

    private const EIN = '987654321';

    private Partner $host;

    private Partner $partner;

    private Lead $lead;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->owner = $this->makePartnerAdmin($this->partner);
        $this->lead = Lead::create([
            'partner_id' => $this->partner->id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
            'business_ein' => self::EIN,
            'ssn' => self::SSN,
        ]);
    }

    private function reveal(string $field, ?Lead $lead = null)
    {
        return $this->postJson('/api/v1/common/leads/' . ($lead ?? $this->lead)->id . '/reveal', ['field' => $field]);
    }

    /** @return iterable<string, array{string}> */
    public static function readers(): iterable
    {
        yield "the lead's own partner" => ['owner'];
        yield "the host, on another partner's lead" => ['host'];
    }

    private function reader(string $who): User
    {
        return $who === 'host' ? $this->makePartnerAdmin($this->host) : $this->owner;
    }

    #[Test]
    #[DataProvider('readers')]
    public function the_leads_details_carry_the_ssn_and_ein_masked(string $who): void
    {
        $this->actingAsUser($this->reader($who));

        $response = $this->getJson("/api/v1/common/leads/{$this->lead->id}")
            ->assertOk()
            ->assertJsonPath('data.ssn', '***-**-6789')
            ->assertJsonPath('data.business_ein', '**-***4321');

        $this->assertStringNotContainsString(self::SSN, $response->getContent());
        $this->assertStringNotContainsString(self::EIN, $response->getContent());
    }

    #[Test]
    #[DataProvider('readers')]
    public function the_lead_list_carries_them_masked_too(string $who): void
    {
        $this->actingAsUser($this->reader($who));

        $response = $this->postJson('/api/v1/common/leads/fetch-leads', ['per_page' => 50])->assertOk();

        $this->assertStringContainsString('***-**-6789', $response->getContent());
        $this->assertStringNotContainsString(self::SSN, $response->getContent());
        $this->assertStringNotContainsString(self::EIN, $response->getContent());
    }

    #[Test]
    public function a_lead_without_either_number_carries_null(): void
    {
        $this->lead->update(['ssn' => null, 'business_ein' => null]);
        $this->actingAsUser($this->owner);

        $this->getJson("/api/v1/common/leads/{$this->lead->id}")
            ->assertOk()
            ->assertJsonPath('data.ssn', null)
            ->assertJsonPath('data.business_ein', null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function fields(): iterable
    {
        yield 'ssn' => ['ssn', self::SSN];
        yield 'business_ein' => ['business_ein', self::EIN];
    }

    #[Test]
    #[DataProvider('fields')]
    public function the_full_number_is_fetched_by_naming_the_field(string $field, string $digits): void
    {
        $this->actingAsUser($this->owner);

        $this->reveal($field)
            ->assertOk()
            ->assertJsonPath('data.field', $field)
            ->assertJsonPath('data.value', $digits);
    }

    #[Test]
    #[DataProvider('fields')]
    public function each_reveal_is_logged_on_the_lead_without_the_number(string $field, string $digits): void
    {
        $this->actingAsUser($this->owner);

        $this->reveal($field)->assertOk();

        $log = ActivityLog::where('subject_type', Lead::class)
            ->where('subject_id', $this->lead->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($this->owner->id, $log->causer_id);
        $this->assertSame(ActivityLog::VISIBILITY_INTERNAL, $log->visibility);
        $this->assertStringContainsString(ActivityLog::fieldLabel($field), $log->event);
        $this->assertStringNotContainsString(substr($digits, -4), json_encode($log->getAttributes()));
    }

    #[Test]
    public function the_host_can_reveal_another_partners_lead_and_it_is_logged(): void
    {
        $hostAdmin = $this->makePartnerAdmin($this->host);
        $this->actingAsUser($hostAdmin);

        $this->reveal('ssn')->assertOk()->assertJsonPath('data.value', self::SSN);
        $this->assertTrue(ActivityLog::where('subject_type', Lead::class)
            ->where('subject_id', $this->lead->id)
            ->where('causer_id', $hostAdmin->id)
            ->exists());
    }

    #[Test]
    public function a_number_not_on_file_is_revealed_as_null(): void
    {
        $this->lead->update(['ssn' => null]);
        $this->actingAsUser($this->owner);

        $this->reveal('ssn')->assertOk()->assertJsonPath('data.value', null);
    }

    #[Test]
    public function another_partners_lead_is_not_found(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner()));

        $this->reveal('ssn')->assertNotFound();
    }

    #[Test]
    public function only_the_masked_numbers_can_be_revealed(): void
    {
        $this->actingAsUser($this->owner);

        $this->reveal('email')->assertUnprocessable()->assertJsonValidationErrors(['field']);
        $this->postJson("/api/v1/common/leads/{$this->lead->id}/reveal", [])->assertUnprocessable();
    }

    /** @return iterable<string, array{string}> */
    public static function saves(): iterable
    {
        yield 'contact info' => ['contact-info'];
        yield 'details' => ['details'];
    }

    #[Test]
    #[DataProvider('saves')]
    public function a_form_that_sends_the_mask_back_keeps_the_numbers_on_file(string $save): void
    {
        $this->actingAsUser($this->owner);

        $this->postJson("/api/v1/common/leads/{$this->lead->id}/{$save}", [
            'contact_person' => 'Lee Lead',
            'email' => $this->lead->email,
            'phone_number' => '(555) 234-5678',
            'country' => 'United States',
            'probability' => 'High',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'company_name' => 'Lead Co',
            'lead_source' => 'Website',
            'ssn' => '***-**-6789',
            'business_ein' => '**-***4321',
        ])->assertOk();

        $this->lead->refresh();
        $this->assertSame(self::SSN, $this->lead->ssn);
        $this->assertSame(self::EIN, $this->lead->business_ein);
    }

    #[Test]
    public function a_new_number_typed_in_still_replaces_the_one_on_file(): void
    {
        $this->actingAsUser($this->owner);

        $this->postJson("/api/v1/common/leads/{$this->lead->id}/contact-info", [
            'contact_person' => 'Lee Lead',
            'email' => $this->lead->email,
            'phone_number' => '(555) 234-5678',
            'country' => 'United States',
            'ssn' => '234-56-7890',
        ])->assertOk();

        $this->assertSame('234567890', $this->lead->fresh()->ssn);
    }
}
