<?php

namespace Tests\Feature\Lead;

use App\Http\Requests\Api\V1\FCH\Common\Lead\StoreLeadDetailsRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\UpdateLeadContactInfoRequest;
use App\Mail\Lead\LeadNotificationMail;
use App\Models\Lead\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A lead is created with its contact person and email, so the "New Lead
 * Created" mail can say who the lead is, and the Contact Info tab keeps
 * requiring both on save.
 */
class LeadContactRequiredTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_lead_requires_a_contact_person_and_email(): void
    {
        $errors = $this->errorsFor(new StoreLeadDetailsRequest(), $this->detailsPayload([
            'contact_person' => '',
            'email' => '',
        ]));

        $this->assertArrayHasKey('contact_person', $errors);
        $this->assertArrayHasKey('email', $errors);
    }

    #[Test]
    public function creating_a_lead_rejects_a_malformed_email(): void
    {
        $errors = $this->errorsFor(new StoreLeadDetailsRequest(), $this->detailsPayload([
            'email' => 'not-an-email',
        ]));

        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayNotHasKey('contact_person', $errors);
    }

    #[Test]
    public function creating_a_lead_with_both_passes(): void
    {
        $errors = $this->errorsFor(new StoreLeadDetailsRequest(), $this->detailsPayload());

        $this->assertArrayNotHasKey('contact_person', $errors);
        $this->assertArrayNotHasKey('email', $errors);
    }

    #[Test]
    public function saving_contact_info_requires_a_contact_person_and_email(): void
    {
        $errors = $this->errorsFor(new UpdateLeadContactInfoRequest(), [
            'contact_person' => '',
            'email' => '',
            'phone_number' => '(555) 555-5555',
            'country' => 'United States',
        ]);

        $this->assertArrayHasKey('contact_person', $errors);
        $this->assertArrayHasKey('email', $errors);
    }

    #[Test]
    public function the_new_lead_mail_shows_the_full_name_and_email(): void
    {
        $lead = new Lead([
            'lead_id' => 'LD-2026-1001-001',
            'company_name' => 'TBD',
            'contact_person' => 'Dave Johnson',
            'email' => 'dave@example.test',
            'lead_source' => 'Referral Partner',
        ]);

        $html = (new LeadNotificationMail($lead, ['type' => 'manual', 'is_pre_qualification' => false]))->render();

        $this->assertStringContainsString('Full Name', $html);
        $this->assertStringContainsString('Dave Johnson', $html);
        $this->assertStringContainsString('dave@example.test', $html);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function errorsFor($request, array $data): array
    {
        return Validator::make($data, $request->rules())->errors()->toArray();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function detailsPayload(array $overrides = []): array
    {
        return array_merge([
            'probability' => '50',
            'lead_type' => 'Borrower',
            'status' => 'New',
            'company_name' => 'Acme Inc',
            'contact_person' => 'Dave Johnson',
            'email' => 'dave-' . uniqid() . '@example.test',
        ], $overrides);
    }
}
