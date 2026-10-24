<?php

namespace Tests\Feature\FundingRequest;

use App\Http\Controllers\Api\V1\Borrower\Funding\FundingRequestController;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower portal's own funding requests: /borrower/funding/requests/list,
 * /{id} and the supporting documents a request asks for.
 */
#[CoversClass(FundingRequestController::class)]
class BorrowerFundingRequestApiTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $partner;

    private User $me;

    private User $neighbour;

    private int $sequenceId;

    private int $myRequestId;

    private int $theirRequestId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
        $this->neighbour = $this->makeBorrowerUser($this->partner);
        $this->sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Lines of Credit', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->myRequestId = $this->requestFor($this->me);
        $this->theirRequestId = $this->requestFor($this->neighbour);
    }

    private function requestFor(User $borrowerUser): int
    {
        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrowerUser->entity_id,
            'partner_id' => $borrowerUser->partner_id,
            'funding_sequence_id' => $this->sequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'status' => FundingRequest::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_routes_are_for_signed_in_borrowers_only(): void
    {
        $this->postJson('/api/v1/borrower/funding/requests/list')->assertUnauthorized();

        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->postJson('/api/v1/borrower/funding/requests/list')->assertForbidden();
    }

    #[Test]
    public function i_see_only_my_own_requests(): void
    {
        $this->actingAsUser($this->me);

        $ids = collect($this->postJson('/api/v1/borrower/funding/requests/list')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$this->myRequestId], $ids->all());
    }

    /**
     * Defect (BUG-009, CP-FREQ-05): clicking a column a second time to sort it
     * descending kept the ascending order on every column.
     */
    #[Test]
    public function my_requests_sort_by_a_column_in_both_directions(): void
    {
        DB::table('funding_requests')->where('id', $this->myRequestId)->update(['requested_amount' => 20000]);
        $bigger = $this->requestFor($this->me);
        DB::table('funding_requests')->where('id', $bigger)->update(['requested_amount' => 50000]);
        $this->actingAsUser($this->me);

        $sorted = fn (string $order) => collect($this->postJson('/api/v1/borrower/funding/requests/list', [
            'sort_field' => 'requested_amount',
            'sort_order' => $order,
        ])->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$this->myRequestId, $bigger], $sorted('asc'));
        $this->assertSame([$bigger, $this->myRequestId], $sorted('desc'));
    }

    /**
     * Defect (BUG-009, 19-Oct note): APPLICATION STATUS is sortable on the
     * screen, but `application_status` is not a funding_requests column, so the
     * list fell back to newest first and the order never changed. It now
     * follows the application lifecycle - no application yet, Draft, ...,
     * Funded - in both directions.
     */
    #[Test]
    public function my_requests_sort_by_application_status_both_ways(): void
    {
        $funded = $this->requestFor($this->me);
        $draft = $this->requestFor($this->me);
        foreach ([$funded => FundingApplication::FUNDED, $draft => FundingApplication::DRAFT] as $requestId => $status) {
            DB::table('funding_applications')->insert([
                'funding_request_id' => $requestId,
                'borrower_id' => $this->me->entity_id,
                'partner_id' => $this->me->partner_id,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->actingAsUser($this->me);

        $sorted = fn (string $order) => collect($this->postJson('/api/v1/borrower/funding/requests/list', [
            'sort_field' => 'application_status',
            'sort_order' => $order,
        ])->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$this->myRequestId, $draft, $funded], $sorted('asc'));
        $this->assertSame([$funded, $draft, $this->myRequestId], $sorted('desc'));
    }

    #[Test]
    public function i_open_my_request_but_not_someone_elses(): void
    {
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/funding/requests/{$this->myRequestId}")->assertOk()->assertJsonPath('data.id', $this->myRequestId);
        $this->getJson("/api/v1/borrower/funding/requests/{$this->theirRequestId}")->assertJsonMissingPath('data.id');
    }

    #[Test]
    public function i_upload_a_supporting_document_for_a_product(): void
    {
        $type = BorrowerDocType::create(['name' => 'Bank Statement']);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/funding/requests/documents/upload', [
            'funding_sequence_id' => $this->sequenceId,
            'document_type_id' => $type->id,
            'files' => [UploadedFile::fake()->create('march.pdf', 10, 'application/pdf')],
        ])->assertCreated();

        $this->assertDatabaseHas('borrower_documents', ['borrower_id' => $this->me->entity_id, 'document_type_id' => $type->id]);
    }

    /**
     * Defect: uploading or removing a document while starting a new request
     * wrote the document type into an earlier request's `documents_uploaded`
     * for the same product. A new request must leave existing ones untouched;
     * it sends its own `documents_uploaded` when it is submitted.
     */
    #[Test]
    public function uploading_and_removing_a_document_does_not_change_an_existing_request(): void
    {
        $type = BorrowerDocType::create(['name' => 'Bank Statement']);
        DB::table('funding_requests')->where('id', $this->myRequestId)
            ->update(['documents_uploaded' => json_encode(['Tax Return'])]);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/funding/requests/documents/upload', [
            'funding_sequence_id' => $this->sequenceId,
            'document_type_id' => $type->id,
            'files' => [UploadedFile::fake()->create('march.pdf', 10, 'application/pdf')],
        ])->assertCreated();

        $this->assertSame(['Tax Return'], FundingRequest::findOrFail($this->myRequestId)->documents_uploaded);

        $document = BorrowerDocument::where('borrower_id', $this->me->entity_id)->firstOrFail();
        DB::table('funding_requests')->where('id', $this->myRequestId)
            ->update(['documents_uploaded' => json_encode(['Tax Return', 'Bank Statement'])]);

        $this->deleteJson("/api/v1/borrower/funding/requests/documents/{$document->id}", ['funding_sequence_id' => $this->sequenceId])
            ->assertOk();

        $this->assertSame(['Tax Return', 'Bank Statement'], FundingRequest::findOrFail($this->myRequestId)->documents_uploaded);
    }

    #[Test]
    public function an_upload_needs_a_product_type_and_files(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/funding/requests/documents/upload', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['funding_sequence_id', 'document_type_id', 'files']);
    }

    #[Test]
    public function i_delete_my_document_but_not_someone_elses(): void
    {
        $type = BorrowerDocType::create(['name' => 'Bank Statement']);
        $mine = BorrowerDocument::create(['borrower_id' => $this->me->entity_id, 'document_type_id' => $type->id, 'file_name' => 'a.pdf', 'file_path' => 'a.pdf']);
        $theirs = BorrowerDocument::create(['borrower_id' => $this->neighbour->entity_id, 'document_type_id' => $type->id, 'file_name' => 'b.pdf', 'file_path' => 'b.pdf']);
        $this->actingAsUser($this->me);

        $this->deleteJson("/api/v1/borrower/funding/requests/documents/{$theirs->id}", ['funding_sequence_id' => $this->sequenceId])
            ->assertStatus(500);
        $this->assertNotSoftDeleted($theirs);

        $this->deleteJson("/api/v1/borrower/funding/requests/documents/{$mine->id}", ['funding_sequence_id' => $this->sequenceId])
            ->assertOk();
        $this->assertSoftDeleted($mine);
    }
}
