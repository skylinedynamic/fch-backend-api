<?php

namespace Tests\Feature\Lead;

use App\Mail\Lead\LeadNotificationMail;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerNotificationRecipient;
use App\Services\FCH\Common\Lead\LeadService;
use App\Services\Global\Website\Lead\LeadService as WebsiteLeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A new lead is emailed to its partner's Lead Notification Recipient (Partner
 * profile › Mail Notification) - or, with none set, to the partner's own
 * email. Once per lead, whoever created it: the host, a partner, or the website.
 */
#[CoversClass(LeadService::class)]
#[CoversClass(WebsiteLeadService::class)]
class LeadCreatedRecipientTest extends TestCase
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
        $this->host = $this->makeHostPartner(['email' => 'host@fch.test']);
        $this->partner = $this->makeWhiteLabelPartner(['email' => 'owner@acme.test']);
    }

    private function recipient(Partner $partner, string $email): void
    {
        PartnerNotificationRecipient::create(['partner_id' => $partner->id, 'type' => PartnerNotificationRecipient::TYPE_LEADS, 'email' => $email]);
    }

    private function createLead(array $overrides = []): Lead
    {
        $id = $this->postJson('/api/v1/common/leads/details', array_merge([
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'probability' => 'High',
            'status' => 'New',
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
        ], $overrides))->assertSuccessful()->json('data.id');

        return Lead::findOrFail($id);
    }

    /** @return list<list<string>> each lead email's recipients */
    private function leadMails(): array
    {
        $sent = [];
        Mail::assertQueued(LeadNotificationMail::class, function (LeadNotificationMail $mail) use (&$sent) {
            $sent[] = array_column($mail->to, 'address');

            return true;
        });

        return $sent;
    }

    #[Test]
    public function a_partners_new_lead_goes_to_its_lead_recipient(): void
    {
        $this->recipient($this->partner, 'leads@acme.test');
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->createLead();

        $this->assertSame([['leads@acme.test']], $this->leadMails());
    }

    #[Test]
    public function with_no_recipient_set_it_goes_to_the_partners_own_email(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->createLead();

        $this->assertSame([['owner@acme.test']], $this->leadMails());
    }

    #[Test]
    public function a_lead_the_host_creates_goes_to_the_hosts_lead_recipient(): void
    {
        $this->recipient($this->host, 'host-leads@fch.test');
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->createLead();

        $this->assertSame([['host-leads@fch.test']], $this->leadMails());
    }

    #[Test]
    public function a_website_lead_goes_to_the_hosts_lead_recipient(): void
    {
        $this->recipient($this->host, 'host-leads@fch.test');

        app(WebsiteLeadService::class)->store(['contact_person' => 'Web Visitor', 'email' => 'visitor@example.test']);

        $this->assertSame([['host-leads@fch.test']], $this->leadMails());
    }

    #[Test]
    public function a_website_lead_falls_back_to_the_hosts_own_email(): void
    {
        app(WebsiteLeadService::class)->store(['contact_person' => 'Web Visitor', 'email' => 'visitor@example.test']);

        $this->assertSame([['host@fch.test']], $this->leadMails());
    }

    #[Test]
    public function another_partners_recipient_is_never_used(): void
    {
        $this->recipient($this->host, 'host-leads@fch.test');
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->createLead();

        $this->assertSame([['owner@acme.test']], $this->leadMails());
    }
}
