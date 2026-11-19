<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerNotificationRecipient;
use App\Services\FCH\Partner\PartnerNotificationRecipientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Partner profile › Mail Notification: where a partner's lead and client
 * notification emails go. One row per partner and type
 * (partner_notification_recipients); an empty recipient is no row, and the
 * partner's own email is used instead (Partner::notificationEmail()).
 */
#[CoversClass(PartnerController::class)]
#[CoversClass(PartnerNotificationRecipientService::class)]
#[CoversClass(Partner::class)]
class PartnerMailNotificationApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner(['email' => 'owner@acme.test']);
        $this->actingAsUser($this->makePartnerAdmin($this->host));
    }

    private function url(?int $id = null): string
    {
        return '/api/v1/admin/partners/' . ($id ?? $this->partner->id) . '/mail-notification';
    }

    #[Test]
    public function every_notification_type_is_listed_with_its_label_and_no_recipient_yet(): void
    {
        $rows = $this->getJson($this->url())->assertOk()->json('data');

        $this->assertSame(PartnerNotificationRecipient::TYPES, array_column($rows, 'type'));
        $this->assertSame(['Lead Notification Recipient', 'Client Notification Recipient'], array_column($rows, 'label'));
        $this->assertSame([null, null], array_column($rows, 'email'));
        $this->assertNotEmpty($rows[0]['help']);
    }

    #[Test]
    public function recipients_are_saved_one_row_per_type_and_listed_back(): void
    {
        $rows = $this->putJson($this->url(), ['recipients' => ['leads' => 'leads@acme.test', 'clients' => 'clients@acme.test']])
            ->assertOk()
            ->assertJsonPath('message', __('partner.success.mail_notification_updated'))
            ->json('data');

        $this->assertSame(['leads@acme.test', 'clients@acme.test'], array_column($rows, 'email'));
        $this->assertSame(2, PartnerNotificationRecipient::where('partner_id', $this->partner->id)->count());
        $this->assertSame(['leads@acme.test', 'clients@acme.test'], array_column($this->getJson($this->url())->json('data'), 'email'));
    }

    #[Test]
    public function a_cleared_recipient_is_removed_and_a_changed_one_replaced(): void
    {
        $this->putJson($this->url(), ['recipients' => ['leads' => 'leads@acme.test', 'clients' => 'clients@acme.test']])->assertOk();

        $this->putJson($this->url(), ['recipients' => ['leads' => null, 'clients' => 'team@acme.test']])->assertOk();

        $this->assertNull(PartnerNotificationRecipient::where('partner_id', $this->partner->id)->where('type', 'leads')->first());
        $this->assertSame('team@acme.test', PartnerNotificationRecipient::where('partner_id', $this->partner->id)->where('type', 'clients')->value('email'));
        $this->assertSame(1, PartnerNotificationRecipient::where('partner_id', $this->partner->id)->count());
    }

    #[Test]
    public function a_type_left_out_of_the_request_is_kept(): void
    {
        $this->putJson($this->url(), ['recipients' => ['leads' => 'leads@acme.test']])->assertOk();

        $this->putJson($this->url(), ['recipients' => ['clients' => 'clients@acme.test']])->assertOk();

        $this->assertSame('leads@acme.test', PartnerNotificationRecipient::where('partner_id', $this->partner->id)->where('type', 'leads')->value('email'));
    }

    #[Test]
    public function an_invalid_email_is_refused_under_its_own_field(): void
    {
        $this->putJson($this->url(), ['recipients' => ['leads' => 'not-an-email', 'clients' => 'clients@acme.test']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipients.leads')
            ->assertJsonMissingValidationErrors('recipients.clients');

        $this->assertSame(0, PartnerNotificationRecipient::count());
    }

    #[Test]
    public function an_unknown_notification_type_is_refused(): void
    {
        $this->putJson($this->url(), ['recipients' => ['invoices' => 'x@acme.test']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipients');
    }

    #[Test]
    public function an_unknown_partner_is_not_found(): void
    {
        $this->getJson($this->url(999999))->assertNotFound();
        $this->putJson($this->url(999999), ['recipients' => ['leads' => 'x@acme.test']])->assertNotFound();
    }

    #[Test]
    public function a_partners_recipients_go_when_the_partner_is_deleted(): void
    {
        $this->putJson($this->url(), ['recipients' => ['leads' => 'leads@acme.test']])->assertOk();

        $this->partner->forceDelete();

        $this->assertSame(0, PartnerNotificationRecipient::count());
    }

    #[Test]
    public function the_notification_email_is_the_recipient_when_set_and_the_partners_own_otherwise(): void
    {
        $this->assertSame('owner@acme.test', $this->partner->notificationEmail(PartnerNotificationRecipient::TYPE_LEADS));

        $this->putJson($this->url(), ['recipients' => ['leads' => 'leads@acme.test']])->assertOk();
        $partner = $this->partner->fresh();

        $this->assertSame('leads@acme.test', $partner->notificationEmail(PartnerNotificationRecipient::TYPE_LEADS));
        $this->assertSame('owner@acme.test', $partner->notificationEmail(PartnerNotificationRecipient::TYPE_CLIENTS));
    }
}
