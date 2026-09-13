<?php

namespace Tests\Unit\EmbeddedForm;

use App\Rules\UsPhoneNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The field rules the public form is validated against.
 *
 * Exercised through a standalone Validator rather than through
 * SubmitEmbedFormRequest, because that class resolves the form's partner from
 * the database to build its sales-rep list. The rules asserted here are built
 * from the same config the request reads, so a limit changed in config is
 * caught by these tests.
 */
class SubmitEmbedFormRequestTest extends TestCase
{
    private function fails(array $rules, array $data): bool
    {
        return Validator::make($data, $rules)->fails();
    }

    // ── Postal codes ──────────────────────────────────────────────────

    private function postalRules(): array
    {
        $length = (int) config('funding_application.embed_form.postal_code_length');

        return ['zip_code' => ['required', 'string', "digits:{$length}"]];
    }

    public static function badPostalCodes(): array
    {
        return [
            'four digits' => ['1000'],
            'six digits' => ['100011'],
            'contains a letter' => ['1000A'],
            'ZIP+4' => ['75201-1234'],
            'padded with a space' => [' 12345'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('badPostalCodes')]
    public function a_postal_code_must_be_exactly_five_digits(string $value): void
    {
        $this->assertTrue($this->fails($this->postalRules(), ['zip_code' => $value]));
    }

    #[Test]
    public function a_five_digit_postal_code_passes(): void
    {
        $this->assertFalse($this->fails($this->postalRules(), ['zip_code' => '75201']));
        // A leading zero must survive - digits: treats it as a string, so 01234
        // is five digits and not the number 1234.
        $this->assertFalse($this->fails($this->postalRules(), ['zip_code' => '01234']));
    }

    // ── Age floor ─────────────────────────────────────────────────────

    private function dobRules(): array
    {
        $age = (int) config('funding_application.embed_form.minimum_age');

        return ['date_of_birth' => [
            'required',
            'date',
            'before:today',
            'before_or_equal:' . now()->subYears($age)->toDateString(),
        ]];
    }

    #[Test]
    public function an_applicant_under_the_minimum_age_is_rejected(): void
    {
        $age = (int) config('funding_application.embed_form.minimum_age');

        $this->assertTrue($this->fails($this->dobRules(), [
            'date_of_birth' => now()->subYears($age - 1)->toDateString(),
        ]));
    }

    #[Test]
    public function an_applicant_exactly_at_the_minimum_age_is_accepted(): void
    {
        $age = (int) config('funding_application.embed_form.minimum_age');

        $this->assertFalse($this->fails($this->dobRules(), [
            'date_of_birth' => now()->subYears($age)->toDateString(),
        ]));
    }

    #[Test]
    public function a_date_of_birth_in_the_future_is_rejected(): void
    {
        $this->assertTrue($this->fails($this->dobRules(), [
            'date_of_birth' => now()->addYear()->toDateString(),
        ]));
    }

    // ── Amount bounds ─────────────────────────────────────────────────

    private function amountRules(string $field): array
    {
        $bounds = config("funding_application.embed_form.amounts.{$field}");

        return [$field => ['required', 'numeric', 'min:' . $bounds['min'], 'max:' . $bounds['max']]];
    }

    #[Test]
    public function a_requested_amount_below_the_floor_is_rejected(): void
    {
        $min = config('funding_application.embed_form.amounts.requested_amount.min');

        $this->assertTrue($this->fails($this->amountRules('requested_amount'), [
            'requested_amount' => $min - 1,
        ]));
    }

    #[Test]
    public function a_requested_amount_above_the_ceiling_is_rejected(): void
    {
        $max = config('funding_application.embed_form.amounts.requested_amount.max');

        $this->assertTrue($this->fails($this->amountRules('requested_amount'), [
            'requested_amount' => $max + 1,
        ]));
    }

    #[Test]
    public function the_bounds_themselves_are_accepted(): void
    {
        $bounds = config('funding_application.embed_form.amounts.requested_amount');

        $this->assertFalse($this->fails($this->amountRules('requested_amount'), ['requested_amount' => $bounds['min']]));
        $this->assertFalse($this->fails($this->amountRules('requested_amount'), ['requested_amount' => $bounds['max']]));
    }

    // ── Credit score ──────────────────────────────────────────────────

    private function creditScoreRules(): array
    {
        return ['credit_score' => [
            'nullable',
            'integer',
            'min:' . config('funding_application.credit_score.min'),
            'max:' . config('funding_application.credit_score.max'),
        ]];
    }

    #[Test]
    public function the_credit_score_is_optional(): void
    {
        // An applicant who does not know their score must still be able to apply.
        $this->assertFalse($this->fails($this->creditScoreRules(), []));
        $this->assertFalse($this->fails($this->creditScoreRules(), ['credit_score' => null]));
        $this->assertFalse($this->fails($this->creditScoreRules(), ['credit_score' => '']));
    }

    #[Test]
    public function a_score_that_is_given_must_be_on_the_scale(): void
    {
        $this->assertTrue($this->fails($this->creditScoreRules(), ['credit_score' => 299]));
        $this->assertTrue($this->fails($this->creditScoreRules(), ['credit_score' => 851]));
        $this->assertFalse($this->fails($this->creditScoreRules(), ['credit_score' => 300]));
        $this->assertFalse($this->fails($this->creditScoreRules(), ['credit_score' => 720]));
        $this->assertFalse($this->fails($this->creditScoreRules(), ['credit_score' => 850]));
    }

    // ── Controlled lists ──────────────────────────────────────────────

    #[Test]
    public function the_referral_source_must_come_from_the_list(): void
    {
        $rules = ['referral_source' => ['nullable', 'string',
            \Illuminate\Validation\Rule::in(config('funding_application.embed_form.referral_sources'))]];

        $this->assertTrue($this->fails($rules, ['referral_source' => 'anything I like']));
        $this->assertFalse($this->fails($rules, ['referral_source' => 'Broker or Partner Referral']));
    }

    #[Test]
    public function the_business_credit_score_must_come_from_the_list(): void
    {
        $rules = ['self_reported_business_credit_score' => ['nullable', 'string',
            \Illuminate\Validation\Rule::in(config('funding_application.credit_score.business_score_options'))]];

        $this->assertTrue($this->fails($rules, ['self_reported_business_credit_score' => 'excellent']));
        $this->assertFalse($this->fails($rules, ['self_reported_business_credit_score' => '75-79']));
    }

    // ── SSN ───────────────────────────────────────────────────────────

    #[Test]
    public function an_ssn_must_be_nine_digits_once_the_mask_is_stripped(): void
    {
        $rules = ['ssn' => ['required', 'string', 'digits:9']];

        // What prepareForValidation() hands the validator.
        $this->assertFalse($this->fails($rules, ['ssn' => '412889031']));
        $this->assertTrue($this->fails($rules, ['ssn' => '41288']));
        // The masked form never reaches the rule, and would fail if it did.
        $this->assertTrue($this->fails($rules, ['ssn' => '412-88-9031']));
    }

    // ── Phone, through the rule object ────────────────────────────────

    #[Test]
    public function the_phone_rule_is_wired_into_a_validator_correctly(): void
    {
        $rules = ['phone' => ['required', 'string', new UsPhoneNumber()]];

        $this->assertTrue($this->fails($rules, ['phone' => '1234567890']));
        $this->assertFalse($this->fails($rules, ['phone' => '(813) 391-3333']));
    }
}
