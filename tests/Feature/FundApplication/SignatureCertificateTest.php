<?php

namespace Tests\Feature\FundApplication;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use App\Services\FCH\Common\Application\SignatureCertificateService;
use App\Services\FCH\Common\Application\SignatureRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The electronic signature certificate says what actually happened at signing:
 * the signer's own IP, the exact moment in the signer's timezone, who signed and
 * how, the policy versions accepted, and which signed version of the
 * application it certifies.
 */
class SignatureCertificateTest extends TestCase
{
    use RefreshDatabase;

    private function application(): FundingApplication
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);
        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Ava',
            'last_name' => 'Ortiz',
            'email' => 'ava@example.test',
        ]);

        $id = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-0005151',
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'first_name' => 'Ava',
            'last_name' => 'Ortiz',
            'business_name' => 'Pearl Black',
            'status' => FundingApplication::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return FundingApplication::findOrFail($id);
    }

    private function signed(array $overrides = []): array
    {
        return array_merge([
            'signature_name' => 'Ava Ortiz',
            'signature_type' => 'typed',
            'signing_method' => SignatureRecorder::METHOD_BORROWER_PORTAL,
            'signer_account' => ['user_id' => 41, 'name' => 'Ava Ortiz', 'email' => 'ava@example.test', 'user_type' => 'Borrower'],
            'signer_email' => 'ava@example.test',
            'ip_address' => '198.51.100.23',
            'browser_name' => 'Chrome',
            'operating_system' => 'Windows',
            'device_type' => 'Desktop',
            'timezone' => 'America/Chicago',
            'signed_at' => '2026-10-16T14:41:07+00:00',
            'esign_consent' => true,
            'terms_consent' => true,
            'credit_consent' => true,
            'legal_binding_consent' => true,
            'policy_versions' => SignatureRecorder::policyVersions(),
            'application_version' => 2,
            'certificate_id' => 'b3f1c2d4-0000-4000-8000-000000000002',
            'sha256' => str_repeat('ab', 32),
        ], $overrides);
    }

    private function certificate(array $signature): string
    {
        $application = $this->application();
        $branding = FundingApplicationDocumentService::getBrandingData($application);

        return html_entity_decode(strip_tags(view('pdf.signature-certificate', array_merge(
            $branding,
            SignatureCertificateService::getTemplateData($application, $branding, $signature)
        ))->render()), ENT_QUOTES);
    }

    // -- 4. IP address ----------------------------------------------------------

    #[Test]
    public function the_signers_recorded_ip_is_shown(): void
    {
        $this->assertStringContainsString('198.51.100.23', $this->certificate($this->signed()));
    }

    #[Test]
    public function an_ip_that_was_never_recorded_is_not_made_up(): void
    {
        $text = $this->certificate($this->signed(['ip_address' => null]));

        $this->assertStringNotContainsString('127.0.0.1', $text);
        $this->assertStringContainsString('Not recorded', $text);
    }

    // -- 5. Exact date, time and timezone -----------------------------------------

    #[Test]
    public function the_signing_moment_is_shown_in_the_signers_timezone_and_in_utc(): void
    {
        $text = $this->certificate($this->signed());

        $this->assertStringContainsString('Oct 16, 2026 9:41:07 AM CDT (UTC-05:00)', $text);
        $this->assertStringContainsString('America/Chicago', $text);
        $this->assertStringContainsString('Oct 16, 2026 2:41:07 PM UTC', $text);
    }

    #[Test]
    public function without_the_signers_timezone_it_is_shown_in_utc(): void
    {
        $text = $this->certificate($this->signed(['timezone' => null]));

        $this->assertStringContainsString('Oct 16, 2026 2:41:07 PM UTC', $text);
    }

    // -- 6. Who signed, and how ---------------------------------------------------

    #[Test]
    public function the_borrower_and_the_name_signed_are_shown_apart_and_a_mismatch_is_flagged(): void
    {
        $text = $this->certificate($this->signed(['signature_name' => 'Awlad Hossain']));

        $this->assertStringContainsString('Ava Ortiz', $text);
        $this->assertStringContainsString('Awlad Hossain', $text);
        $this->assertStringContainsString('The name signed differs from the borrower on the application.', $text);
    }

    #[Test]
    public function a_borrower_portal_signature_names_the_account_that_signed_in(): void
    {
        $text = $this->certificate($this->signed());

        $this->assertStringContainsString('Borrower portal', $text);
        $this->assertStringContainsString('Ava Ortiz (ava@example.test)', $text);
        $this->assertStringNotContainsString('differs from the borrower', $text);
    }

    #[Test]
    public function an_embedded_form_signature_does_not_claim_an_account_login(): void
    {
        $text = $this->certificate($this->signed([
            'signing_method' => SignatureRecorder::METHOD_EMBEDDED_FORM,
            'signer_account' => null,
        ]));

        $this->assertStringContainsString('Public embedded form', $text);
        $this->assertStringContainsString('No account sign-in', $text);
        $this->assertStringNotContainsString('User account login', $text);
    }

    #[Test]
    public function a_partner_portal_signature_says_a_partner_user_submitted_it(): void
    {
        $text = $this->certificate($this->signed([
            'signing_method' => SignatureRecorder::METHOD_PARTNER_PORTAL,
            'signer_account' => ['user_id' => 7, 'name' => 'Pat Partner', 'email' => 'pat@partner.test', 'user_type' => 'Partner'],
        ]));

        $this->assertStringContainsString('Partner portal', $text);
        $this->assertStringContainsString('Pat Partner (pat@partner.test)', $text);
    }

    #[Test]
    public function a_signature_from_before_the_method_was_recorded_says_so(): void
    {
        $text = $this->certificate($this->signed(['signing_method' => null, 'signer_account' => null]));

        $this->assertStringNotContainsString('User account login', $text);
        $this->assertStringContainsString('Not recorded', $text);
    }

    // -- 7. Policy versions -------------------------------------------------------

    #[Test]
    public function the_versions_accepted_are_shown_not_just_agreed(): void
    {
        $text = $this->certificate($this->signed());

        foreach (config('funding_application.signing.policies') as $policy) {
            $this->assertStringContainsString($policy['label'], $text);
            $this->assertStringContainsString('v' . $policy['version'], $text);
        }
        $this->assertStringNotContainsString('Agreed', $text);
    }

    #[Test]
    public function versions_never_recorded_are_not_invented(): void
    {
        $text = $this->certificate($this->signed(['policy_versions' => null]));

        $this->assertStringContainsString('Not recorded', $text);
        $this->assertStringNotContainsString('v1.0', $text);
    }

    // -- 8. The signed version ----------------------------------------------------

    #[Test]
    public function the_certificate_names_the_signed_version_and_its_fingerprint(): void
    {
        $text = $this->certificate($this->signed());

        $this->assertStringContainsString('Version 2', $text);
        $this->assertStringContainsString(str_repeat('ab', 32), $text);
        $this->assertStringContainsString('b3f1c2d4-0000-4000-8000-000000000002', $text);
    }
}
