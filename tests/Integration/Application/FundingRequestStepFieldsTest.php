<?php

namespace Tests\Integration\Application;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Services\FCH\Common\Application\FundingRequestStepFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ConvertsEmbedFormSubmissions;
use Tests\Support\CreatesFundingTypes;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect: after the Funding Sequence of an application converted from the
 * embed form was changed on the Funding Request step, a later step's save
 * (Business Information sends funding_sequence_id) carried the wizard's first
 * loaded sequence id and put it back - the application then held the old
 * sequence under the new sequence's name. Only the Funding Request step of an
 * embed-form application may change the sequence.
 */
#[CoversClass(FundingRequestStepFields::class)]
#[CoversClass(FundingApplication::class)]
class FundingRequestStepFieldsTest extends TestCase
{
    use ConvertsEmbedFormSubmissions;
    use CreatesFundingTypes;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private FundingApplication $application;

    private int $termLoans;

    private int $fastTermLoans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFundingTypes();

        $this->termLoans = $this->sequence('Term Loans');
        $this->fastTermLoans = $this->sequence('Fast Term Loans');

        $borrower = $this->makeBorrower($this->makeHostPartner());
        $id = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-TEST-'.uniqid(),
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'status' => FundingApplication::DRAFT,
            'preferred_funding_type' => 'Term Loan',
            'funding_sequence_id' => $this->fastTermLoans,
            'approved_funding' => 'Fast Term Loans',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->markConvertedFromEmbedForm($id);
        $this->application = FundingApplication::findOrFail($id);
    }

    #[Test]
    public function another_steps_save_keeps_the_chosen_funding_sequence(): void
    {
        $data = app(FundingRequestStepFields::class)->apply($this->application, [
            'business_name' => 'Thornton Holdings LLC',
            'funding_sequence_id' => $this->termLoans,
        ]);

        $this->assertSame($this->fastTermLoans, (int) $data['funding_sequence_id']);
        $this->assertSame('Thornton Holdings LLC', $data['business_name']);
    }

    #[Test]
    public function the_funding_request_step_changes_it(): void
    {
        $data = app(FundingRequestStepFields::class)->apply($this->application, [
            'preferred_funding_type' => 'Term Loan',
            'funding_sequence_id' => $this->termLoans,
            'approved_funding' => 'Fast Term Loans',
        ]);

        $this->assertSame($this->termLoans, (int) $data['funding_sequence_id']);
        $this->assertSame('Term Loans', $data['approved_funding']);
    }

    #[Test]
    public function a_sequence_not_offered_or_not_sent_keeps_the_one_saved(): void
    {
        $fields = app(FundingRequestStepFields::class);

        foreach ([999999, null, ''] as $sent) {
            $data = $fields->apply($this->application, [
                'preferred_funding_type' => 'Term Loan',
                'funding_sequence_id' => $sent,
                'approved_funding' => 'Something Else',
            ]);

            $this->assertSame($this->fastTermLoans, (int) $data['funding_sequence_id']);
            $this->assertSame('Fast Term Loans', $data['approved_funding']);
        }
    }

    #[Test]
    public function the_preferred_funding_type_is_kept_whatever_is_sent(): void
    {
        $data = app(FundingRequestStepFields::class)->apply($this->application, [
            'preferred_funding_type' => 'Business Line of Credit',
            'funding_sequence_id' => $this->termLoans,
        ]);

        $this->assertSame('Term Loan', $data['preferred_funding_type']);
    }

    #[Test]
    public function without_a_type_of_its_own_the_application_is_held_to_its_funding_requests(): void
    {
        $requestId = $this->fundingRequest('SBA Financing');
        $this->application->forceFill(['preferred_funding_type' => null, 'funding_request_id' => $requestId])->save();

        $this->assertSame('SBA Financing', app(FundingRequestStepFields::class)->lockedPreferredFundingType($this->application->fresh()));
    }

    #[Test]
    public function a_save_attaching_another_funding_request_is_left_to_the_service(): void
    {
        $requestId = $this->fundingRequest('Term Loan');

        $data = app(FundingRequestStepFields::class)->apply($this->application, [
            'funding_request_id' => $requestId,
            'funding_sequence_id' => $this->termLoans,
        ]);

        $this->assertSame($this->termLoans, $data['funding_sequence_id']);
    }

    #[Test]
    public function an_application_from_a_funding_request_keeps_its_sequence_and_name(): void
    {
        $requestId = $this->fundingRequest('Term Loan');
        $this->application->forceFill(['funding_request_id' => $requestId])->save();
        $application = $this->application->fresh();

        $data = app(FundingRequestStepFields::class)->apply($application, [
            'funding_request_id' => $requestId,
            'preferred_funding_type' => 'Term Loan',
            'funding_sequence_id' => $this->termLoans,
            'approved_funding' => 'Term Loans',
        ]);

        $this->assertSame($this->fastTermLoans, (int) $data['funding_sequence_id']);
        $this->assertSame('Fast Term Loans', $data['approved_funding']);
    }

    #[Test]
    public function an_application_knows_it_was_converted_from_the_embed_form(): void
    {
        $this->assertTrue($this->application->isFromEmbedForm());

        $other = $this->application->replicate(['application_number']);
        $other->application_number = 'FUND-TEST-'.uniqid();
        $other->save();
        $this->assertFalse($other->isFromEmbedForm());
    }

    private function fundingRequest(string $fundingType): int
    {
        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $this->application->borrower_id,
            'partner_id' => $this->application->partner_id,
            'funding_sequence_id' => $this->termLoans,
            'funding_type' => $fundingType,
            'requested_amount' => 30000,
            'use_of_funds' => 'Working Capital',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sequence(string $name): int
    {
        return DB::table('funding_sequencings')->insertGetId([
            'name' => $name,
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'funding_type_id' => DB::table('lender_product_categories')->where('name', 'Term Loan')->value('id'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
