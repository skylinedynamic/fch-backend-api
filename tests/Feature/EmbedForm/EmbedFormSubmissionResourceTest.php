<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Resources\FCH\EmbedForm\EmbedFormSubmissionResource;
use App\Models\Borrower\Borrower;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
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

    #[Test]
    public function the_partner_it_came_through_is_named_with_its_type(): void
    {
        // So the host, who sees every partner's applications, can tell whose each is.
        $partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Acme Lending',
            'full_name' => 'Pat Partner',
            'email' => 'acme@example.test',
        ]);
        $submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);

        $data = (new EmbedFormSubmissionResource($submission->load('partner')))->resolve(Request::create('/'));

        $this->assertSame('Acme Lending', $data['partner']['company_name']);
        $this->assertSame(Partner::PARTNER_TYPE_WHITE_LABEL, $data['partner']['partner_type']);
        $this->assertFalse($data['partner']['is_host']);
    }

    /**
     * BUG-058: a submission converted onto an existing client shows that
     * client - their name, business, email, phone and real plan - not the
     * submitted applicant's details or a hard-coded "Free Plan".
     */
    private function convertedOnto(Borrower $borrower): array
    {
        $submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $borrower->partner_id,
            'first_name' => 'QA-TEST R2',
            'last_name' => 'Host Embed Two',
            'email' => 'embed-two@example.test',
            'status' => EmbedFormSubmission::STATUS_CONVERTED,
            'converted_borrower_id' => $borrower->id,
        ]);

        $submission = EmbedFormSubmission::with('convertedBorrower.latestSubscription')->findOrFail($submission->id);

        return (new EmbedFormSubmissionResource($submission))->resolve(Request::create('/'));
    }

    private function client(array $attributes = []): Borrower
    {
        $partner = Partner::where('is_host', true)->first() ?? Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        return Borrower::create(array_merge([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'QA-TEST Owner',
            'last_name' => 'Client Two',
            'email' => 'client-two@example.test',
            'phone_number' => '5551234567',
            'business_name' => 'Client Two LLC',
            'is_free_trail' => false,
        ], $attributes));
    }

    #[Test]
    public function the_converted_borrower_is_the_existing_clients_own_details(): void
    {
        $data = $this->convertedOnto($this->client())['converted_borrower'];

        $this->assertSame('QA-TEST Owner Client Two', $data['full_name']);
        $this->assertSame('client-two@example.test', $data['email']);
        $this->assertSame('5551234567', $data['phone']);
        $this->assertSame('Client Two LLC', $data['business_name']);
    }

    #[Test]
    public function a_paid_clients_plan_is_its_enrollment_type(): void
    {
        $client = $this->client();
        Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $client->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $data = $this->convertedOnto($client)['converted_borrower'];

        $this->assertFalse($data['is_free_trail']);
        $this->assertSame(SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT, $data['enrollment_type']);
    }

    #[Test]
    public function a_client_without_a_subscription_has_no_enrollment_type(): void
    {
        $data = $this->convertedOnto($this->client(['is_free_trail' => true]))['converted_borrower'];

        $this->assertTrue($data['is_free_trail']);
        $this->assertNull($data['enrollment_type']);
    }
}
