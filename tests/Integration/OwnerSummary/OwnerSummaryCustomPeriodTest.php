<?php

namespace Tests\Integration\OwnerSummary;

use App\Models\Lead\Lead;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryPeriod;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/** The real summary service counts inside the windows a custom period gives it. */
#[CoversClass(OwnerSummaryService::class)]
class OwnerSummaryCustomPeriodTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function leadOn(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'UTC'));

        Lead::forceCreate([
            'contact_person' => 'Lead ' . Str::random(4),
            'email' => 'lead-' . uniqid() . '@example.test',
            'status' => Lead::STATUS_NEW_LEAD,
        ]);
    }

    #[Test]
    public function it_counts_the_range_and_the_days_just_before_it(): void
    {
        Mail::fake();
        Notification::fake();

        $owner = $this->makePartnerAdmin($this->makeHostPartner());

        $this->leadOn('2026-10-15 12:00:00'); // in the range
        $this->leadOn('2026-10-20 12:00:00'); // in the range
        $this->leadOn('2026-10-05 12:00:00'); // in the 10 days just before
        $this->leadOn('2026-09-20 12:00:00'); // in neither
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));

        $summary = app(OwnerSummaryService::class)->summary(
            $owner,
            OwnerSummaryPeriod::custom('2026-10-11', '2026-10-20', 'UTC'),
            OwnerSummaryService::ENVIRONMENT_PRODUCTION
        );

        $this->assertSame('custom', $summary['period']['key']);
        $this->assertEquals(2, $summary['kpis']['new_leads']['current']);
        $this->assertEquals(1, $summary['kpis']['new_leads']['previous']);
    }
}
