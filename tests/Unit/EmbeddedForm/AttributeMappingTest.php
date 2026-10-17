<?php

namespace Tests\Unit\EmbeddedForm;

use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * How a validated payload becomes the columns of a submission.
 *
 * These mappers are where the public form meets the funding application's own
 * column names, so a rename on either side shows up here first. Each is called
 * directly - none of them touch the database.
 */
#[CoversClass(EmbedFormService::class)]
class AttributeMappingTest extends TestCase
{
    private EmbedFormService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmbedFormService();
    }

    private function invokeMapper(string $method, mixed ...$args): array
    {
        $reflection = new ReflectionMethod(EmbedFormService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->service, ...$args);
    }

    // ── Financial ─────────────────────────────────────────────────────

    #[Test]
    public function a_debt_balance_is_kept_only_alongside_a_yes(): void
    {
        $withDebt = $this->invokeMapper('financialAttributes', [
            'has_business_debt' => '1',
            'debt_balance' => '94000',
        ]);

        $this->assertTrue($withDebt['has_business_debt']);
        $this->assertSame('94000', $withDebt['debt_balance']);
    }

    #[Test]
    public function a_debt_balance_sent_with_a_no_is_discarded(): void
    {
        // Leaving a stale figure behind would misstate the applicant's position.
        $withoutDebt = $this->invokeMapper('financialAttributes', [
            'has_business_debt' => '0',
            'debt_balance' => '94000',
        ]);

        $this->assertFalse($withoutDebt['has_business_debt']);
        $this->assertNull($withoutDebt['debt_balance']);
    }

    #[Test]
    public function the_credit_score_is_mirrored_into_the_column_the_portal_reads(): void
    {
        $attributes = $this->invokeMapper('financialAttributes', ['credit_score' => 720]);

        $this->assertSame(720, $attributes['credit_score']);
        $this->assertSame('720', $attributes['self_reported_personal_credit_score']);
    }

    #[Test]
    public function no_credit_score_leaves_both_columns_null(): void
    {
        $attributes = $this->invokeMapper('financialAttributes', []);

        $this->assertNull($attributes['credit_score']);
        $this->assertNull($attributes['self_reported_personal_credit_score']);
    }

    // ── Ownership ─────────────────────────────────────────────────────

    #[Test]
    public function the_owner_phone_is_stored_the_way_the_portals_store_it(): void
    {
        $attributes = $this->invokeMapper('ownershipAttributes', [
            'first_name' => 'Marcus',
            'last_name' => 'Webb',
            'email' => 'marcus@example.test',
            'phone' => '(813) 391-3333',
        ]);

        $this->assertSame('(813) 391-3333', $attributes['phone']);
    }

    #[Test]
    public function an_unnormalisable_phone_is_kept_verbatim_rather_than_dropped(): void
    {
        // Validation should already have refused it; if one ever gets through,
        // keeping what the applicant typed beats storing null.
        $attributes = $this->invokeMapper('ownershipAttributes', [
            'first_name' => 'Marcus',
            'last_name' => 'Webb',
            'email' => 'marcus@example.test',
            'phone' => '1234567890',
        ]);

        $this->assertSame('1234567890', $attributes['phone']);
    }

    #[Test]
    public function the_ssn_is_encrypted_and_never_stored_in_the_clear(): void
    {
        $attributes = $this->invokeMapper('ownershipAttributes', [
            'first_name' => 'Marcus',
            'last_name' => 'Webb',
            'email' => 'marcus@example.test',
            'ssn' => '412889031',
        ]);

        $this->assertNotSame('412889031', $attributes['ssn_encrypted']);
        $this->assertStringNotContainsString('412889031', $attributes['ssn_encrypted']);
        $this->assertSame('412889031', decrypt($attributes['ssn_encrypted'], false));
    }

    #[Test]
    public function has_co_borrower_is_derived_from_the_rows_actually_sent(): void
    {
        $without = $this->invokeMapper('ownershipAttributes', [
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.test',
        ]);
        $with = $this->invokeMapper('ownershipAttributes', [
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.test',
            'co_borrowers' => [['first_name' => 'Dana']],
        ]);

        $this->assertFalse($without['has_co_borrower']);
        $this->assertTrue($with['has_co_borrower']);
    }

    // ── Federal compliance ────────────────────────────────────────────

    #[Test]
    public function every_compliance_flag_defaults_to_false_when_absent(): void
    {
        $attributes = $this->invokeMapper('federalComplianceAttributes', []);

        foreach ([
            'filed_for_bankruptcy', 'any_pending_lawsuits', 'has_federal_loan',
            'has_federal_debt', 'has_criminal_offense', 'has_parole_or_probation',
            'suspended_or_debarred', 'any_outstanding_judgments_business',
            'any_outstanding_judgments_owner',
        ] as $flag) {
            $this->assertFalse($attributes[$flag], "{$flag} should default to false");
        }

        $this->assertNull($attributes['federal_compliance_disclosures_explanation']);
    }

    #[Test]
    public function a_yes_is_recorded_as_a_boolean_whatever_shape_it_arrives_in(): void
    {
        foreach (['1', 1, true, 'true'] as $yes) {
            $attributes = $this->invokeMapper('federalComplianceAttributes', ['filed_for_bankruptcy' => $yes]);
            $this->assertTrue($attributes['filed_for_bankruptcy']);
        }

        foreach (['0', 0, false, 'false'] as $no) {
            $attributes = $this->invokeMapper('federalComplianceAttributes', ['filed_for_bankruptcy' => $no]);
            $this->assertFalse($attributes['filed_for_bankruptcy']);
        }
    }

    // ── Attestation ───────────────────────────────────────────────────

    private function attestation(array $data): array
    {
        return $this->invokeMapper('attestationAttributes', $data, Request::create('/', 'POST'));
    }

    #[Test]
    public function the_overall_attestation_holds_only_when_all_four_consents_do(): void
    {
        $all = $this->attestation([
            'signature_name' => 'Marcus A Webb',
            'esign_consent' => '1', 'terms_consent' => '1',
            'credit_consent' => '1', 'legal_binding_consent' => '1',
        ]);
        $this->assertTrue($all['attestation_authorization']);

        $missingOne = $this->attestation([
            'signature_name' => 'Marcus A Webb',
            'esign_consent' => '1', 'terms_consent' => '1',
            'credit_consent' => '1', 'legal_binding_consent' => '0',
        ]);
        $this->assertFalse($missingOne['attestation_authorization']);
    }

    #[Test]
    public function the_signature_record_captures_what_makes_it_defensible(): void
    {
        $attributes = $this->attestation([
            'signature_name' => 'Marcus A Webb',
            'timezone' => 'America/Chicago',
            'esign_consent' => '1', 'terms_consent' => '1',
            'credit_consent' => '1', 'legal_binding_consent' => '1',
        ]);

        $signature = $attributes['signature_data'];

        $this->assertSame('Marcus A Webb', $signature['signature_name']);
        $this->assertSame('America/Chicago', $signature['timezone']);
        $this->assertNotEmpty($signature['signed_at']);
        $this->assertArrayHasKey('ip_address', $signature);
        $this->assertArrayHasKey('user_agent', $signature);
    }

    #[Test]
    public function malformed_client_consent_json_is_discarded_rather_than_trusted(): void
    {
        // consents_data is applicant-supplied, so it is decoded defensively.
        $attributes = $this->attestation([
            'signature_name' => 'Marcus A Webb',
            'consents_data' => '{not valid json',
        ]);

        $this->assertNull($attributes['signature_data']['consents_data']);
        // The server's own record of the signing survives regardless.
        $this->assertNotEmpty($attributes['signature_data']['signed_at']);
    }

    #[Test]
    public function well_formed_client_consent_timestamps_are_kept(): void
    {
        $attributes = $this->attestation([
            'signature_name' => 'Marcus A Webb',
            'consents_data' => json_encode(['esign_consent' => '2026-09-10T12:00:00Z']),
        ]);

        $this->assertSame(
            ['esign_consent' => '2026-09-10T12:00:00Z'],
            $attributes['signature_data']['consents_data']
        );
    }

    #[Test]
    public function an_embedded_form_signature_says_it_was_signed_on_the_public_form(): void
    {
        // No account was signed in: the applicant is known only by the email given.
        $attributes = $this->attestation([
            'email' => 'marcus@example.test',
            'signature_name' => 'Marcus A Webb',
            'esign_consent' => '1', 'terms_consent' => '1',
            'credit_consent' => '1', 'legal_binding_consent' => '1',
        ]);
        $signature = $attributes['signature_data'];

        $this->assertSame(\App\Services\FCH\Common\Application\SignatureRecorder::METHOD_EMBEDDED_FORM, $signature['signing_method']);
        $this->assertNull($signature['signer_account']);
        $this->assertSame('marcus@example.test', $signature['signer_email']);
        $this->assertTrue($signature['credit_consent']);
        $this->assertSame(array_keys(config('funding_application.signing.policies')), array_keys($signature['policy_versions']));
    }

    #[Test]
    public function the_forms_own_build_number_is_not_mistaken_for_the_application_version(): void
    {
        // application_version is the signed version number, set when the signed
        // copy is kept; what the form posts is its build.
        $signature = $this->attestation([
            'signature_name' => 'Marcus A Webb',
            'application_version' => '2026.09.03',
        ])['signature_data'];

        $this->assertSame('2026.09.03', $signature['form_version']);
        $this->assertArrayNotHasKey('application_version', $signature);
    }

    // ── Business ──────────────────────────────────────────────────────

    #[Test]
    public function the_business_phone_is_stored_the_way_the_portals_store_it(): void
    {
        $attributes = $this->invokeMapper('businessInfoAttributes', ['business_phone_no' => '+18133913333']);

        $this->assertSame('(813) 391-3333', $attributes['business_phone_no']);
    }

    #[Test]
    public function the_ein_is_stored_as_its_nine_digits(): void
    {
        // As the borrower profile stores it; XX-XXXXXXX is only how it is written.
        $attributes = $this->invokeMapper('businessInfoAttributes', ['business_ein' => '12-3456789']);

        $this->assertSame('123456789', $attributes['business_ein']);
    }

    #[Test]
    public function years_in_business_is_worked_out_from_the_business_start_date(): void
    {
        // The form no longer asks for it: it follows from when the business started.
        \Illuminate\Support\Carbon::setTestNow('2026-10-16');

        $cases = [
            '2026-06-01' => 'Under 6 months',
            '2026-04-16' => '6-12 months',
            '2025-10-17' => '6-12 months',
            '2025-10-16' => '1-2 years',
            '2024-10-16' => '2-3 years',
            '2023-10-16' => '3+ years',
        ];

        foreach ($cases as $started => $expected) {
            $attributes = $this->invokeMapper('financialAttributes', ['business_checking_start_date' => $started]);
            $this->assertSame($expected, $attributes['years_in_business'], "started {$started}");
        }

        $this->assertNull($this->invokeMapper('financialAttributes', [])['years_in_business']);
        \Illuminate\Support\Carbon::setTestNow();
    }

    #[Test]
    public function the_years_in_business_labels_are_the_configured_options(): void
    {
        // So the value reads the same as one picked from the portal's list.
        foreach (['2026-06-01', '2026-01-01', '2025-01-01', '2024-01-01', '2020-01-01'] as $started) {
            $label = $this->invokeMapper('financialAttributes', ['business_checking_start_date' => $started])['years_in_business'];
            $this->assertContains($label, config('funding_application.options.years_in_business'));
        }
    }

    // ── Funding request ───────────────────────────────────────────────

    #[Test]
    public function the_currency_is_kept_with_the_requested_amount(): void
    {
        $this->assertSame('EUR', $this->invokeMapper('fundingRequestAttributes', ['currency' => 'EUR'])['currency']);
        // A form loaded before the field existed posts none: the amount was in dollars.
        $this->assertSame('USD', $this->invokeMapper('fundingRequestAttributes', [])['currency']);
    }

    #[Test]
    public function the_additional_funding_types_are_kept_as_a_list(): void
    {
        $attributes = $this->invokeMapper('fundingRequestAttributes', [
            'preferred_funding_type' => 'Term Loan',
            'additional_funding_types' => ['SBA Loan', 'Line of Credit'],
        ]);

        $this->assertSame(['SBA Loan', 'Line of Credit'], $attributes['additional_funding_types']);
        $this->assertNull($this->invokeMapper('fundingRequestAttributes', [])['additional_funding_types']);
    }

    #[Test]
    public function absent_business_fields_map_to_null_not_to_missing_keys(): void
    {
        $attributes = $this->invokeMapper('businessInfoAttributes', []);

        // Every column the mapper owns must be present, so a partial payload
        // cannot silently leave a stale value behind on an update.
        foreach (['full_name', 'business_name', 'business_email', 'industry_type', 'entity_type'] as $field) {
            $this->assertArrayHasKey($field, $attributes);
            $this->assertNull($attributes[$field]);
        }
    }
}
