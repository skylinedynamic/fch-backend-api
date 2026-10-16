<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Resources\FCH\EmbedForm\EmbedFormSubmissionResource;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the partner portal's submission details page is given for the business
 * EIN, the business start date, the currency and the additional funding types.
 */
class EmbedFormSubmissionResourceTest extends TestCase
{
    use RefreshDatabase;

    private function resource(array $attributes): array
    {
        $partner = Partner::where('is_host', true)->first() ?? Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $submission = EmbedFormSubmission::create(array_merge([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ], $attributes));

        return (new EmbedFormSubmissionResource($submission->fresh()))->resolve(Request::create('/'));
    }

    #[Test]
    public function the_ein_is_given_written_as_an_ein(): void
    {
        $data = $this->resource(['business_ein' => '123456789']);

        $this->assertSame('123456789', $data['business_ein']);
        $this->assertSame('12-3456789', $data['business_ein_display']);
    }

    #[Test]
    public function the_requested_amount_is_shown_in_its_currency(): void
    {
        $data = $this->resource(['requested_amount' => 150000, 'currency' => 'EUR']);

        $this->assertSame('EUR', $data['currency']);
        $this->assertSame('€', $data['currency_symbol']);
        $this->assertSame('€150,000.00', $data['requested_amount_formatted']);
    }

    #[Test]
    public function a_submission_from_before_the_currency_choice_is_in_dollars(): void
    {
        $data = $this->resource(['requested_amount' => 150000]);

        $this->assertSame('USD', $data['currency']);
        $this->assertSame('$150,000.00', $data['requested_amount_formatted']);
    }

    #[Test]
    public function the_additional_funding_types_are_a_list(): void
    {
        $this->assertSame(['SBA Loan', 'Line of Credit'], $this->resource([
            'additional_funding_types' => ['SBA Loan', 'Line of Credit'],
        ])['additional_funding_types']);
        $this->assertSame([], $this->resource([])['additional_funding_types']);
    }
}
