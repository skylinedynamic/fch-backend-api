<?php

namespace Tests\Feature\FundingApplication;

use App\Mail\FundingRequest\BorrowerFundingApplicationReminder;
use App\Mail\FundingRequest\PartnerFundingApplicationReminder;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Funding\FundingSequencing;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendFundingApplicationRemindersTest extends TestCase
{
    protected Partner $partner;

    protected FundingSequencing $sequence;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Mail::fake();

        $this->partner = Partner::factory()->create(['email' => 'partner@example.com', 'is_host' => false]);
        $this->sequence = FundingSequencing::create(['name' => 'Term Loan']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeApprovedFundingRequest(int $daysAgo, int $reminderStep = 0): FundingRequest
    {
        $borrower = Borrower::factory()->create(['partner_id' => $this->partner->id]);
        User::factory()->borrower($borrower)->create(['email' => 'borrower@example.com']);

        return FundingRequest::create([
            'borrower_id' => $borrower->id,
            'partner_id' => $this->partner->id,
            'funding_sequence_id' => $this->sequence->id,
            'status' => FundingRequest::STATUS_APPROVED,
            'approved_at' => Carbon::now()->subDays($daysAgo),
            'reminder_step' => $reminderStep,
        ]);
    }

    public function test_sends_the_day_one_reminder_when_approved_yesterday_and_none_sent_yet(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 12:00:00'));
        $request = $this->makeApprovedFundingRequest(1);

        $this->artisan('funding:application-reminders')->assertExitCode(0);

        Mail::assertQueued(BorrowerFundingApplicationReminder::class);
        Mail::assertQueued(PartnerFundingApplicationReminder::class);
        $this->assertSame(1, $request->fresh()->reminder_step);
    }

    public function test_does_not_resend_the_same_threshold_reminder_twice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 12:00:00'));
        $request = $this->makeApprovedFundingRequest(2, reminderStep: 1);

        $this->artisan('funding:application-reminders')->assertExitCode(0);

        Mail::assertNotQueued(BorrowerFundingApplicationReminder::class);
        Mail::assertNotQueued(PartnerFundingApplicationReminder::class);
        $this->assertSame(1, $request->fresh()->reminder_step);
    }

    public function test_sends_only_the_highest_applicable_threshold_reminder_in_a_single_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 12:00:00'));
        $request = $this->makeApprovedFundingRequest(8);

        $this->artisan('funding:application-reminders')->assertExitCode(0);

        Mail::assertQueued(BorrowerFundingApplicationReminder::class, 1);
        Mail::assertQueued(PartnerFundingApplicationReminder::class, 1);
        // reversed [7,3,1] => day-7 threshold is step 3 (the highest step reached first)
        $this->assertSame(3, $request->fresh()->reminder_step);
    }

    public function test_advances_the_step_on_a_later_run_once_the_next_threshold_is_crossed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 12:00:00'));
        $request = $this->makeApprovedFundingRequest(3, reminderStep: 1);

        $this->artisan('funding:application-reminders')->assertExitCode(0);

        Mail::assertQueued(BorrowerFundingApplicationReminder::class, 1);
        $this->assertSame(2, $request->fresh()->reminder_step);
    }

    public function test_skips_funding_requests_that_already_have_a_funding_application(): void
    {
        $request = $this->makeApprovedFundingRequest(8);
        FundingApplication::factory()->create([
            'funding_request_id' => $request->id,
            'borrower_id' => $request->borrower_id,
        ]);

        $this->artisan('funding:application-reminders')->assertExitCode(0);

        Mail::assertNotQueued(BorrowerFundingApplicationReminder::class);
        Mail::assertNotQueued(PartnerFundingApplicationReminder::class);
    }

    public function test_skips_funding_requests_that_are_not_yet_approved(): void
    {
        $borrower = Borrower::factory()->create(['partner_id' => $this->partner->id]);
        User::factory()->borrower($borrower)->create(['email' => 'borrower@example.com']);

        FundingRequest::create([
            'borrower_id' => $borrower->id,
            'partner_id' => $this->partner->id,
            'funding_sequence_id' => $this->sequence->id,
            'status' => FundingRequest::STATUS_PENDING,
            'approved_at' => null,
        ]);

        $this->artisan('funding:application-reminders')->assertExitCode(0);

        Mail::assertNothingQueued();
    }

    public function test_skips_a_request_that_has_not_yet_crossed_the_first_threshold(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 12:00:00'));
        $this->makeApprovedFundingRequest(0); // approved today, 0 full days elapsed

        $this->artisan('funding:application-reminders')->assertExitCode(0);

        Mail::assertNothingQueued();
    }
}
