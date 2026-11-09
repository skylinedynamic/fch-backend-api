<?php

namespace Tests\Feature\FundApplication;

use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreFundingRequestRequest as BorrowerStoreFundingRequestRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreFundingRequestRequest as PartnerStoreFundingRequestRequest;
use App\Http\Resources\Borrower\FundApplication\FundingRequestResource as BorrowerFundingRequestResource;
use App\Http\Resources\FCH\Partner\FundApplication\FundingRequestResource as PartnerFundingRequestResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Rules\OfferedFundingSequence;
use App\Services\FCH\Common\Application\FundingRequestStepFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ConvertsEmbedFormSubmissions;
use Tests\Support\CreatesFundingTypes;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The Funding Request step of an existing funding application, in the admin /
 * partner wizard and the borrower wizard:
 *  - Preferred Funding Type is read-only: a save cannot change it;
 *  - only an application converted from the embed form has its Funding
 *    Sequence chosen on this step, from the sequences offered for its
 *    Preferred Funding Type; approved_funding is that sequence's name, set by
 *    the server;
 *  - every other application (from a funding request, or not) keeps the
 *    funding sequence it has - nothing sent can change it.
 */
#[CoversClass(PartnerStoreFundingRequestRequest::class)]
#[CoversClass(BorrowerStoreFundingRequestRequest::class)]
#[CoversClass(OfferedFundingSequence::class)]
#[CoversClass(FundingRequestStepFields::class)]
#[CoversClass(BorrowerFundingRequestResource::class)]
#[CoversClass(PartnerFundingRequestResource::class)]
class FundingSequenceOnFundingRequestStepTest extends TestCase
{
    use ConvertsEmbedFormSubmissions;
    use CreatesFundingTypes;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $partner;

    private User $borrowerUser;

    private Borrower $borrower;

    private int $termLoans;

    private int $creditLines;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFundingTypes();

        Storage::fake('public');
        Mail::fake();
        Notification::fake();

        $this->partner = $this->makeHostPartner();
        $this->borrowerUser = $this->makeBorrowerUser($this->partner);
        $this->borrower = Borrower::findOrFail($this->borrowerUser->entity_id);

        $this->termLoans = $this->sequence('Term Loans', 'Term Loan');
        $this->creditLines = $this->sequence('Business Lines of Credit', 'Business Line of Credit');
    }

    /** @return array<string, array{string}> */
    public static function wizards(): array
    {
        return [
            'admin / partner wizard' => ['partner'],
            'borrower wizard' => ['borrower'],
        ];
    }

    #[Test]
    #[DataProvider('wizards')]
    public function an_application_converted_from_the_embed_form_saves_the_chosen_funding_sequence(string $wizard): void
    {
        $applicationId = $this->embedApplication();
        $this->signIn($wizard);

        $this->postJson($this->url($wizard), $this->step($applicationId, [
            'funding_sequence_id' => $this->termLoans,
            // Whatever is sent, the name is the chosen sequence's.
            'approved_funding' => 'Something Else',
        ]))->assertOk()
            ->assertJsonPath('data.funding_sequence_id', $this->termLoans)
            ->assertJsonPath('data.approved_funding', 'Term Loans')
            ->assertJsonPath('data.funding_request_id', null);

        $application = FundingApplication::findOrFail($applicationId);
        $this->assertSame($this->termLoans, (int) $application->funding_sequence_id);
        $this->assertSame('Term Loans', $application->approved_funding);
        $this->assertNull($application->funding_request_id);
    }

    #[Test]
    #[DataProvider('wizards')]
    public function an_application_converted_from_the_embed_form_must_choose_a_funding_sequence(string $wizard): void
    {
        $applicationId = $this->embedApplication();
        $this->signIn($wizard);

        $this->postJson($this->url($wizard), $this->step($applicationId))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['funding_sequence_id' => __('borrower_validation.funding_sequence_required')]);

        $this->assertNull(FundingApplication::findOrFail($applicationId)->funding_sequence_id);
    }

    #[Test]
    #[DataProvider('wizards')]
    public function the_funding_sequence_must_be_one_offered_for_the_preferred_funding_type(string $wizard): void
    {
        $applicationId = $this->embedApplication();
        $inactive = $this->sequence('Paused Term Loans', 'Term Loan', 'Inactive');
        $this->signIn($wizard);

        foreach ([$this->creditLines, $inactive, 999999] as $sequenceId) {
            $this->postJson($this->url($wizard), $this->step($applicationId, ['funding_sequence_id' => $sequenceId]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['funding_sequence_id' => __('borrower_validation.funding_sequence_invalid')]);
        }

        $this->assertNull(FundingApplication::findOrFail($applicationId)->funding_sequence_id);
    }

    #[Test]
    #[DataProvider('wizards')]
    public function the_preferred_funding_type_cannot_be_changed(string $wizard): void
    {
        $applicationId = $this->embedApplication();
        $this->signIn($wizard);

        $this->postJson($this->url($wizard), $this->step($applicationId, [
            'preferred_funding_type' => 'Business Line of Credit',
            'funding_sequence_id' => $this->creditLines,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['preferred_funding_type' => __('borrower_validation.preferred_funding_type_locked')]);

        $this->assertSame('Term Loan', FundingApplication::findOrFail($applicationId)->preferred_funding_type);
    }

    #[Test]
    #[DataProvider('wizards')]
    public function a_preferred_funding_type_saved_under_an_old_name_is_kept_as_it_is(string $wizard): void
    {
        // An old name that is no Active funding type: read-only, so it is not
        // re-validated; the sequences offered are those of the type it means
        // ("Line of Credit" -> "Business Line of Credit").
        $applicationId = $this->embedApplication(['preferred_funding_type' => 'Line of Credit']);
        $this->signIn($wizard);

        $this->postJson($this->url($wizard), $this->step($applicationId, [
            'preferred_funding_type' => 'Line of Credit',
            'funding_sequence_id' => $this->termLoans,
        ]))->assertUnprocessable()
            ->assertJsonMissingValidationErrors('preferred_funding_type')
            ->assertJsonValidationErrors('funding_sequence_id');

        $this->postJson($this->url($wizard), $this->step($applicationId, [
            'preferred_funding_type' => 'Line of Credit',
            'funding_sequence_id' => $this->creditLines,
        ]))->assertOk();

        $application = FundingApplication::findOrFail($applicationId);
        $this->assertSame('Line of Credit', $application->preferred_funding_type);
        $this->assertSame($this->creditLines, (int) $application->funding_sequence_id);
        $this->assertSame('Business Lines of Credit', $application->approved_funding);
    }

    #[Test]
    #[DataProvider('wizards')]
    public function an_application_from_a_funding_request_keeps_its_funding_sequence(string $wizard): void
    {
        $requestId = $this->approvedRequest($this->termLoans);
        $applicationId = $this->application([
            'funding_request_id' => $requestId,
            'funding_sequence_id' => $this->termLoans,
            'approved_funding' => 'Term Loans',
        ]);
        $this->signIn($wizard);

        $this->postJson($this->url($wizard), $this->step($applicationId, [
            'funding_request_id' => $requestId,
            'funding_sequence_id' => $this->creditLines,
            'approved_funding' => 'Business Lines of Credit',
        ]))->assertOk();

        $application = FundingApplication::findOrFail($applicationId);
        $this->assertSame($this->termLoans, (int) $application->funding_sequence_id);
        $this->assertSame('Term Loans', $application->approved_funding);
        $this->assertSame($requestId, (int) $application->funding_request_id);
    }

    #[Test]
    #[DataProvider('wizards')]
    public function a_new_choice_replaces_the_one_saved_before(string $wizard): void
    {
        $applicationId = $this->embedApplication();
        $this->signIn($wizard);

        $this->postJson($this->url($wizard), $this->step($applicationId, ['funding_sequence_id' => $this->termLoans]))->assertOk();
        $fastTermLoans = $this->sequence('Fast Term Loans', 'Term Loan');
        $this->postJson($this->url($wizard), $this->step($applicationId, ['funding_sequence_id' => $fastTermLoans]))
            ->assertOk()
            ->assertJsonPath('data.funding_sequence_id', $fastTermLoans)
            ->assertJsonPath('data.approved_funding', 'Fast Term Loans');

        $application = FundingApplication::findOrFail($applicationId);
        $this->assertSame($fastTermLoans, (int) $application->funding_sequence_id);
        $this->assertSame('Fast Term Loans', $application->approved_funding);
    }

    #[Test]
    #[DataProvider('wizards')]
    public function the_step_says_whether_the_application_was_converted_from_the_embed_form(string $wizard): void
    {
        $embed = $this->embedApplication();
        $other = $this->application();
        $this->signIn($wizard);
        $read = fn (int $id) => $this->getJson($this->url($wizard)."?funding_application_id={$id}")->assertOk();

        $read($embed)->assertJsonPath('data.is_from_embed_form', true);
        $read($other)->assertJsonPath('data.is_from_embed_form', false);
    }

    #[Test]
    #[DataProvider('wizards')]
    public function an_application_without_a_funding_request_not_from_the_embed_form_keeps_its_funding_sequence(string $wizard): void
    {
        // No dropdown for it: nothing to choose, nothing sent can change it.
        $applicationId = $this->application(['funding_sequence_id' => $this->termLoans, 'approved_funding' => 'Term Loans']);
        $this->signIn($wizard);

        $this->postJson($this->url($wizard), $this->step($applicationId, [
            'funding_sequence_id' => $this->creditLines,
            'approved_funding' => 'Business Lines of Credit',
        ]))->assertOk();

        $application = FundingApplication::findOrFail($applicationId);
        $this->assertSame($this->termLoans, (int) $application->funding_sequence_id);
        $this->assertSame('Term Loans', $application->approved_funding);
    }

    #[Test]
    #[DataProvider('wizards')]
    public function an_application_from_a_funding_request_needs_no_funding_sequence_sent(string $wizard): void
    {
        $requestId = $this->approvedRequest($this->termLoans);
        $applicationId = $this->application([
            'funding_request_id' => $requestId,
            'funding_sequence_id' => $this->termLoans,
            'approved_funding' => 'Term Loans',
        ]);
        $this->signIn($wizard);

        $this->postJson($this->url($wizard), $this->step($applicationId, [
            'funding_request_id' => $requestId,
            'approved_funding' => 'Term Loans',
        ]))->assertOk()->assertJsonPath('data.funding_sequence_id', $this->termLoans);
    }

    private function signIn(string $wizard): void
    {
        $this->actingAsUser($wizard === 'partner' ? $this->makePartnerAdmin($this->partner) : $this->borrowerUser);
    }

    private function url(string $wizard): string
    {
        return $wizard === 'partner'
            ? '/api/v1/common/funding-application/funding-request'
            : '/api/v1/borrower/funding-application/funding-request';
    }

    /** @param  array<string, mixed>  $overrides */
    private function step(int $applicationId, array $overrides = []): array
    {
        return array_merge([
            'id' => $applicationId,
            'requested_amount' => 40000,
            'purpose_of_funds' => 'Working Capital',
            'preferred_funding_type' => 'Term Loan',
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    private function embedApplication(array $overrides = []): int
    {
        $id = $this->application($overrides);
        $this->markConvertedFromEmbedForm($id);

        return $id;
    }

    /** @param  array<string, mixed>  $overrides */
    private function application(array $overrides = []): int
    {
        return DB::table('funding_applications')->insertGetId(array_merge([
            'application_number' => 'FUND-TEST-'.uniqid(),
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->borrower->partner_id,
            'funding_request_id' => null,
            'funding_sequence_id' => null,
            'status' => FundingApplication::DRAFT,
            'business_name' => 'Thornton Holdings LLC',
            'requested_amount' => 25000,
            'purpose_of_funds' => 'Working Capital',
            'approved_funding' => null,
            'preferred_funding_type' => 'Term Loan',
            'is_business_info_saved' => true,
            'is_funding_request_saved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function sequence(string $name, string $type, string $status = 'Active'): int
    {
        return DB::table('funding_sequencings')->insertGetId([
            'name' => $name,
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'funding_type_id' => DB::table('lender_product_categories')->where('name', $type)->value('id'),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function approvedRequest(int $sequenceId): int
    {
        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'funding_sequence_id' => $sequenceId,
            'funding_type' => 'Term Loan',
            'requested_amount' => 30000,
            'use_of_funds' => 'Working Capital',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
