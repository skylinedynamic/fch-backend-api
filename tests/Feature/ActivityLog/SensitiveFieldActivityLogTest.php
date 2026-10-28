<?php

namespace Tests\Feature\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Lead\Lead;
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Services\FCH\Common\Lead\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ActivityLog\Concerns\BuildsTaskAssignmentFixtures;
use Tests\TestCase;

/**
 * The client form sends the SSN back on every save. The same SSN is no
 * change, and a real change is recorded without the value - plain or
 * encrypted - since the entry is shown to staff (and may be to the client).
 */
class SensitiveFieldActivityLogTest extends TestCase
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

        $this->borrower->ssn = '123-45-6789';
        $this->borrower->save();
        $this->borrower->refresh();
    }

    private function latestClientLog(): ActivityLog
    {
        return ActivityLog::where('subject_type', $this->borrower::class)
            ->where('subject_id', $this->borrower->id)
            ->latest('id')
            ->firstOrFail();
    }

    #[Test]
    public function the_same_ssn_sent_again_is_not_a_change(): void
    {
        $encrypted = $this->borrower->ssn_encrypted;

        $this->borrower->ssn = '123456789';

        $this->assertFalse($this->borrower->isDirty('ssn_encrypted'));
        $this->assertSame($encrypted, $this->borrower->ssn_encrypted);
    }

    #[Test]
    public function changing_only_assigned_to_logs_nothing_about_the_ssn(): void
    {
        app(BorrowerService::class)->update($this->borrower, [
            'ssn' => '123-45-6789',
            'assign_id' => $this->partnerColleague->id,
        ]);

        $log = $this->latestClientLog();
        $this->assertSame('Client Assigned To Changed', $log->event);
        $this->assertSame('Assigned To set to Partner Colleague.', $log->description);
        $this->assertStringNotContainsStringIgnoringCase('ssn', $log->description);
    }

    #[Test]
    public function a_changed_ssn_is_recorded_without_its_value(): void
    {
        app(BorrowerService::class)->update($this->borrower, ['ssn' => '987-65-4321']);

        $log = $this->latestClientLog();
        $encrypted = $this->borrower->refresh()->ssn_encrypted;
        $this->assertSame('SSN was updated.', $log->description);
        $this->assertStringNotContainsString($encrypted, json_encode($log->getAttributes()));
        $this->assertStringNotContainsString('987', $log->description);
        $this->assertStringNotContainsString('4321', $log->description);
    }

    #[Test]
    public function a_clients_date_of_birth_is_recorded_without_its_value(): void
    {
        app(BorrowerService::class)->update($this->borrower, ['date_of_birth' => '1980-05-17']);

        $description = $this->latestClientLog()->description;
        $this->assertSame('Date Of Birth was updated.', $description);
        $this->assertStringNotContainsString('1980', $description);
    }

    #[Test]
    public function a_leads_ein_is_recorded_without_its_value(): void
    {
        $lead = Lead::create([
            'partner_id' => $this->partner->id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => Lead::PARTNER_TYPE_LEAD,
            'status' => Lead::STATUS_LEAD_COMPLETED,
        ]);

        app(LeadService::class)->updateDetails($lead, ['business_ein' => '12-3456789']);

        $log = ActivityLog::where('subject_type', Lead::class)->where('subject_id', $lead->id)->latest('id')->firstOrFail();
        $this->assertSame('Business EIN was updated.', $log->description);
        $this->assertStringNotContainsString('3456789', $log->description);
    }

    #[Test]
    public function sensitive_fields_are_recognised_by_name(): void
    {
        foreach (['ssn', 'ssn_encrypted', 'last_4_ssn', 'business_ein', 'ein', 'date_of_birth', 'bank_account_number', 'routing_number', 'tax_id'] as $field) {
            $this->assertTrue(ActivityLog::isSensitiveField($field), $field);
        }
        foreach (['first_name', 'assign_id', 'email', 'business_name', 'weinstein'] as $field) {
            $this->assertFalse(ActivityLog::isSensitiveField($field), $field);
        }
    }
}
