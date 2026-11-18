<?php

namespace Tests\Feature\Contact;

use App\Http\Controllers\Api\V1\FCH\Common\Contact\ContactController;
use App\Models\Contact\Contact;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-017: a contact that is a lead's Referral Contact is not deleted -
 * the user is told which leads use it. A deleted contact can't be chosen as
 * a Referral Contact, and leads already left pointing at one are cleared.
 */
#[CoversClass(ContactController::class)]
class ContactUsedAsReferralTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->partnerAdmin);
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'partner_id' => $this->partner->id,
            'first_name' => 'QA-TEST R3',
            'last_name' => 'Cont02',
            'email' => 'contact-' . uniqid() . '@example.test',
            'mobile_number' => '(555) 234-5678',
        ], $attributes));
    }

    private function leadReferredBy(?Contact $contact, array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'partner_id' => $this->partner->id,
            'company_name' => 'Captcha Co',
            'contact_person' => 'QA-TEST R3 Captcha',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => 'Borrower',
            'status' => 'New',
            'probability' => 'High',
            'lead_source' => Lead::LEAD_SOURCE_REFERRAL_PARTNER,
            'referral_partner_id' => $contact?->id,
        ], $attributes));
    }

    #[Test]
    public function a_referral_contact_is_not_deleted_and_the_leads_are_named(): void
    {
        $contact = $this->contact();
        $lead = $this->leadReferredBy($contact);

        $this->deleteJson("/api/v1/common/contacts/{$contact->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This contact is the Referral Contact on 1 lead (QA-TEST R3 Captcha). Choose another Referral Contact there first.')
            ->assertJsonPath('errors.leads', [['id' => $lead->id, 'name' => 'QA-TEST R3 Captcha']]);

        $this->assertNotSoftDeleted($contact);
        $this->assertSame($contact->id, $lead->fresh()->referral_partner_id);
    }

    #[Test]
    public function a_bulk_delete_with_a_referral_contact_deletes_none_and_names_it(): void
    {
        $free = $this->contact(['first_name' => 'Free', 'last_name' => 'Contact']);
        $used = $this->contact();
        $this->leadReferredBy($used);
        $this->leadReferredBy($used, ['contact_person' => 'Second Lead']);

        $response = $this->postJson('/api/v1/common/contacts/multiple-delete', ['ids' => [$free->id, $used->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Some selected contacts are the Referral Contact on a lead and cannot be deleted: QA-TEST R3 Cont02. Unselect them to delete the others, or choose another Referral Contact on those leads.');

        $this->assertSame([['id' => $used->id, 'name' => 'QA-TEST R3 Cont02', 'lead_count' => 2]], $response->json('errors.contacts'));
        $this->assertNotSoftDeleted($free);
        $this->assertNotSoftDeleted($used);
    }

    #[Test]
    public function a_contact_no_lead_uses_is_still_deleted(): void
    {
        $contact = $this->contact();
        $this->leadReferredBy(null, ['lead_source' => 'Website']);

        $this->deleteJson("/api/v1/common/contacts/{$contact->id}")->assertOk();
        $this->assertSoftDeleted($contact);

        $other = $this->contact();
        $this->postJson('/api/v1/common/contacts/multiple-delete', ['ids' => [$other->id]])->assertOk();
        $this->assertSoftDeleted($other);
    }

    #[Test]
    public function a_deleted_lead_does_not_hold_its_referral_contact(): void
    {
        $contact = $this->contact();
        $this->leadReferredBy($contact)->delete();

        $this->deleteJson("/api/v1/common/contacts/{$contact->id}")->assertOk();
        $this->assertSoftDeleted($contact);
    }

    #[Test]
    public function a_deleted_contact_cannot_be_chosen_as_a_referral_contact(): void
    {
        $gone = $this->contact();
        $gone->delete();
        $lead = $this->leadReferredBy(null, ['lead_source' => 'Website']);

        $details = [
            'probability' => 'High',
            'lead_type' => 'Borrower',
            'status' => 'New',
            'company_name' => 'Captcha Co',
            'contact_person' => 'QA-TEST R3 Captcha',
            'lead_source' => Lead::LEAD_SOURCE_REFERRAL_PARTNER,
            'referral_partner_id' => $gone->id,
        ];

        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $details)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_partner_id');
        $this->postJson('/api/v1/common/leads/details', $details)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_partner_id');
    }

    #[Test]
    public function leads_left_on_a_deleted_contact_are_cleared(): void
    {
        $gone = $this->contact();
        $live = $this->contact(['first_name' => 'Live']);
        $stale = $this->leadReferredBy($gone);
        $fine = $this->leadReferredBy($live);
        $gone->delete();

        $migration = require database_path('migrations/2026_11_15_000001_clear_deleted_referral_contacts_from_leads.php');
        $migration->up();

        $this->assertNull($stale->fresh()->referral_partner_id);
        $this->assertSame($live->id, $fine->fresh()->referral_partner_id);
    }
}
