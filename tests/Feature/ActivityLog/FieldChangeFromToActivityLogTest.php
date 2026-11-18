<?php

namespace Tests\Feature\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Services\FCH\Common\Lead\LeadService;
use App\Services\FCH\Partner\PartnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ActivityLog\Concerns\BuildsTaskAssignmentFixtures;
use Tests\TestCase;

/**
 * A field changed on a client, lead or partner is logged with both values -
 * "First Name changed from Johnathan to Jonathan" - and as a "Show changes"
 * row. An SSN, EIN, date of birth or account number is still only "was
 * updated", with no values anywhere.
 */
class FieldChangeFromToActivityLogTest extends TestCase
{
    use RefreshDatabase;
    use BuildsTaskAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTaskAssignmentFixtures();

        Mail::fake();
        Notification::fake();
        Queue::fake();

        Passport::actingAs($this->hostAdmin, [], 'api');
    }

    private function latestLogFor(object $model): ActivityLog
    {
        return ActivityLog::where('subject_type', $model::class)
            ->where('subject_id', $model->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'partner_id' => $this->partner->id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => Lead::PARTNER_TYPE_LEAD,
            'status' => Lead::STATUS_LEAD_COMPLETED,
        ], $attributes));
    }

    // ── Client ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_client_field_is_logged_from_the_old_value_to_the_new(): void
    {
        app(BorrowerService::class)->update($this->borrower, ['first_name' => 'Jonathan']);

        $log = $this->latestLogFor($this->borrower);
        $this->assertSame('First Name changed from Johnathan to Jonathan.', $log->description);
        $this->assertSame(
            ['label' => 'First Name', 'from' => 'Johnathan', 'to' => 'Jonathan'],
            $log->properties['changes']['first_name']
        );
    }

    #[Test]
    public function an_emptied_or_first_filled_value_says_empty(): void
    {
        app(BorrowerService::class)->update($this->borrower, ['last_name' => '', 'monthly_revenue' => '25000']);

        $log = $this->latestLogFor($this->borrower);
        $this->assertStringContainsString('Last Name changed from Smithfield to empty', $log->description);
        $this->assertStringContainsString('Monthly Revenue changed from empty to 25000', $log->description);
    }

    #[Test]
    public function a_sensitive_client_field_is_never_shown(): void
    {
        $this->borrower->forceFill(['ssn' => '111-22-3333'])->save();

        app(BorrowerService::class)->update($this->borrower->refresh(), ['ssn' => '444-55-6666', 'first_name' => 'Jonathan']);

        $log = $this->latestLogFor($this->borrower);
        $this->assertStringContainsString('SSN was updated', $log->description);
        $this->assertStringNotContainsString('3333', json_encode([$log->description, $log->properties]));
        $this->assertStringNotContainsString('6666', json_encode([$log->description, $log->properties]));
        $this->assertArrayNotHasKey('ssn', $log->properties['changes']);
    }

    #[Test]
    public function the_clients_business_financials_are_logged_from_old_to_new(): void
    {
        $this->borrower->update(['annual_revenue' => '100000']);

        app(BorrowerService::class)->updateBusinessFinancials($this->borrower->refresh(), ['annual_revenue' => '150000']);

        $log = $this->latestLogFor($this->borrower);
        $this->assertSame('Annual Revenue changed from 100000 to 150000.', $log->description);
        $this->assertSame('100000', $log->properties['changes']['annual_revenue']['from']);
    }

    // ── Lead ───────────────────────────────────────────────────────────────

    #[Test]
    public function a_lead_detail_is_logged_from_old_to_new(): void
    {
        $lead = $this->lead(['probability' => 'High']);

        app(LeadService::class)->updateDetails($lead, ['probability' => 'Low']);

        $log = $this->latestLogFor($lead);
        $this->assertSame('Probability changed from High to Low.', $log->description);
        $this->assertSame(['label' => 'Probability', 'from' => 'High', 'to' => 'Low'], $log->properties['changes']['probability']);
    }

    #[Test]
    public function a_lead_contact_field_is_logged_from_old_to_new(): void
    {
        $lead = $this->lead();

        app(LeadService::class)->updateContactInfo($lead, ['contact_person' => 'Lee Leader']);

        $log = $this->latestLogFor($lead);
        $this->assertSame('Contact Person changed from Lee Lead to Lee Leader.', $log->description);
        $this->assertSame('Lee Lead', $log->properties['changes']['contact_person']['from']);
    }

    // ── Partner ────────────────────────────────────────────────────────────

    #[Test]
    public function a_partner_field_and_its_name_are_logged_from_old_to_new(): void
    {
        app(PartnerService::class)->update($this->partner, ['company_name' => 'FCF Capital', 'full_name' => 'FCF Capital LLC']);

        $log = $this->latestLogFor($this->partner);
        $this->assertStringContainsString('Company Name changed from Four Corner Funding to FCF Capital', $log->description);
        $this->assertStringContainsString('Name changed from Four Corner Funding to FCF Capital LLC', $log->description);
        $this->assertSame('Four Corner Funding', $log->properties['changes']['company_name']['from']);
    }

    #[Test]
    public function a_partners_status_is_logged_from_old_to_new(): void
    {
        $partner = $this->partner->refresh();
        app(PartnerService::class)->updateStatusAgent($partner, ['status' => Partner::STATUS_INACTIVE, 'assign_to' => $partner->assign_to]);

        $log = $this->latestLogFor($this->partner);
        $this->assertSame('Status changed from Active to Inactive.', $log->description);
        $this->assertSame(['label' => 'Status', 'from' => 'Active', 'to' => 'Inactive'], $log->properties['changes']['status']);
    }
}
