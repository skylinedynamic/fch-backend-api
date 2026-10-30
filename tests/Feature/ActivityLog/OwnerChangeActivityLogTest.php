<?php

namespace Tests\Feature\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
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
 * Changing who is responsible for a record - a client's Assigned To, a
 * lead's Lead Owner, a partner's Agent - is logged by name: who it was, who
 * it is now, as its own entry when nothing else changed, and as a
 * "View changes" row.
 */
class OwnerChangeActivityLogTest extends TestCase
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
            'salesperson_id' => $this->partnerUser->id,
        ], $attributes));
    }

    // ── Client: Assigned To ────────────────────────────────────────────────

    #[Test]
    public function a_clients_assigned_to_change_is_logged_from_one_name_to_the_other(): void
    {
        $this->borrower->update(['assign_id' => $this->partnerUser->id]);

        app(BorrowerService::class)->update($this->borrower->refresh(), ['assign_id' => $this->partnerColleague->id]);

        $log = $this->latestLogFor($this->borrower);
        $this->assertSame('Client Assigned To Changed', $log->event);
        $this->assertSame('Assigned To changed from Jason Rodriguez to Partner Colleague.', $log->description);
        $this->assertSame(
            ['label' => 'Assigned To', 'from' => 'Jason Rodriguez', 'to' => 'Partner Colleague'],
            $log->properties['changes']['assign_id']
        );
        $this->assertStringNotContainsString('Assign Id', $log->description);
    }

    #[Test]
    public function assigning_and_unassigning_a_client_say_so(): void
    {
        app(BorrowerService::class)->update($this->borrower->refresh(), ['assign_id' => $this->partnerColleague->id]);
        $this->assertSame('Assigned To set to Partner Colleague.', $this->latestLogFor($this->borrower)->description);

        app(BorrowerService::class)->update($this->borrower->refresh(), ['assign_id' => null]);
        $log = $this->latestLogFor($this->borrower);
        $this->assertSame('Assigned To removed (was Partner Colleague).', $log->description);
        $this->assertSame(['label' => 'Assigned To', 'from' => 'Partner Colleague', 'to' => ''], $log->properties['changes']['assign_id']);
    }

    #[Test]
    public function an_assigned_to_change_among_other_edits_is_named_in_the_client_update(): void
    {
        $this->borrower->update(['assign_id' => $this->partnerUser->id]);

        app(BorrowerService::class)->update($this->borrower->refresh(), [
            'assign_id' => $this->partnerColleague->id,
            'first_name' => 'Jonathan',
        ]);

        $log = $this->latestLogFor($this->borrower);
        $this->assertSame(ActivityLog::LOG_TYPE_BORROWER_UPDATE, $log->log_name);
        $this->assertStringContainsString('Assigned To changed from Jason Rodriguez to Partner Colleague', $log->description);
        $this->assertStringContainsString('First Name was updated to Jonathan', $log->description);
        $this->assertSame('Partner Colleague', $log->properties['changes']['assign_id']['to']);
    }

    // ── Lead: Lead Owner ───────────────────────────────────────────────────

    #[Test]
    public function a_lead_owner_change_is_logged_from_one_name_to_the_other(): void
    {
        $lead = $this->lead();

        app(LeadService::class)->updateDetails($lead, ['salesperson_id' => $this->partnerColleague->id]);

        $log = $this->latestLogFor($lead);
        $this->assertSame('Lead Owner Changed', $log->event);
        $this->assertSame('Lead Owner changed from Jason Rodriguez to Partner Colleague.', $log->description);
        $this->assertSame(
            ['label' => 'Lead Owner', 'from' => 'Jason Rodriguez', 'to' => 'Partner Colleague'],
            $log->properties['changes']['salesperson_id']
        );
    }

    #[Test]
    public function removing_a_lead_owner_says_who_it_was(): void
    {
        $lead = $this->lead();

        app(LeadService::class)->updateDetails($lead, ['salesperson_id' => null]);

        $this->assertSame('Lead Owner removed (was Jason Rodriguez).', $this->latestLogFor($lead)->description);
    }

    #[Test]
    public function a_lead_owner_change_among_other_edits_is_named_in_the_lead_update(): void
    {
        $lead = $this->lead();

        app(LeadService::class)->updateDetails($lead, ['salesperson_id' => $this->partnerColleague->id, 'probability' => 'Low']);

        $log = $this->latestLogFor($lead);
        $this->assertSame(ActivityLog::LOG_TYPE_LEAD_DETAILS_UPDATE, $log->log_name);
        $this->assertStringContainsString('Lead Owner changed from Jason Rodriguez to Partner Colleague', $log->description);
        $this->assertStringContainsString('Probability was updated to Low', $log->description);
        $this->assertStringNotContainsString('was changed', $log->description);
    }

    // ── Partner: Agent ─────────────────────────────────────────────────────

    #[Test]
    public function a_partners_agent_change_is_logged_from_one_name_to_the_other(): void
    {
        $this->partner->update(['assign_to' => $this->hostAdmin->id]);

        app(PartnerService::class)->update($this->partner->refresh(), ['assign_to' => $this->hostColleague->id]);

        $log = $this->latestLogFor($this->partner);
        $this->assertSame('Partner Agent Changed', $log->event);
        $this->assertSame('Agent changed from Host Admin to Avery Staff.', $log->description);
        $this->assertSame(
            ['label' => 'Agent', 'from' => 'Host Admin', 'to' => 'Avery Staff'],
            $log->properties['changes']['assign_to']
        );
    }

    #[Test]
    public function setting_a_partners_first_agent_says_so(): void
    {
        app(PartnerService::class)->update($this->partner, ['assign_to' => $this->hostColleague->id]);

        $this->assertSame('Agent set to Avery Staff.', $this->latestLogFor($this->partner)->description);
    }

    #[Test]
    public function an_agent_who_no_longer_exists_is_named_by_id(): void
    {
        $this->partner->update(['assign_to' => $this->hostAdmin->id]);
        $gone = $this->makeUser(User::ADMIN, ['name' => 'Gone Soon', 'partner_id' => $this->hostPartner->id]);
        $this->partner->update(['assign_to' => $gone->id]);
        $goneId = $gone->id;
        $gone->forceDelete();

        app(PartnerService::class)->update($this->partner->refresh(), ['assign_to' => $this->hostColleague->id]);

        $this->assertSame("Agent changed from user #{$goneId} to Avery Staff.", $this->latestLogFor($this->partner)->description);
    }
}
