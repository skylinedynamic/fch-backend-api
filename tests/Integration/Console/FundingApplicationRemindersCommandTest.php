<?php

namespace Tests\Integration\Console;

use App\Console\Commands\SendFundingApplicationReminders;
use App\Mail\FundingRequest\BorrowerFundingApplicationReminder;
use App\Mail\FundingRequest\PartnerFundingApplicationReminder;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The daily nudge to a borrower (and their partner) whose funding request was
 * approved but who has not started the funding application: one reminder per
 * step (day 1, 3 and 7 by calendar day), never the same step twice.
 */
#[CoversClass(SendFundingApplicationReminders::class)]
class FundingApplicationRemindersCommandTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $borrower;
    private int $sequence;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Carbon::setTestNow('2026-06-15 14:00:00');
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->makePartnerAdmin($this->partner);
        $this->borrower = $this->makeBorrowerUser($this->partner);
        $this->sequence = DB::table('funding_sequencings')->insertGetId(['name' => 'Business Lines of Credit', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function approvedRequest(string $approvedAt, array $attributes = []): int
    {
        return DB::table('funding_requests')->insertGetId(array_merge([
            'borrower_id' => $this->borrower->entity_id,
            'partner_id' => $this->partner->id,
            'funding_sequence_id' => $this->sequence,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'status' => FundingRequest::STATUS_APPROVED,
            'approved_at' => $approvedAt,
            'reminder_step' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function remind(): void
    {
        $this->artisan('funding:application-reminders')->assertSuccessful();
    }

    private function step(int $request): int
    {
        return (int) DB::table('funding_requests')->where('id', $request)->value('reminder_step');
    }

    #[Test]
    public function a_day_after_approval_the_borrower_and_partner_get_the_first_reminder(): void
    {
        $request = $this->approvedRequest('2026-06-14 09:00:00');

        $this->remind();

        $this->assertSame(1, $this->step($request));
        Mail::assertQueued(BorrowerFundingApplicationReminder::class, fn ($mail) => $mail->hasTo($this->borrower->email));
        Mail::assertQueued(PartnerFundingApplicationReminder::class, fn ($mail) => $mail->hasTo($this->partner->email));
        Notification::assertSentTo($this->borrower, DynamicNotification::class, fn ($n) => $n->typeKey === 'borrower.funding_application.reminder');
    }

    #[Test]
    public function a_second_run_the_same_day_sends_nothing_more(): void
    {
        $this->approvedRequest('2026-06-14 09:00:00');

        $this->remind();
        $this->remind();

        Mail::assertQueuedCount(2);
    }

    #[Test]
    public function the_day_counts_by_calendar_date_not_24_hours(): void
    {
        Carbon::setTestNow('2026-06-15 00:10:00');
        $request = $this->approvedRequest('2026-06-14 23:50:00');

        $this->remind();

        $this->assertSame(1, $this->step($request));
    }

    #[Test]
    public function nothing_is_sent_on_the_day_of_approval(): void
    {
        $request = $this->approvedRequest('2026-06-15 08:00:00');

        $this->remind();

        $this->assertSame(0, $this->step($request));
        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_later_steps_follow_on_days_3_and_7(): void
    {
        $request = $this->approvedRequest('2026-06-12 10:00:00', ['reminder_step' => 1]);

        $this->remind();
        $this->assertSame(2, $this->step($request));

        Carbon::setTestNow('2026-06-19 14:00:00');
        $this->remind();
        $this->assertSame(3, $this->step($request));

        Carbon::setTestNow('2026-07-30 14:00:00');
        $this->remind();
        $this->assertSame(3, $this->step($request), 'there is no step after the last');
        Mail::assertQueuedCount(4);
    }

    #[Test]
    public function a_missed_run_sends_only_the_latest_reminder_due(): void
    {
        $request = $this->approvedRequest('2026-06-05 10:00:00');

        $this->remind();

        $this->assertSame(3, $this->step($request));
        Mail::assertQueued(BorrowerFundingApplicationReminder::class, 1);
    }

    #[Test]
    public function a_request_already_started_declined_or_never_approved_is_left_alone(): void
    {
        $started = $this->approvedRequest('2026-06-10 10:00:00');
        DB::table('funding_applications')->insert([
            'application_number' => 'FUND-STARTED',
            'borrower_id' => $this->borrower->entity_id,
            'partner_id' => $this->partner->id,
            'funding_request_id' => $started,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->approvedRequest('2026-06-10 10:00:00', ['status' => FundingRequest::STATUS_REJECTED]);
        $this->approvedRequest('2026-06-10 10:00:00', ['status' => FundingRequest::STATUS_PENDING, 'approved_at' => null]);

        $this->remind();

        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }
}
