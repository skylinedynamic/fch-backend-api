<?php

namespace Tests\Integration\Console;

use App\Console\Commands\SyncCalendlyEvents;
use App\Models\Appointment\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesCalendly;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * calendly:sync - the hourly catch-up behind the webhooks: whatever Calendly
 * has that a webhook missed (a delivery lost, the API down for a while) is
 * brought in.
 */
#[CoversClass(SyncCalendlyEvents::class)]
class SyncCalendlyEventsCommandTest extends TestCase
{
    use FakesCalendly;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->configureCalendly();
    }

    #[Test]
    public function it_brings_in_the_meetings_of_the_connected_account(): void
    {
        $host = $this->makePartnerAdmin($this->makeHostPartner());
        $this->connectCalendly($host);
        $event = $this->calendlyEvent('EV1', now()->addDays(2));
        Http::fake([
            "{$this->calendlyApi}/scheduled_events/EV1/invitees*" => Http::response(['collection' => [$this->calendlyInvitee($event, 'someone@example.test', 'Some One')]]),
            "{$this->calendlyApi}/scheduled_events?*" => Http::response(['collection' => [$event], 'pagination' => ['next_page_token' => null]]),
        ]);

        $this->artisan('calendly:sync')->assertSuccessful();

        $this->assertSame(1, Appointment::where('source', Appointment::SOURCE_CALENDLY)->count());
    }

    #[Test]
    public function with_no_account_connected_it_does_nothing_and_succeeds(): void
    {
        Http::fake();

        $this->artisan('calendly:sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_calendly_failure_fails_the_run_so_the_schedule_reports_it(): void
    {
        $this->connectCalendly($this->makePartnerAdmin($this->makeHostPartner()));
        Http::fake(["{$this->calendlyApi}/*" => Http::response(['message' => 'Down'], 500)]);

        $this->artisan('calendly:sync')->assertFailed();
    }
}
