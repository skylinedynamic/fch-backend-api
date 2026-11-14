<?php

namespace Tests\Feature\Reminder;

use App\Models\Common\Reminder;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-152: the Task & Reminders Date Range is the viewer's day (X-Timezone),
 * not the UTC day. set_date_time / send_at are stored in UTC.
 */
class ReminderDateRangeTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private const DHAKA = 'Asia/Dhaka';

    private int $partnerId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        // 01-Nov-2026 01:00 AM in Dhaka (UTC+6) - as in the QA report.
        Carbon::setTestNow(Carbon::parse('2026-11-01 01:00:00', self::DHAKA)->utc());

        $this->partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'phone_number' => '(111) 111-1111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Owner User',
            'email' => 'owner-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $this->partnerId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs(User::findOrFail($userId), [], 'api');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A reminder whose activity is at $localActivity (Dhaka), reminding 30 minutes before. */
    private function makeReminder(string $localActivity, string $summary): Reminder
    {
        $activityUtc = Carbon::parse($localActivity, self::DHAKA)->utc();

        return Reminder::create([
            'entity_id' => $this->partnerId,
            'entity_type' => Partner::class,
            'created_by' => $this->partnerId,
            'created_by_entity_type' => Partner::class,
            'reference' => 'Reference Call',
            'summary' => $summary,
            'set_date_time' => $activityUtc,
            'reminder_date_time' => $activityUtc->copy()->subMinutes(30),
            'send_at' => $activityUtc->copy()->subMinutes(30),
            'status' => Reminder::STATUS_PENDING,
        ]);
    }

    private function summariesFor(string $start, string $end, ?string $timezone = self::DHAKA): array
    {
        $headers = $timezone ? ['X-Timezone' => $timezone] : [];

        $response = $this->postJson('/api/v1/common/reminders/fetch-reminders', [
            'start_date' => $start,
            'end_date' => $end,
        ], $headers)->assertOk();

        return collect($response->json('data.reminders.data'))
            ->pluck('summary')
            ->sort()
            ->values()
            ->all();
    }

    #[Test]
    public function today_lists_only_reminders_on_the_viewers_local_day(): void
    {
        $this->makeReminder('2026-11-01 01:17:00', 'today early');     // 31-Oct 19:17 UTC
        $this->makeReminder('2026-11-01 23:30:00', 'today late');      // 01-Nov 17:30 UTC
        $this->makeReminder('2026-11-02 01:03:00', 'tomorrow early');  // 01-Nov 19:03 UTC
        $this->makeReminder('2026-10-31 23:00:00', 'yesterday late');  // 31-Oct 17:00 UTC

        $this->assertSame(['today early', 'today late'], $this->summariesFor('2026-11-01', '2026-11-01'));
    }

    #[Test]
    public function a_picked_range_covers_whole_local_days(): void
    {
        $this->makeReminder('2026-11-01 01:17:00', 'first day');
        $this->makeReminder('2026-11-02 01:03:00', 'second day');
        // Activity and reminder (00:30) both on 03-Nov local.
        $this->makeReminder('2026-11-03 01:00:00', 'after range');

        $this->assertSame(['first day', 'second day'], $this->summariesFor('2026-11-01', '2026-11-02'));
    }

    #[Test]
    public function without_a_timezone_header_the_app_timezone_is_used(): void
    {
        $this->makeReminder('2026-11-01 01:17:00', 'utc 31 oct');  // 31-Oct 19:17 UTC
        $this->makeReminder('2026-11-01 23:30:00', 'utc 01 nov');  // 01-Nov 17:30 UTC

        $this->assertSame(['utc 01 nov'], $this->summariesFor('2026-11-01', '2026-11-01', null));
    }

    #[Test]
    public function an_invalid_timezone_header_falls_back_to_the_app_timezone(): void
    {
        $this->makeReminder('2026-11-01 01:17:00', 'utc 31 oct');
        $this->makeReminder('2026-11-01 23:30:00', 'utc 01 nov');

        $this->assertSame(['utc 01 nov'], $this->summariesFor('2026-11-01', '2026-11-01', 'Not/AZone'));
    }
}
