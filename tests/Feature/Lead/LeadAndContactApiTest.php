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
            'mobile_number' => '(555) 234-5678',
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
    public function a_leads_contact_info_is_checked_against_the_shared_formats(): void
    {
        $lead = $this->leadOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", [
            'contact_person' => 'Jane Doe', 'email' => 'jane@example.test', 'country' => 'US',
            'phone_number' => '(124) 982-7457', 'business_phone' => '555-0100',
            'business_ein' => 'AB-CDEFGHI', 'duns_number' => '12345678', 'zip_code' => '1234',
        ])->assertStatus(422)->assertJsonValidationErrors([
            'phone_number' => __('embed_form.phone_invalid'),
            'business_phone' => __('embed_form.phone_invalid'),
            'business_ein' => __('common.business_ein_invalid'),
            'duns_number' => __('common.duns_number_invalid'),
            'zip_code' => __('common.postal_code_invalid'),
        ]);
    }

    private function contactInfo(array $overrides = []): array
    {
        return array_merge(['contact_person' => 'Jane Doe', 'email' => 'jane@example.test', 'phone_number' => '(555) 234-5678', 'country' => 'United States'], $overrides);
    }

    /**
     * A lead's SSN is the full number, as on a client's profile, kept
     * encrypted - it used to be the last four digits only, in plain text. The
     * lead's details carry it masked (R3-BUG-004); see LeadSensitiveFieldRevealTest.
     */
    #[Test]
    public function a_leads_ssn_is_the_full_number_kept_encrypted(): void
    {
        $lead = $this->leadOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", $this->contactInfo(['ssn' => '123-45-6789']))->assertOk();

        $this->assertSame('123456789', $lead->fresh()->ssn);
        $this->assertStringNotContainsString('6789', (string) $lead->fresh()->getRawOriginal('ssn_encrypted'));
        $this->getJson("/api/v1/common/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.ssn', '***-**-6789')
            ->assertJsonMissingPath('data.ssn_last_4');
    }

    #[Test]
    public function a_leads_ssn_must_be_a_valid_full_ssn_and_stays_optional(): void
    {
        $lead = $this->leadOf($this->partner, ['ssn' => '123456789']);
        $this->actingAsUser($this->partnerAdmin);

        foreach (['6789', '12-345', '000-12-3456', 'abc-de-fghi'] as $bad) {
            $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", $this->contactInfo(['ssn' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['ssn' => __('common.ssn_invalid')]);
        }

        // The last four alone are no longer taken.
        $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", $this->contactInfo(['ssn_last_4' => '1111']))->assertOk();
        $this->assertSame('123456789', $lead->fresh()->ssn);

        $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", $this->contactInfo(['ssn' => null]))->assertOk();
        $this->assertNull($lead->fresh()->ssn);
    }

    /**
     * BUG-017: the admin form now sends an empty lead instead of stopping at
     * Contact Person, so the API must name every required field in one go.
     */
    #[Test]
    public function an_empty_lead_gets_every_required_error_at_once(): void
    {
        $lead = $this->leadOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/leads/details', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'probability' => __('lead.probability_required'),
                'lead_type' => __('lead.lead_type_required'),
                'status' => __('lead.status_required'),
                'company_name' => __('lead.company_name_required'),
                'contact_person' => __('lead.contact_person_required'),
            ]);

        $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'contact_person' => __('lead.contact_person_required'),
                'email' => __('lead.email_required'),
                'phone_number' => __('lead.phone_number_required'),
                'country' => __('lead.country_required'),
            ]);
    }

    /** BUG-018: Lead Source is optional on create, so it stays optional on update. */
    #[Test]
    public function a_lead_created_without_a_lead_source_can_be_updated_without_one(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $id = $this->postJson('/api/v1/common/leads/details', $this->leadDetails(['lead_source' => null]))
            ->assertSuccessful()
            ->json('data.id');

        $this->postJson("/api/v1/common/leads/{$id}/details", $this->leadDetails(['lead_source' => null, 'status' => 'Working']))
            ->assertOk()
            ->assertJsonMissingValidationErrors('lead_source');
        $this->assertSame('Working', Lead::find($id)->status);
    }

    /** BUG-021: QA's values - an EIN of "12.5" and a DUNS of "12" - are refused. */
    #[Test]
    public function a_fractional_ein_and_a_short_duns_are_refused(): void
    {
        $lead = $this->leadOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", $this->contactInfo(['business_ein' => '12.5', 'duns_number' => '12']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'business_ein' => __('common.business_ein_invalid'),
                'duns_number' => __('common.duns_number_invalid'),
            ]);
        $this->assertNull($lead->fresh()->business_ein);
    }

    #[Test]
    public function a_leads_ein_is_kept_as_its_nine_digits(): void
    {
        $lead = $this->leadOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", $this->contactInfo(['business_ein' => '53-4534534']))->assertOk();
        $this->assertSame('534534534', $lead->fresh()->business_ein);

        $this->postJson("/api/v1/common/leads/{$lead->id}/contact-info", $this->contactInfo(['business_ein' => 'AB-CDEFGHI']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_ein' => __('common.business_ein_invalid')]);
    }

    #[Test]
    public function converting_a_lead_carries_its_full_ssn_to_the_new_client(): void
    {
        $lead = $this->leadOf($this->partner, ['ssn' => '123456789', 'phone_number' => '(555) 234-5678']);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", ['is_free_trail' => true])->assertSuccessful();

        $this->assertSame('123456789', \App\Models\Borrower\Borrower::where('email', $lead->email)->firstOrFail()->ssn);
    }

    #[Test]
    public function a_contacts_mobile_must_be_a_real_us_number(): void
    {
        // Written correctly, but area code 124 does not exist.
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/contacts', ['first_name' => 'Rae', 'email' => 'rae@example.test', 'mobile_number' => '(124) 982-7457'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile_number' => __('embed_form.phone_invalid')]);
    }

    /** R3-BUG-018: an unformatted mobile was answered with the raw key "contact.mobile_number_invalid". */
    #[Test]
    public function an_unformatted_contact_mobile_gets_a_readable_message_on_add_and_edit(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $expected = 'Enter a valid 10-digit US mobile number, e.g. (555) 234-5678.';

        $this->postJson('/api/v1/common/contacts', ['first_name' => 'Rae', 'last_name' => 'Ray', 'email' => 'rae@example.test', 'mobile_number' => '2025550199'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile_number' => $expected]);

        $contact = $this->contactOf($this->partner);
        $this->putJson("/api/v1/common/contacts/{$contact->id}", ['first_name' => 'Rae', 'email' => 'rae@example.test', 'mobile_number' => '2025550199'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile_number' => $expected]);
    }

    #[Test]
    public function another_partners_lead_cannot_be_read_changed_or_deleted(): void
    {
        $theirs = $this->leadOf($this->otherPartner, ['company_name' => 'Theirs']);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/leads/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/v1/common/leads/{$theirs->id}/details", $this->leadDetails(['company_name' => 'Hijacked']))->assertNotFound();
        $this->postJson("/api/v1/common/leads/{$theirs->id}/contact-info", [
            'contact_person' => 'X', 'phone_number' => '(555) 200-0000', 'email' => 'x@example.test', 'country' => 'US',
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
    /**
     * A lead already converted to a borrower or partner is kept: the delete is
     * refused with the reason, and nothing is touched - not the lead, not the
     * borrower made from it, not the other selected leads.
     */
    #[Test]
    public function a_converted_lead_is_not_deleted_and_the_user_is_told_why(): void
    {
        $converted = $this->leadOf($this->partner, ['contact_person' => 'Wanda Lead', 'convert_to' => 'Borrower', 'status' => Lead::STATUS_LEAD_COMPLETED]);
        $borrower = $this->makeBorrower($this->partner, ['email' => $converted->email]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => [$converted->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This lead has already been converted and cannot be deleted.')
            ->assertJsonPath('errors.converted', [['id' => $converted->id, 'name' => 'Wanda Lead']]);

        $this->assertNotSoftDeleted($converted);
        $this->assertNotSoftDeleted($borrower);
    }

    #[Test]
    public function when_any_selected_lead_is_converted_none_are_deleted_and_each_converted_one_is_named(): void
    {
        $open = $this->leadOf($this->partner, ['contact_person' => 'Open Lead']);
        $first = $this->leadOf($this->partner, ['contact_person' => 'Wanda Lead', 'convert_to' => 'Borrower']);
        $second = $this->leadOf($this->partner, ['contact_person' => 'Pat Partner', 'convert_to' => 'Partner']);
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->postJson('/api/v1/common/leads/multiple-delete', ['ids' => [$open->id, $first->id, $second->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Some selected leads have already been converted and cannot be deleted: Wanda Lead, Pat Partner. '
                . 'Unselect them to delete the others.');

        $this->assertSame(['Wanda Lead', 'Pat Partner'], array_column($response->json('errors.converted'), 'name'));
        $this->assertNotSoftDeleted($open);
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
    }

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

        $this->postJson('/api/v1/common/contacts', ['mobile_number' => '5552345678'])
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
            'first_name' => 'Renamed', 'email' => $contact->email, 'mobile_number' => '(555) 234-5678',
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
        $this->putJson("/api/v1/common/contacts/{$theirs->id}", ['first_name' => 'X', 'email' => 'x@example.test', 'mobile_number' => '(555) 234-5678'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/common/contacts/{$theirs->id}")->assertForbidden();
        $this->postJson('/api/v1/common/contacts/multiple-delete', ['ids' => [$theirs->id]]);

        $this->assertNotSoftDeleted($theirs);
        Storage::disk('public')->assertExists('contacts/theirs.jpg');
    }
}
