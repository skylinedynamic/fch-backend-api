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
        // The request's own rule for the owner's ZIP, so this test describes
        // what the form actually validates against.
        return ['zip_code' => $this->stepRules('ownershipInfoRules')['zip_code']];
    }

    public static function badPostalCodes(): array
    {
        return [
            'four digits' => ['1000'],
            'six digits' => ['100011'],
            'contains a letter' => ['1000A'],
            'ZIP+4' => ['75201-1234'],
            'padded with a space' => [' 12345'],
        ];
    }

    #[Test]
    #[DataProvider('badPostalCodes')]
    public function a_postal_code_must_be_exactly_five_digits(string $value): void
    {
        $this->assertTrue($this->fails($this->postalRules(), ['zip_code' => $value]));
    }

    #[Test]
    public function the_owners_and_the_businesss_postal_codes_are_required(): void
    {
        $this->assertTrue($this->fails($this->postalRules(), ['zip_code' => '']));
        $this->assertTrue($this->fails($this->postalRules(), []));

        $business = ['business_postal_code' => $this->stepRules('businessInfoRules')['business_postal_code']];
        $this->assertTrue($this->fails($business, []));
        $this->assertTrue($this->fails($business, ['business_postal_code' => '1000']));
        $this->assertFalse($this->fails($business, ['business_postal_code' => '75201']));
    }

    #[Test]
    public function a_five_digit_postal_code_passes(): void
    {
        $this->assertFalse($this->fails($this->postalRules(), ['zip_code' => '75201']));
        // A leading zero must survive - the rule matches the string, so 01234
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

    // -- Phones are stored the way the portals store them ---------------------

    /**
     * The public form sends +15122345678; the borrower and partner portals store
     * (512) 234-5678. Normalised before validation, so the submission row - and
     * everything converted from it - holds the portals' form.
     */
    private function prepared(array $input): \Illuminate\Http\Request
    {
        $request = new class extends \App\Http\Requests\Api\V1\Global\Website\EmbedForm\SubmitEmbedFormRequest {
            public function prepare(): void
            {
                $this->prepareForValidation();
            }
        };
        $request->merge($input);
        $request->prepare();

        return $request;
    }

    #[Test]
    public function phones_are_stored_the_way_the_portals_store_them(): void
    {
        $request = $this->prepared([
            'phone' => '+15122345678',
            'business_phone_no' => '512.987.6543',
            'co_borrowers' => [['first_name' => 'Sam', 'mobile_phone' => '+1 512 555 0199', 'home_phone' => '5125550188']],
        ]);

        $this->assertSame('(512) 234-5678', $request->input('phone'));
        $this->assertSame('(512) 987-6543', $request->input('business_phone_no'));
        $this->assertSame('(512) 555-0199', $request->input('co_borrowers.0.mobile_phone'));
        $this->assertSame('(512) 555-0188', $request->input('co_borrowers.0.home_phone'));
    }

    #[Test]
    public function a_number_that_is_not_a_us_phone_is_left_for_the_rule_to_refuse(): void
    {
        $request = $this->prepared(['phone' => '12345']);

        $this->assertSame('12345', $request->input('phone'));
    }

    // -- Business details: EIN, no years in business --------------------------

    /** The request's own rules for one step; none of these touch the database. */
    private function stepRules(string $method): array
    {
        $request = new class extends \App\Http\Requests\Api\V1\Global\Website\EmbedForm\SubmitEmbedFormRequest {
            public function step(string $method): array
            {
                return $this->{$method}();
            }
        };

        return $request->step($method);
    }

    #[Test]
    public function every_business_field_is_required_but_the_ein_and_the_employees(): void
    {
        $optional = ['business_ein', 'number_of_employees'];

        foreach ($this->stepRules('businessInfoRules') as $field => $rules) {
            if (in_array($field, $optional, true)) {
                $this->assertContains('nullable', $rules, "{$field} should be optional");
                $this->assertNotContains('required', $rules, "{$field} should be optional");
            } else {
                $this->assertContains('required', $rules, "{$field} should be required");
            }
        }
    }

    #[Test]
    public function the_owners_country_is_required(): void
    {
        $rules = ['country' => $this->stepRules('ownershipInfoRules')['country']];

        $this->assertTrue($this->fails($rules, []));
        $this->assertFalse($this->fails($rules, ['country' => 'United States']));
    }

    #[Test]
    public function an_ein_may_be_left_out_but_one_given_must_be_an_ein(): void
    {
        $rules = ['business_ein' => $this->stepRules('businessInfoRules')['business_ein']];

        $this->assertFalse($this->fails($rules, []));
        $this->assertFalse($this->fails($rules, ['business_ein' => null]));
        $this->assertTrue($this->fails($rules, ['business_ein' => '12-34567']));
        $this->assertTrue($this->fails($rules, ['business_ein' => 'AB-CDEFGHI']));
        $this->assertFalse($this->fails($rules, ['business_ein' => '12-3456789']));
        $this->assertFalse($this->fails($rules, ['business_ein' => '123456789']));
    }

    #[Test]
    public function years_in_business_is_no_longer_asked_for(): void
    {
        // Worked out from the business start date instead.
        $this->assertArrayNotHasKey('years_in_business', $this->stepRules('businessInfoRules'));
    }

    #[Test]
    public function the_business_start_date_is_still_required(): void
    {
        $rules = ['business_checking_start_date' => $this->stepRules('financialInfoRules')['business_checking_start_date']];

        $this->assertTrue($this->fails($rules, []));
        $this->assertTrue($this->fails($rules, ['business_checking_start_date' => now()->addDay()->toDateString()]));
        $this->assertFalse($this->fails($rules, ['business_checking_start_date' => '2020-01-15']));
    }

    #[Test]
    public function the_ein_is_sent_on_as_its_nine_digits(): void
    {
        $this->assertSame('123456789', $this->prepared(['business_ein' => '12-3456789'])->input('business_ein'));
    }

    // -- Funding request: currency --------------------------------------------

    #[Test]
    public function the_currency_must_be_one_the_form_offers(): void
    {
        $rules = ['currency' => $this->stepRules('fundingRequestRules')['currency']];

        $this->assertSame(['USD', 'EUR', 'GBP'], array_keys(config('funding_application.embed_form.currencies')));
        $this->assertTrue($this->fails($rules, ['currency' => 'BDT']));
        $this->assertTrue($this->fails($rules, []));
        foreach (['USD', 'EUR', 'GBP'] as $currency) {
            $this->assertFalse($this->fails($rules, ['currency' => $currency]));
        }
    }

    // -- Funding request: additional funding types ----------------------------

    private function fundingTypeRules(): array
    {
        return array_intersect_key(
            $this->stepRules('fundingRequestRules'),
            array_flip(['preferred_funding_type', 'additional_funding_types', 'additional_funding_types.*'])
        );
    }

    #[Test]
    public function additional_funding_types_are_optional(): void
    {
        $this->assertFalse($this->fails($this->fundingTypeRules(), ['preferred_funding_type' => 'Term Loan']));
        $this->assertFalse($this->fails($this->fundingTypeRules(), ['preferred_funding_type' => 'Term Loan', 'additional_funding_types' => []]));
    }

    #[Test]
    public function several_other_funding_types_can_be_chosen(): void
    {
        [$preferred, $second, $third] = config('funding_application.options.funding_types');

        $this->assertFalse($this->fails($this->fundingTypeRules(), [
            'preferred_funding_type' => $preferred,
            'additional_funding_types' => [$second, $third],
        ]));
    }

    #[Test]
    public function the_preferred_type_cannot_also_be_an_additional_one(): void
    {
        [$preferred, $second] = config('funding_application.options.funding_types');

        $this->assertTrue($this->fails($this->fundingTypeRules(), [
            'preferred_funding_type' => $preferred,
            'additional_funding_types' => [$second, $preferred],
        ]));
    }

    #[Test]
    public function an_additional_type_must_come_from_the_list_and_appear_once(): void
    {
        [$preferred, $second] = config('funding_application.options.funding_types');

        $this->assertTrue($this->fails($this->fundingTypeRules(), [
            'preferred_funding_type' => $preferred,
            'additional_funding_types' => ['Something Else'],
        ]));
        $this->assertTrue($this->fails($this->fundingTypeRules(), [
            'preferred_funding_type' => $preferred,
            'additional_funding_types' => [$second, $second],
        ]));
    }
}
