<?php

namespace Tests\Integration\Mail;

use App\Mail\Appointment\AppointmentNotification;
use App\Mail\Borrower\BorrowerDocumentsUploadedMail;
use App\Mail\Borrower\CreditProfileRequestMail;
use App\Mail\Borrower\DraftApplicationSentMail;
use App\Mail\Borrower\FundingApplicationSubmittedMail;
use App\Mail\Borrower\UpdateCreditProfileRequestMail;
use App\Mail\EmbedForm\EmbedFormConverted;
use App\Mail\EmbedForm\NewSubmissionMail;
use App\Mail\FundingRequest\BorrowerFundingApplicationReminder;
use App\Mail\FundingRequest\BorrowerFundingRequestApproved;
use App\Mail\FundingRequest\PartnerFundingApplicationReminder;
use App\Mail\FundingRequest\PartnerFundingRequestApproved;
use App\Mail\FundingRequest\UnderwritingStatusUpdated;
use App\Mail\FundingRequest\UnderwritingSubmitted;
use App\Mail\Invoice\InvoiceMail;
use App\Mail\Lead\LeadNotificationMail;
use App\Mail\Partner\BorrowerFeeInvoiceNotificationMail;
use App\Mail\Partner\BorrowerSubmittedFundingApplicationMail;
use App\Mail\Partner\FundingApplicationSubmittedMail as PartnerFundingApplicationSubmittedMail;
use App\Mail\Partner\NewChangeRequestSubmittedMail;
use App\Mail\PreQualificationSubmitted;
use App\Models\Appointment\Appointment;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Invoice\Invoice;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Every email built from a record - funding requests and applications, credit
 * profile requests, leads, pre-qualifications, appointments, embed-form
 * submissions, invoices and change requests - renders as the queue worker
 * would render it, and the record's own details reach the body.
 */
class RecordMailRenderTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $user;
    private Borrower $borrower;
    private FundingRequest $request;
    private FundingApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->host = $this->makeHostPartner(['company_name' => 'Four Corner Funding']);
        $this->partner = $this->makeWhiteLabelPartner(['company_name' => 'Lakeside Capital']);
        $this->user = $this->makeBorrowerUser($this->partner, ['first_name' => 'Dana', 'last_name' => 'Whitfield', 'business_name' => 'Whitfield Bakery LLC'], ['name' => 'Dana Whitfield']);
        $this->borrower = Borrower::findOrFail($this->user->entity_id);

        $sequence = DB::table('funding_sequencings')->insertGetId(['name' => 'Business Lines of Credit', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->request = FundingRequest::findOrFail(DB::table('funding_requests')->insertGetId([
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'funding_sequence_id' => $sequence,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 75000,
            'use_of_funds' => 'Second oven',
            'status' => FundingRequest::STATUS_APPROVED,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->application = FundingApplication::findOrFail(DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-20260615-0007',
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'funding_request_id' => $this->request->id,
            'funding_sequence_id' => $sequence,
            'first_name' => 'Dana',
            'last_name' => 'Whitfield',
            'business_name' => 'Whitfield Bakery LLC',
            'business_email' => 'books@whitfield.test',
            'business_phone_no' => '+1 555 0142',
            'status' => FundingApplication::ON_HOLD,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function creditRequest(): CreditProfileRequest
    {
        return (new CreditProfileRequest())->forceFill([
            'id' => 31, 'borrower_id' => $this->borrower->id, 'first_name' => 'Dana', 'middle_name' => null, 'last_name' => 'Whitfield',
            'email' => 'dana@example.test', 'phone' => '+1 555 0142', 'address' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '73301',
            'ssn' => '123-45-6789', 'date_of_birth' => '1985-04-02',
        ]);
    }

    private function lead(): Lead
    {
        return (new Lead())->forceFill([
            'lead_id' => 'LEAD-0091', 'company_name' => 'Harbor Freight Co', 'contact_person' => 'Sam Ortiz', 'email' => 'sam@harbor.test',
            'phone_number' => '+1 555 0177', 'domain' => 'harbor.test', 'lead_source' => Lead::LEAD_SOURCE_WEBSITE,
            'requirement_details' => 'Needs equipment financing', 'created_at' => now(),
        ]);
    }

    private function preQualification(): PreQualificationRequest
    {
        return (new PreQualificationRequest())->forceFill([
            'partner_id' => $this->partner->id, 'first_name' => 'Dana', 'last_name' => 'Whitfield', 'email' => 'dana@example.test',
            'phone_number' => '+1 555 0142', 'business_name' => 'Whitfield Bakery LLC', 'industry_type' => 'Food service',
            'state_of_incorporation' => 'TX', 'years_in_business' => '3', 'annual_revenue' => 480000, 'monthly_revenue' => 40000,
            'funding_requested_amount' => 75000, 'personal_credit_score' => '720', 'purpose_of_funding' => 'Second oven', 'notes' => null,
        ]);
    }

    private function appointment(): Appointment
    {
        return (new Appointment())->forceFill([
            'subject' => 'Underwriting review call', 'description' => 'Walk through bank statements',
            'appointment_date' => Carbon::parse('2026-06-20 15:00:00'), 'duration_minutes' => 30, 'timezone' => 'America/Chicago',
        ])->setRelation('assignedFor', $this->user);
    }

    private function submission(): EmbedFormSubmission
    {
        return (new EmbedFormSubmission())->forceFill([
            'submission_uid' => 'SUB-7Q2X', 'first_name' => 'Dana', 'last_name' => 'Whitfield', 'email' => 'dana@example.test',
            'phone' => '+1 555 0142', 'business_name' => 'Whitfield Bakery LLC', 'industry_type' => 'Food service', 'employer_name' => null,
            'monthly_revenue' => 40000, 'years_in_business' => 3, 'requested_amount' => 75000, 'preferred_funding_type' => 'Line of Credit',
            'purpose_of_funds' => 'Second oven', 'self_reported_personal_credit_score' => 720, 'created_at' => now(),
        ])->setRelation('attachments', collect())->setRelation('coBorrowers', collect());
    }

    private function invoice(): Invoice
    {
        return (new Invoice())->forceFill([
            'id' => 88, 'invoice_id' => 'INV-0088', 'partner_id' => $this->partner->id, 'customer_name' => 'Whitfield Bakery LLC',
            'payment_amount' => 249, 'status' => 'Unpaid', 'is_partner_paying' => false, 'created_at' => now(),
        ]);
    }

    private function changeRequest(): BorrowerChangeRequest
    {
        return (new BorrowerChangeRequest())->forceFill([
            'id' => 12, 'partner_id' => $this->partner->id, 'borrower_id' => $this->borrower->id, 'field_name' => 'business_phone',
            'visible_field_name' => 'Business Phone', 'old_value' => '+1 555 0142', 'new_value' => '+1 555 0199', 'status' => BorrowerChangeRequest::STATUS_PENDING,
        ])->setRelation('borrower', $this->borrower);
    }

    public static function mailables(): array
    {
        return [
            'borrower: request approved' => [fn (self $t) => new BorrowerFundingRequestApproved($t->request), '75,000'],
            'partner: request approved' => [fn (self $t) => new PartnerFundingRequestApproved($t->request, $t->partner), 'Business Line of Credit'],
            'borrower: application reminder' => [fn (self $t) => new BorrowerFundingApplicationReminder($t->request), 'Business Line of Credit'],
            'partner: application reminder' => [fn (self $t) => new PartnerFundingApplicationReminder($t->request, $t->partner), 'Business Line of Credit'],
            'borrower: underwriting status' => [fn (self $t) => new UnderwritingStatusUpdated($t->request), 'Second oven'],
            'host: underwriting submitted' => [fn (self $t) => new UnderwritingSubmitted($t->request), 'Second oven'],
            'borrower: application submitted' => [fn (self $t) => new FundingApplicationSubmittedMail($t->application), 'FUND-20260615-0007'],
            'borrower: draft application sent' => [fn (self $t) => new DraftApplicationSentMail($t->application), 'FUND-20260615-0007'],
            'partner: borrower submitted application' => [fn (self $t) => new BorrowerSubmittedFundingApplicationMail($t->application), 'books@whitfield.test'],
            'partner: application submitted' => [fn (self $t) => new PartnerFundingApplicationSubmittedMail($t->application), 'books@whitfield.test'],
            'credit profile request' => [fn (self $t) => new CreditProfileRequestMail($t->creditRequest(), $t->partner->id), 'dana@example.test'],
            'credit profile request updated' => [fn (self $t) => new UpdateCreditProfileRequestMail($t->creditRequest(), $t->partner->id), 'dana@example.test'],
            'lead created' => [fn (self $t) => new LeadNotificationMail($t->lead(), ['type' => 'website']), 'Harbor Freight Co'],
            'pre-qualification submitted' => [fn (self $t) => new PreQualificationSubmitted($t->preQualification()), 'Whitfield Bakery LLC'],
            'appointment created' => [fn (self $t) => new AppointmentNotification($t->appointment(), 'created'), 'Walk through bank statements'],
            'appointment updated' => [fn (self $t) => new AppointmentNotification($t->appointment(), 'updated'), 'Walk through bank statements'],
            'appointment cancelled' => [fn (self $t) => new AppointmentNotification($t->appointment(), 'deleted'), '20 June, 2026'],
            'embed form: new submission' => [fn (self $t) => new NewSubmissionMail($t->submission()), 'SUB-7Q2X'],
            'embed form: converted (staff)' => [fn (self $t) => new EmbedFormConverted($t->submission(), $t->application, true), 'FUND-20260615-0007'],
            'embed form: converted (applicant)' => [fn (self $t) => new EmbedFormConverted($t->submission(), $t->application, false), 'FUND-20260615-0007'],
            'invoice sent' => [fn (self $t) => new InvoiceMail($t->invoice()), 'INV-0088'],
            'partner: borrower fee invoice' => [fn (self $t) => new BorrowerFeeInvoiceNotificationMail($t->invoice(), $t->borrower, $t->partner, 'Four Corner Funding', $t->host->id), 'INV-0088'],
            'host: change request submitted' => [fn (self $t) => new NewChangeRequestSubmittedMail($t->host, $t->changeRequest()), 'Business Phone'],
            'partner: documents uploaded' => [fn (self $t) => new BorrowerDocumentsUploadedMail($t->user, [(new BorrowerDocument())->forceFill(['document_number' => 'DOC-301', 'file_name' => 'bank-statement-may.pdf', 'created_at' => now()])]), 'bank-statement-may.pdf'],
        ];
    }

    #[Test]
    #[DataProvider('mailables')]
    public function the_email_renders_with_its_record(Closure $make, string $expected): void
    {
        /** @var Mailable $mail */
        $mail = $make($this);

        $html = $mail->render();

        $this->assertStringContainsString($expected, $html);
    }

    /**
     * Defect: three borrower emails built their portal link with
     * make_url(getBorrowerDomain(...)), and getBorrowerDomain() is null for a
     * white-label partner that has not set its own borrower domain. make_url()
     * takes a string, so rendering threw and the queued email failed - the
     * approval, the daily "start your application" reminder and the
     * underwriting update never reached the borrower.
     */
    #[Test]
    public function borrower_links_fall_back_to_the_portal_when_the_partner_has_no_domain_of_its_own(): void
    {
        config(['app.frontend_borrower_url' => 'https://app.fourcorner.example']);
        $this->assertEmpty($this->partner->borrower_domain);

        $this->assertStringContainsString("https://app.fourcorner.example/fund-application/{$this->request->id}", (new BorrowerFundingRequestApproved($this->request))->render());
        $this->assertStringContainsString("https://app.fourcorner.example/fund-application/{$this->request->id}", (new BorrowerFundingApplicationReminder($this->request))->render());
        $this->assertStringContainsString("https://app.fourcorner.example/funding-requests/{$this->request->id}", (new UnderwritingStatusUpdated($this->request))->render());

        $this->partner->forceFill(['borrower_domain' => 'portal.lakeside.example'])->save();
        $this->assertStringContainsString("portal.lakeside.example/fund-application/{$this->request->id}", (new BorrowerFundingRequestApproved($this->request->fresh()))->render());
    }

    /**
     * Defect (O47): the appointment email accepted the partner to brand it as,
     * then ignored it and branded it by whoever happened to be signed in.
     */
    #[Test]
    public function the_appointment_email_is_branded_as_the_partner_it_is_given(): void
    {
        $mail = new AppointmentNotification($this->appointment(), 'created', [], $this->partner->id);

        $property = new \ReflectionProperty($mail, 'mailPartnerId');
        $this->assertSame($this->partner->id, $property->getValue($mail));
    }

    /** Defect (O47): every underwriting email logged its whole branding array at info. */
    #[Test]
    public function the_underwriting_email_does_not_log_its_branding(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        (new UnderwritingSubmitted($this->request))->render();

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('info', fn ($message) => $message === 'Branding');
    }

    /**
     * Defect (O48): the applicant's "converted" email had no sign-in button when
     * the partner had no borrower domain of its own - unlike the other borrower
     * emails, it did not fall back to the main portal.
     */
    #[Test]
    public function the_converted_applicant_email_falls_back_to_the_portal_sign_in(): void
    {
        config(['app.frontend_borrower_url' => 'https://app.fourcorner.example']);
        $this->assertEmpty($this->partner->borrower_domain);

        $html = (new EmbedFormConverted($this->submission(), $this->application, false))->render();

        $this->assertStringContainsString('https://app.fourcorner.example/login', $html);
    }

    /**
     * Defect: the status-update email is sent to the borrower and to a non-host
     * partner, but its button always opened the borrower portal's funding
     * request list - the partner landed on a portal they cannot sign in to, and
     * neither reader landed on the request itself.
     */
    #[Test]
    public function the_status_update_email_links_each_reader_to_the_request_in_their_own_portal(): void
    {
        config(['app.frontend_borrower_url' => 'https://app.fourcorner.example']);
        $this->partner->forceFill(['partner_domain' => 'https://partners.lakeside.example'])->save();
        $request = $this->request->fresh();

        $borrowerHtml = (new UnderwritingStatusUpdated($request))->render();
        $this->assertStringContainsString("https://app.fourcorner.example/funding-requests/{$request->id}", $borrowerHtml);
        $this->assertStringNotContainsString('intake-review', $borrowerHtml);

        $partnerHtml = (new UnderwritingStatusUpdated($request, UnderwritingStatusUpdated::AUDIENCE_PARTNER))->render();
        $this->assertStringContainsString("https://partners.lakeside.example/intake-review/funding-request/{$request->id}", $partnerHtml);
        $this->assertStringNotContainsString('/funding-requests/', $partnerHtml);
    }

    /** Defect (D12): the host's "change requested" email showed an EIN's old and new value in full. */
    #[Test]
    public function the_host_change_request_email_masks_an_ein(): void
    {
        $request = (new BorrowerChangeRequest())->forceFill([
            'id' => 13, 'partner_id' => $this->partner->id, 'borrower_id' => $this->borrower->id, 'field_name' => 'business_ein',
            'visible_field_name' => 'Business EIN No', 'old_value' => '98-7654321', 'new_value' => '12-3456789', 'status' => BorrowerChangeRequest::STATUS_PENDING,
        ])->setRelation('borrower', $this->borrower);

        $html = (new NewChangeRequestSubmittedMail($this->host, $request))->render();

        $this->assertStringContainsString('**-***6789', $html);
        $this->assertStringContainsString('**-***4321', $html);
        $this->assertStringNotContainsString('3456789', $html);
        $this->assertStringNotContainsString('7654321', $html);
    }
}
