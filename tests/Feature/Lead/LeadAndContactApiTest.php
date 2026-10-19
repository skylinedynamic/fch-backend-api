<?php

namespace Tests\Feature\Lead;

use App\Http\Controllers\Api\V1\FCH\Common\Contact\ContactController;
use App\Http\Controllers\Api\V1\FCH\Common\Lead\LeadController;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Contact\Contact;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner portal's Leads (/common/leads*) and Contacts (/common/contacts*).
 * Both are a partner's own book: its leads and its contacts, no one else's.
 */
#[CoversClass(LeadController::class)]
#[CoversClass(ContactController::class)]
class LeadAndContactApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private Partner $otherPartner;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
    }

    private function leadOf(Partner $partner, array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'partner_id' => $partner->id,
            'company_name' => 'Lead Co ' . uniqid(),
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => 'Borrower',
            'status' => 'New',
            'probability' => 'High',
        ], $attributes));
    }

    private function contactOf(Partner $partner, array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'partner_id' => $partner->id,
            'first_name' => 'Connie',
            'last_name' => 'Contact',
            'email' => 'contact-' . uniqid() . '@example.test',
            'mobile_number' => '(555) 123-4567',
        ], $attributes));
    }

    private function leadDetails(array $overrides = []): array
    {
        return array_merge([
            'probability' => 'High',
            'lead_type' => 'Borrower',
            'status' => 'New',
            'company_name' => 'Fresh Lead Co',
            'contact_person' => 'Jane Doe',
            'lead_source' => 'Website',
        ], $overrides);
    }

    // ---- Leads -------------------------------------------------------------

    #[Test]
    public function the_lead_lists_hold_my_partners_leads_only(): void
    {
        $mine = $this->leadOf($this->partner);
        $this->leadOf($this->otherPartner);
        $this->actingAsUser($this->partnerAdmin);

        $this->assertSame([$mine->id], collect($this->getJson('/api/v1/common/leads')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([$mine->id], collect($this->postJson('/api/v1/common/leads/fetch-leads')->assertOk()->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function a_new_lead_is_filed_under_my_partner(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/leads/details', $this->leadDetails())->assertSuccessful()->json('data.id');

        $this->assertSame($this->partner->id, Lead::findOrFail($id)->partner_id);
    }

    #[Test]
    public function a_lead_needs_its_core_details(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/leads/details', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['probability', 'lead_type', 'status', 'company_name']);
        $this->postJson('/api/v1/common/leads/details', $this->leadDetails(['lead_type' => 'Alien']))
            ->assertStatus(422)->assertJsonValidationErrors(['lead_type']);
    }

    #[Test]
    public function i_open_edit_and_delete_my_lead(): void
    {
        $lead = $this->leadOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/leads/{$lead->id}")->assertOk()->assertJsonPath('data.id', $lead->id);
        $this->postJson("/api/v1/common/leads/{$lead->id}/details", $this->leadDetails(['company_name' => 'Renamed Co']))->assertOk();
        $this->assertSame('Renamed Co', $lead->fresh()->company_name);

        // One lead or many, deleting goes through the one bulk endpoint.
        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => [$lead->id]])->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertSoftDeleted($lead);
    }

    /**
     * Defect: show, both updates and delete looked the lead up by id alone
     * (only the list and convert were scoped), so any partner could read,
     * rewrite or delete another partner's leads.
     */
    #[Test]
    public function another_partners_lead_cannot_be_read_changed_or_deleted(): void
    {
        $theirs = $this->leadOf($this->otherPartner, ['company_name' => 'Theirs']);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/leads/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/v1/common/leads/{$theirs->id}/details", $this->leadDetails(['company_name' => 'Hijacked']))->assertNotFound();
        $this->postJson("/api/v1/common/leads/{$theirs->id}/contact-info", [
            'contact_person' => 'X', 'phone_number' => '(555) 000-0000', 'email' => 'x@example.test', 'country' => 'US',
        ])->assertNotFound();
        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => [$theirs->id]])->assertNotFound();

        $this->assertSame('Theirs', $theirs->fresh()->company_name);
        $this->assertNotSoftDeleted($theirs);
    }

    /**
     * The Leads screen deletes a selection in one call. It used to send one
     * DELETE per lead; the bulk endpoint existed but had no route, and deleted
     * by id alone with no activity log (findings O19).
     */
    #[Test]
    public function i_delete_several_of_my_leads_in_one_call(): void
    {
        [$a, $b, $kept] = [$this->leadOf($this->partner), $this->leadOf($this->partner), $this->leadOf($this->partner)];
        $this->actingAsUser($this->partnerAdmin);
        $logsBefore = ActivityLog::where('subject_type', Lead::class)->whereIn('subject_id', [$a->id, $b->id])->count();

        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
        $this->assertNotSoftDeleted($kept);
        $this->assertSame(
            $logsBefore + 2,
            ActivityLog::where('subject_type', Lead::class)->whereIn('subject_id', [$a->id, $b->id])->count(),
            'each deleted lead is logged, as a single delete is'
        );
    }

    #[Test]
    public function a_bulk_delete_never_touches_another_partners_leads(): void
    {
        $mine = $this->leadOf($this->partner);
        $theirs = $this->leadOf($this->otherPartner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => [$mine->id, $theirs->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);
        $this->assertSoftDeleted($mine);
        $this->assertNotSoftDeleted($theirs);

        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => [$theirs->id]])->assertNotFound();
        $this->assertNotSoftDeleted($theirs);
    }

    #[Test]
    public function a_bulk_delete_needs_a_list_of_lead_ids(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/leads/multiple-delete', [])->assertStatus(422)->assertJsonValidationErrors(['ids']);
        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => []])->assertStatus(422)->assertJsonValidationErrors(['ids']);
        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => 5])->assertStatus(422)->assertJsonValidationErrors(['ids']);
        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => ['abc']])->assertStatus(422)->assertJsonValidationErrors(['ids.0']);
    }

    #[Test]
    public function another_partners_lead_cannot_be_converted(): void
    {
        $theirs = $this->leadOf($this->otherPartner);
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->postJson("/api/v1/common/leads/{$theirs->id}/convert", []);

        $this->assertNotSame(200, $response->status());
        $this->assertNull($theirs->fresh()->converted_at ?? null);
    }

    // ---- Contacts ----------------------------------------------------------

    #[Test]
    public function the_contact_lists_hold_my_partners_contacts_only(): void
    {
        $mine = $this->contactOf($this->partner);
        $this->contactOf($this->otherPartner);
        $this->actingAsUser($this->partnerAdmin);

        $this->assertSame([$mine->id], collect($this->getJson('/api/v1/common/contacts')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([$mine->id], collect($this->postJson('/api/v1/common/contacts/fetch-contacts')->assertOk()->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function a_new_contact_is_filed_under_my_partner(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        // Defect (O18): the email was checked with email:rfc,dns - a live DNS
        // lookup on every save, so this needed a resolving domain and network
        // access. A well-formed address on a domain with no mail records
        // (example.test) is a valid address to record.
        $id = $this->postJson('/api/v1/common/contacts', [
            'first_name' => 'Rae', 'last_name' => 'Referrer',
            'email' => 'rae.referrer@example.test', 'mobile_number' => '(555) 222-3333',
            'is_referral_partner' => true,
        ])->assertSuccessful()->json('data.id');

        $this->assertSame($this->partner->id, Contact::findOrFail($id)->partner_id);
    }

    #[Test]
    public function a_malformed_contact_email_is_still_refused(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/contacts', [
            'first_name' => 'Rae', 'last_name' => 'Referrer',
            'email' => 'not an email', 'mobile_number' => '(555) 222-3333',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function a_contact_needs_a_name_email_and_a_formatted_mobile_number(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/contacts', ['mobile_number' => '5551234567'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'email', 'mobile_number']);
    }

    #[Test]
    public function i_open_edit_and_delete_my_contact(): void
    {
        $contact = $this->contactOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/contacts/{$contact->id}")->assertOk()->assertJsonPath('data.id', $contact->id);
        $this->putJson("/api/v1/common/contacts/{$contact->id}", [
            'first_name' => 'Renamed', 'email' => $contact->email, 'mobile_number' => '(555) 123-4567',
        ])->assertOk();
        $this->assertSame('Renamed', $contact->fresh()->first_name);

        $this->deleteJson("/api/v1/common/contacts/{$contact->id}")->assertOk();
        $this->assertSoftDeleted($contact);
    }

    /**
     * Defect: show looked the contact up by id alone, and multiple-delete
     * removed the image files of any ids sent (the records were scoped).
     */
    #[Test]
    public function another_partners_contact_cannot_be_read_and_its_files_survive_a_bulk_delete(): void
    {
        Storage::disk('public')->put('contacts/theirs.jpg', 'x');
        $theirs = $this->contactOf($this->otherPartner, ['image' => 'contacts/theirs.jpg']);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/contacts/{$theirs->id}")->assertNotFound();
        $this->putJson("/api/v1/common/contacts/{$theirs->id}", ['first_name' => 'X', 'email' => 'x@example.test', 'mobile_number' => '(555) 123-4567'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/common/contacts/{$theirs->id}")->assertForbidden();
        $this->postJson('/api/v1/common/contacts/multiple-delete', ['ids' => [$theirs->id]]);

        $this->assertNotSoftDeleted($theirs);
        Storage::disk('public')->assertExists('contacts/theirs.jpg');
    }
}
