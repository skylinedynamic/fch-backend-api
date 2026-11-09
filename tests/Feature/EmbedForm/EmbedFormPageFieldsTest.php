<?php

namespace Tests\Feature\EmbedForm;

use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fields the public form page asks for: the business EIN, the business
 * start date in place of years in business, the requested amount's currency,
 * and the additional funding types next to the preferred one.
 */
class EmbedFormPageFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function page(): TestResponse
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
        ]);

        return $this->get('/form/' . $config->public_key)->assertOk();
    }

    #[Test]
    public function the_business_step_asks_for_the_ein_written_as_an_ein(): void
    {
        $this->page()
            ->assertSee('Business EIN', false)
            ->assertSee('name="business_ein"', false)
            ->assertSee('data-type="ein"', false)
            ->assertSee('placeholder="XX-XXXXXXX"', false);
    }

    #[Test]
    public function the_ein_and_the_employees_are_the_optional_business_fields(): void
    {
        // data-req is what the page's own check treats as required.
        $page = $this->page()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*name="business_ein"(?![^>]*data-req)[^>]*>/', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*name="number_of_employees"(?![^>]*data-req)[^>]*>/', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*name="business_postal_code"[^>]*data-req[^>]*>/', $page);
    }

    /**
     * The SSN field is the portals' one: masked ***-**-1234 at rest, dots while
     * typed, the digits on the eye button. What is shown is never what is sent -
     * the number itself travels in a hidden field carrying the name.
     */
    #[Test]
    public function the_ssn_is_the_portals_masked_field_with_an_eye_button(): void
    {
        $page = $this->page()->getContent();

        // The owner: a visible field the check reads, and the named value beside it.
        $this->assertMatchesRegularExpression('/<input[^>]*id="ssn"[^>]*class="[^"]*ssn-field[^"]*"[^>]*data-req[^>]*data-type="ssn"[^>]*>/', $page);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="ssn"[^>]*name="ssn"[^>]*>/', $page);
        $this->assertMatchesRegularExpression('/<input type="hidden" class="ssn-value" name="ssn">/', $page);
        $this->assertStringContainsString('aria-label="Show SSN"', $page);

        // A co-borrower's SSN is the same field.
        $this->assertStringContainsString('class="ssn-value" name="co_borrowers[${index}][ssn]"', $page);

        // Masked at rest, the digits on the eye button.
        $this->assertStringContainsString('function ssnMask(value)', $page);
        $this->assertStringContainsString("'***-**-' + digits.slice(-4)", $page);
    }

    #[Test]
    public function the_owners_country_and_zip_are_required(): void
    {
        $page = $this->page()->getContent();

        $this->assertMatchesRegularExpression('/<select[^>]*name="country"[^>]*data-req[^>]*>/', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*name="zip_code"[^>]*data-req[^>]*>/', $page);
    }

    #[Test]
    public function the_date_picker_is_the_portals_calendar(): void
    {
        // Month and year dropdowns, the same calendar on a phone, and the
        // portals' styling in this form's brand colour.
        $this->page()
            ->assertSee('function yearDropdownPlugin(minYear)', false)
            ->assertSee('plugins: [yearDropdownPlugin(1900)]', false)
            ->assertSee('disableMobile: true', false)
            // Shown as the portals show a date (14 Mar, 1986); posted as YYYY-MM-DD.
            ->assertSee("dateFormat: 'Y-m-d'", false)
            ->assertSee('altInput: true', false)
            ->assertSee("altFormat: 'j M, Y'", false)
            ->assertSee('placeholder="Select date of birth"', false)
            ->assertSee('placeholder="Select business start date"', false)
            ->assertDontSee('placeholder="YYYY-MM-DD"', false)
            ->assertSee('.fp-yd-trigger', false)
            ->assertSee('background: var(--brand-primary) !important;', false);
    }

    /**
     * The date of birth calendar offers every year up to today, opens an empty
     * field on the latest date that is old enough (18 years back, from the
     * server), and an under-age pick gets the API's minimum-age message.
     */
    #[Test]
    public function the_date_of_birth_opens_eighteen_years_back_and_explains_an_under_age_pick(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00');

        $this->page()
            ->assertSee('const LATEST_DOB = "2008-11-09";', false)
            ->assertSee('const MINIMUM_AGE_MESSAGE = "You must be at least 18 years old to continue.";', false)
            ->assertSee('maxDate: today,', false)
            ->assertSee('if (isDob && !selectedDates.length) fp.jumpToDate(LATEST_DOB);', false)
            ->assertSee("if (el.dataset.datepicker === 'dob' && value > LATEST_DOB) return MINIMUM_AGE_MESSAGE;", false);

        Carbon::setTestNow();
    }

    #[Test]
    public function years_in_business_is_not_asked(): void
    {
        $this->page()->assertDontSee('name="years_in_business"', false);
    }

    #[Test]
    public function the_checking_date_is_asked_as_the_business_start_date(): void
    {
        // Same column, business_checking_start_date; only what the applicant reads changed.
        $this->page()
            ->assertSee('name="business_checking_start_date"', false)
            ->assertSee('Business Start Date', false)
            ->assertDontSee('Business Checking Opened', false)
            ->assertDontSee('Checking Opened', false);
    }

    #[Test]
    public function the_funding_step_offers_usd_eur_and_gbp(): void
    {
        $this->page()
            ->assertSee('name="currency"', false)
            ->assertSeeInOrder(['<option value="USD"', '<option value="EUR"', '<option value="GBP"'], false);
    }

    #[Test]
    public function additional_funding_types_follow_the_preferred_one_and_take_several(): void
    {
        $this->page()
            ->assertSeeInOrder(['name="preferred_funding_type"', 'Additional Funding Types', 'name="additional_funding_types[]"'], false)
            ->assertSee('id="additional_funding_types" multiple', false);
    }
}
