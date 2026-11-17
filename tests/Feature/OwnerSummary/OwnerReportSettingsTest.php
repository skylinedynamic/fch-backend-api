<?php

namespace Tests\Feature\OwnerSummary;

use App\Http\Controllers\Api\V1\FCH\Common\OwnerSummary\OwnerReportSettingsController;
use App\Models\OwnerSummary\OwnerReportSetting;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/** GET and PUT /api/v1/common/owner-summary/report-settings - who gets the weekly report. */
#[CoversClass(OwnerReportSettingsController::class)]
class OwnerReportSettingsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const URL = '/api/v1/common/owner-summary/report-settings';

    private Partner $host;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
        $this->owner = $this->makePartnerAdmin($this->host);
    }

    private function save(array $payload, ?User $user = null): TestResponse
    {
        $this->actingAsUser($user ?? $this->owner);

        return $this->putJson(self::URL, $payload);
    }

    #[Test]
    public function only_the_host_owner_may_see_or_change_them(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->putJson(self::URL, [])->assertUnauthorized();

        foreach ([$this->makeStaffUser($this->host), $this->makePartnerAdmin($this->makeWhiteLabelPartner()), $this->makeBorrowerUser($this->host)] as $user) {
            $this->actingAsUser($user);
            $this->getJson(self::URL)->assertForbidden();
            $this->save(['enabled' => true, 'recipients' => ['a@example.test']], $user)->assertForbidden();
        }

        $this->assertDatabaseCount('owner_report_settings', 0);
    }

    #[Test]
    public function the_report_is_off_with_nobody_on_it_until_it_is_set_up(): void
    {
        $this->actingAsUser($this->owner);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.recipients', []);
    }

    #[Test]
    public function saved_settings_come_back_and_a_second_save_replaces_them(): void
    {
        $this->save(['enabled' => true, 'recipients' => ['owner@example.test', 'finance@example.test']])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.recipients', ['owner@example.test', 'finance@example.test']);

        $this->save(['enabled' => false, 'recipients' => ['finance@example.test']])->assertOk();

        $this->getJson(self::URL)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.recipients', ['finance@example.test']);
        $this->assertSame(1, OwnerReportSetting::where('partner_id', $this->host->id)->count());
    }

    #[Test]
    public function an_address_must_be_complete(): void
    {
        $this->save(['enabled' => true, 'recipients' => ['owner@example.test', 'finance@fourcornerfunding']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipients.1' => 'Enter a valid email address.']);
    }

    #[Test]
    public function an_address_can_only_be_listed_once(): void
    {
        $this->save(['enabled' => true, 'recipients' => ['owner@example.test', 'OWNER@example.test']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipients.1' => 'This address is already on the list.']);
    }

    #[Test]
    public function the_list_stops_at_ten_addresses(): void
    {
        $eleven = array_map(fn (int $i) => "person{$i}@example.test", range(1, 11));

        $this->save(['enabled' => true, 'recipients' => $eleven])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipients' => 'Add up to 10 addresses.']);

        $this->save(['enabled' => true, 'recipients' => array_slice($eleven, 0, 10)])->assertOk();
    }

    #[Test]
    public function turning_the_report_on_needs_someone_to_send_it_to(): void
    {
        $this->save(['enabled' => true, 'recipients' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipients' => 'Add at least one address to send the report to.']);

        $this->save(['enabled' => false, 'recipients' => []])->assertOk();
    }

    #[Test]
    public function it_must_say_on_or_off_and_give_a_list(): void
    {
        $this->save([])->assertUnprocessable()->assertJsonValidationErrors(['enabled', 'recipients']);
    }
}
