<?php

namespace Tests\Feature\Borrower;

use App\Mail\Partner\ClientCreatedMail;
use App\Models\Borrower\Borrower;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerNotificationRecipient;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Borrower\BorrowerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A new client is emailed to its partner's Client Notification Recipient
 * (Partner profile › Mail Notification) - or, with none set, to the partner's
 * own email - once, whichever way the client was made: +Add Client, a lead
 * converted to a client, or an embedded form submission converted to one.
 */
#[CoversClass(BorrowerService::class)]
#[CoversClass(ClientCreatedMail::class)]
class ClientCreatedNotificationTest extends TestCase
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
        Storage::fake('public');
        $this->host = $this->makeHostPartner(['email' => 'host@fch.test']);
        $this->partner = $this->makeCertifiedPartner(['email' => 'owner@acme.test', 'company_name' => 'Acme Lending']);
    }

    private function recipient(Partner $partner, string $email): void
    {
        PartnerNotificationRecipient::create(['partner_id' => $partner->id, 'type' => PartnerNotificationRecipient::TYPE_CLIENTS, 'email' => $email]);
    }

    private function addClient(array $overrides = []): Borrower
    {
        $id = $this->postJson('/api/v1/common/borrowers', array_merge([
            'first_name' => 'Cleo',
            'last_name' => 'Client',
            'business_name' => 'Cleo Co',
            'email' => 'cleo-' . uniqid() . '@example.test',
            'phone_number' => '(555) 234-5678',
            'country' => 'United States',
            'business_country' => 'United States',
            'is_free_trail' => true,
        ], $overrides))->assertCreated()->json('data.id');

        return Borrower::findOrFail($id);
    }

    /** @return list<ClientCreatedMail> */
    private function clientMails(): array
    {
        $sent = [];
        Mail::assertQueued(ClientCreatedMail::class, function (ClientCreatedMail $mail) use (&$sent) {
            $sent[] = $mail;

            return true;
        });

        return $sent;
    }

    private function to(ClientCreatedMail $mail): array
    {
        return array_column($mail->to, 'address');
    }

    #[Test]
    public function add_client_emails_the_partners_client_recipient(): void
    {
        $this->recipient($this->partner, 'clients@acme.test');
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $client = $this->addClient();

        $mails = $this->clientMails();
        $this->assertCount(1, $mails);
        $this->assertSame(['clients@acme.test'], $this->to($mails[0]));
        $this->assertSame($client->id, $mails[0]->borrower->id);
        $this->assertSame(Borrower::CREATED_VIA_MANUAL, $mails[0]->source);
    }

    #[Test]
    public function with_no_client_recipient_it_goes_to_the_partners_own_email(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->addClient();

        $this->assertSame(['owner@acme.test'], $this->to($this->clientMails()[0]));
    }

    #[Test]
    public function the_partner_is_also_told_in_its_bell(): void
    {
        $partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($partnerAdmin);

        $client = $this->addClient();

        Notification::assertSentTo($partnerAdmin, DynamicNotification::class, function (DynamicNotification $n) use ($partnerAdmin, $client) {
            return $n->typeKey === 'partner.client.created' && $n->toArray($partnerAdmin)['url'] === "/clients/{$client->id}";
        });
    }

    #[Test]
    public function a_lead_converted_to_a_client_emails_the_client_recipient_once(): void
    {
        $this->recipient($this->partner, 'clients@acme.test');
        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $lead = Lead::create([
            'partner_id' => $this->partner->id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lee-' . uniqid() . '@example.test',
            'phone_number' => '(555) 123-4567',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
        ]);

        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", ['is_free_trail' => true])->assertOk();

        $mails = $this->clientMails();
        $this->assertCount(1, $mails);
        $this->assertSame(['clients@acme.test'], $this->to($mails[0]));
        $this->assertSame(Borrower::CREATED_VIA_LEAD, $mails[0]->source);
    }

    #[Test]
    public function an_embedded_form_submission_converted_to_a_client_emails_the_client_recipient(): void
    {
        $this->recipient($this->host, 'host-clients@fch.test');
        $this->actingAsUser($this->makePartnerAdmin($this->host, ['view application', 'convert application']));
        $submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->host->id,
            'first_name' => 'Jimmy',
            'last_name' => 'Thornton',
            'email' => 'jimmy-' . uniqid() . '@example.test',
            'phone' => '+15122345678',
            'business_name' => 'Thornton Holdings LLC',
            'requested_amount' => '25000.00',
        ]);

        $this->postJson("/api/v1/common/embed-forms/submissions/{$submission->id}/convert-to-borrower")->assertOk();

        $mails = $this->clientMails();
        $this->assertCount(1, $mails);
        $this->assertSame(['host-clients@fch.test'], $this->to($mails[0]));
        $this->assertSame(Borrower::CREATED_VIA_EMBED_FORM, $mails[0]->source);
    }

    #[Test]
    public function a_client_that_fails_to_save_emails_no_one(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/common/borrowers', ['first_name' => ''])->assertUnprocessable();

        Mail::assertNotQueued(ClientCreatedMail::class);
    }

    #[Test]
    public function the_email_names_the_client_and_how_they_became_one(): void
    {
        $client = $this->makeBorrower($this->partner, ['first_name' => 'Cleo', 'last_name' => 'Client', 'business_name' => 'Cleo Co']);

        $added = (new ClientCreatedMail($client, Borrower::CREATED_VIA_MANUAL))->render();
        $converted = (new ClientCreatedMail($client, Borrower::CREATED_VIA_LEAD))->render();

        $this->assertStringContainsString('Cleo Client', $added);
        $this->assertStringContainsString('Cleo Co', $added);
        $this->assertStringContainsString(__('borrower.client_created_mail.sources.' . Borrower::CREATED_VIA_MANUAL), $added);
        $this->assertStringContainsString(__('borrower.client_created_mail.sources.' . Borrower::CREATED_VIA_LEAD), $converted);
    }
}
