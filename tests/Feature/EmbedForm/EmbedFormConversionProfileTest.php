<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Common\Permission;
use App\Models\EmbedForm\EmbedFormCoBorrower;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * What a converted embedded form submission puts on the borrower's profile.
 *
 * The applicant already answered the profile's questions on the public form, so
 * the borrower should not open a half-empty profile: every field that has a
 * counterpart is carried across. Phones are stored the way the portals store
 * them - (555) 234-5678 with country code +1 - not the form's +15552345678.
 *
 * Not carried: years in business (the profile derives it from the business
 * start date; the form only has a range) and the business credit score (a range,
 * where the profile's PAYDEX is one number).
 */
class EmbedFormConversionProfileTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();
        Notification::fake();

        $this->partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $owner = User::create([
            'name' => 'Host Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::PARTNER,
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
        ]);

        // Converting is behind permission:convert application. Lazy loading is
        // off, so the relations Spatie reads are loaded first.
        $owner = User::with('permissions', 'roles')->findOrFail($owner->id);
        foreach (['view application', 'convert application'] as $name) {
            $owner->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Passport::actingAs(User::with('permissions', 'roles', 'partner')->findOrFail($owner->id));
    }

    private function submission(array $overrides = []): EmbedFormSubmission
    {
        return EmbedFormSubmission::create(array_merge([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'first_name' => 'Odessa',
            'middle_name' => 'Jo',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
            'phone' => '+15122345678',
            'date_of_birth' => '1986-09-01',
            'ssn_encrypted' => Crypt::encryptString('123456789'),
            'street_address' => '270 Sharulia Road',
            'city' => 'Austin',
            'state' => 'Texas',
            'zip_code' => '78701',
            'country' => 'United States',
            'business_name' => 'Mccall Holdings LLC',
            'business_email' => 'office@mccall.example.test',
            'business_phone_no' => '+15129876543',
            'business_address' => '1 Commerce St',
            'business_city' => 'Dallas',
            'business_state' => 'Texas',
            'business_postal_code' => '75201',
            'business_country' => 'United States',
            'business_country_code' => '+1',
            'entity_type' => 'C-Corporation',
            'industry_type' => 'Real Estate (Non-Lending)',
            'number_of_employees' => 140,
            'years_in_business' => '2-3 years',
            'monthly_revenue' => '50000.00',
            'annual_revenue' => '600000.00',
            'self_reported_personal_credit_score' => '712',
            'self_reported_business_credit_score' => '50-64',
            'has_business_debt' => true,
            'debt_balance' => '25000.00',
        ], $overrides));
    }

    private function convert(EmbedFormSubmission $submission, array $body = [])
    {
        return $this->postJson("/api/v1/common/embed-forms/submissions/{$submission->id}/convert-to-borrower", $body);
    }

    private function convertedBorrower(EmbedFormSubmission $submission): Borrower
    {
        return Borrower::findOrFail($submission->fresh()->converted_borrower_id);
    }

    // -- A new borrower -------------------------------------------------------

    #[Test]
    public function phones_are_stored_the_way_the_portals_store_them(): void
    {
        $submission = $this->submission();

        $this->convert($submission)->assertOk();

        $borrower = $this->convertedBorrower($submission);
        $this->assertSame('(512) 234-5678', $borrower->phone_number);
        $this->assertSame('+1', $borrower->country_code);
        $this->assertSame('(512) 987-6543', $borrower->business_phone);
    }

    #[Test]
    public function revenue_and_the_fico_score_reach_the_profile(): void
    {
        $submission = $this->submission();

        $this->convert($submission)->assertOk();

        $borrower = $this->convertedBorrower($submission);
        $this->assertEquals(50000, (float) $borrower->monthly_revenue);
        $this->assertEquals(600000, (float) $borrower->annual_revenue);
        $this->assertSame(712, (int) $borrower->fico_score);
    }

    #[Test]
    public function the_other_matching_fields_reach_the_profile(): void
    {
        $submission = $this->submission();

        $this->convert($submission)->assertOk();

        $borrower = $this->convertedBorrower($submission);
        $this->assertSame('Jo', $borrower->middle_name);
        $this->assertSame('1986-09-01', substr((string) $borrower->getRawOriginal('date_of_birth'), 0, 10));
        $this->assertSame('123456789', $borrower->ssn);
        $this->assertSame('270 Sharulia Road', $borrower->home_address);
        $this->assertSame('78701', $borrower->postal_code);
        $this->assertSame('1 Commerce St', $borrower->business_address);
        $this->assertSame('Dallas', $borrower->business_city);
        $this->assertSame('Texas', $borrower->business_state);
        $this->assertSame('75201', $borrower->business_postal_code);
        $this->assertSame('United States', $borrower->business_country);
        $this->assertSame('+1', $borrower->business_country_code);
        $this->assertSame('C-Corporation', $borrower->business_entity_type);
        $this->assertSame('Real Estate (Non-Lending)', $borrower->business_industry_type);
        $this->assertSame(140, (int) $borrower->number_of_employees);
        $this->assertEquals(25000, (float) $borrower->current_business_debt);
    }

    #[Test]
    public function values_with_no_true_counterpart_are_left_alone(): void
    {
        $submission = $this->submission();

        $this->convert($submission)->assertOk();

        $borrower = $this->convertedBorrower($submission);
        $this->assertNotSame('2-3 years', $borrower->years_in_business);
        $this->assertEmpty($borrower->paydex_score);
    }

    #[Test]
    public function a_credit_score_that_is_not_a_fico_number_is_not_used(): void
    {
        $submission = $this->submission(['self_reported_personal_credit_score' => '700-749']);

        $this->convert($submission)->assertOk();

        $this->assertNull($this->convertedBorrower($submission)->fico_score);
    }

    #[Test]
    public function no_business_debt_means_no_debt_on_the_profile(): void
    {
        $submission = $this->submission(['has_business_debt' => false, 'debt_balance' => '25000.00']);

        $this->convert($submission)->assertOk();

        $this->assertNull($this->convertedBorrower($submission)->current_business_debt);
    }

    #[Test]
    public function the_funding_application_and_co_borrowers_get_the_same_phone_format(): void
    {
        $submission = $this->submission();
        EmbedFormCoBorrower::create([
            'submission_id' => $submission->id,
            'first_name' => 'Sam',
            'last_name' => 'Partner',
            'mobile_phone' => '+15125550199',
            'home_phone' => '5125550188',
        ]);

        $applicationId = $this->convert($submission)->assertOk()->json('data.funding_application_id');

        $application = FundingApplication::with('coborrowers')->findOrFail($applicationId);
        $this->assertSame('(512) 234-5678', $application->phone_number);
        $this->assertSame('(512) 987-6543', $application->business_phone_no);
        $this->assertSame('(512) 555-0199', $application->coborrowers->first()->mobile_phone);
        $this->assertSame('(512) 555-0188', $application->coborrowers->first()->home_phone);
    }

    #[Test]
    public function the_ein_and_business_start_date_reach_the_profile(): void
    {
        // The form's "Business Start Date" is stored in business_checking_start_date.
        $submission = $this->submission(['business_ein' => '123456789', 'business_checking_start_date' => '2019-04-01']);

        $this->convert($submission)->assertOk();

        $borrower = $this->convertedBorrower($submission);
        $this->assertSame('123456789', $borrower->business_ein);
        $this->assertSame('2019-04-01', substr((string) $borrower->getRawOriginal('business_start_date'), 0, 10));
        // Years in business is worked out from that date, never copied.
        $this->assertEquals(
            round(Carbon::parse('2019-04-01')->diffInDays(now()) / 365.25, 1),
            $borrower->years_in_business
        );
    }

    #[Test]
    public function the_currency_and_additional_funding_types_reach_the_funding_application(): void
    {
        $submission = $this->submission([
            'currency' => 'GBP',
            'preferred_funding_type' => 'Term Loan',
            'additional_funding_types' => ['SBA Loan', 'Line of Credit'],
        ]);

        $applicationId = $this->convert($submission)->assertOk()->json('data.funding_application_id');

        $application = FundingApplication::findOrFail($applicationId);
        $this->assertSame('GBP', $application->currency);
        $this->assertSame('£', $application->currency_symbol);
        $this->assertSame(['SBA Loan', 'Line of Credit'], $application->additional_funding_types);
        $this->assertSame('GBP', $this->convertedBorrower($submission)->currency);
    }

    #[Test]
    public function the_conversion_email_shows_the_amount_in_its_currency(): void
    {
        $submission = $this->submission(['requested_amount' => 150000, 'currency' => 'EUR']);

        $this->convert($submission)->assertOk();

        Mail::assertQueued(\App\Mail\EmbedForm\EmbedFormConverted::class, fn ($mail) => str_contains($mail->render(), '€150,000.00'));
    }

    #[Test]
    public function the_funding_application_keeps_the_embedded_signature_and_a_signed_copy(): void
    {
        // Signed on the public form: the converted application carries that
        // signature, says how it was given, and keeps the application as signed.
        $submission = $this->submission(['signature_data' => [
            'signature_name' => 'Odessa Mccall',
            'signing_method' => \App\Services\FCH\Common\Application\SignatureRecorder::METHOD_EMBEDDED_FORM,
            'signer_account' => null,
            'signer_email' => 'odessa@example.test',
            'ip_address' => '198.51.100.23',
            'signed_at' => '2026-10-16T14:41:07+00:00',
            'timezone' => 'America/Chicago',
        ]]);

        $applicationId = $this->convert($submission)->assertOk()->json('data.funding_application_id');

        $signature = FundingApplication::findOrFail($applicationId)->signature_data;
        $this->assertSame(\App\Services\FCH\Common\Application\SignatureRecorder::METHOD_EMBEDDED_FORM, $signature['signing_method']);
        $this->assertSame('198.51.100.23', $signature['ip_address']);
        $this->assertSame(1, $signature['application_version']);
        Storage::disk('public')->assertExists($signature['signed_application_path']);
        Storage::disk('public')->assertExists($signature['certificate_path']);
    }

    #[Test]
    public function the_funding_application_holds_the_ssn_as_its_nine_digits(): void
    {
        // Submissions from before the form sent bare digits hold 123-45-6789.
        $submission = $this->submission(['ssn_encrypted' => Crypt::encryptString('123-45-6789')]);

        $applicationId = $this->convert($submission)->assertOk()->json('data.funding_application_id');

        $this->assertSame('123456789', FundingApplication::findOrFail($applicationId)->ssn);
        $this->assertSame('123456789', $this->convertedBorrower($submission)->ssn);
    }

    // -- An existing borrower ---------------------------------------------

    #[Test]
    public function an_existing_borrower_with_only_the_last_four_of_the_ssn_gets_the_full_ssn(): void
    {
        // Older accounts hold just the last four: that is not a full SSN, so it
        // counts as missing, not as something to keep.
        $existing = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
            'last_4_ssn' => '6789',
        ]);

        $this->convert($this->submission(), ['confirm_existing_borrower' => true])->assertOk();

        $this->assertSame('123456789', $existing->fresh()->ssn);
    }

    #[Test]
    public function an_existing_borrowers_full_ssn_is_kept(): void
    {
        $existing = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
            'ssn' => '987654321',
        ]);

        $this->convert($this->submission(), ['confirm_existing_borrower' => true])->assertOk();

        $this->assertSame('987654321', $existing->fresh()->ssn);
    }

    #[Test]
    public function an_existing_borrowers_profile_only_has_its_blanks_filled(): void
    {
        // What the borrower or the partner already entered wins.
        $existing = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
            'phone_number' => '(512) 111-2222',
            'country_code' => '+1',
            'monthly_revenue' => 10000,
            'fico_score' => 650,
        ]);
        $submission = $this->submission();

        $this->convert($submission, ['confirm_existing_borrower' => true])->assertOk();

        $borrower = $existing->fresh();
        $this->assertSame('(512) 111-2222', $borrower->phone_number);
        $this->assertEquals(10000, (float) $borrower->monthly_revenue);
        $this->assertSame(650, (int) $borrower->fico_score);
        // Blanks are filled.
        $this->assertEquals(600000, (float) $borrower->annual_revenue);
        $this->assertSame('C-Corporation', $borrower->business_entity_type);
        $this->assertSame('(512) 987-6543', $borrower->business_phone);
    }
}
