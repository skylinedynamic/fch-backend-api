<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\Borrower\Document\DocumentController;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Removing a document from the borrower portal's Documents page.
 *
 * Defect: R2 BUG-045 - the client removed an Accepted Driver's License that
 * an open funding request's option required; the delete answered 200 and the
 * option then reported the licence missing. An Accepted document that is the
 * only copy of a type an open request or application needs is kept, and the
 * borrower is told to upload a replacement first.
 */
#[CoversClass(DocumentController::class)]
class BorrowerDocumentInUseTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $me;
    private int $licenceType;
    private int $otherType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->makeBorrowerUser($this->makeHostPartner());
        $this->licenceType = BorrowerDocType::create(['name' => "Driver's License"])->id;
        $this->otherType = BorrowerDocType::create(['name' => 'Tax Return'])->id;
        $this->actingAsUser($this->me);
    }

    /** A funding request on an option that needs a driver's licence. */
    private function requestNeedingLicence(string $status = FundingRequest::STATUS_PENDING): int
    {
        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Option 5',
            'status' => 'Active',
            'require_documents_uploaded' => true,
            'allowed_documents' => json_encode([$this->licenceType]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $this->me->entity_id,
            'partner_id' => $this->me->partner_id,
            'funding_sequence_id' => $sequenceId,
            'funding_type' => 'Term Loan',
            'requested_amount' => 30000,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function document(int $typeId, string $status = BorrowerDocument::STATUS_ACCEPTED): BorrowerDocument
    {
        return BorrowerDocument::create([
            'borrower_id' => $this->me->entity_id,
            'document_type_id' => $typeId,
            'file_name' => 'licence.pdf',
            'file_path' => 'licence-' . uniqid() . '.pdf',
            'status' => $status,
        ]);
    }

    private function remove(BorrowerDocument $document)
    {
        return $this->deleteJson("/api/v1/borrower/documents/{$document->id}");
    }

    #[Test]
    public function an_accepted_document_an_open_request_needs_is_kept(): void
    {
        $requestId = $this->requestNeedingLicence();
        $licence = $this->document($this->licenceType);

        $this->remove($licence)
            ->assertStatus(422)
            ->assertJsonPath('message', __('borrower.document_in_use', ['requests' => "#{$requestId}"]));

        $this->assertNotSoftDeleted($licence);
    }

    #[Test]
    public function an_unfinished_application_also_keeps_it(): void
    {
        $requestId = $this->requestNeedingLicence(FundingRequest::STATUS_APPROVED);
        DB::table('funding_applications')->insert([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $this->me->entity_id,
            'partner_id' => $this->me->partner_id,
            'funding_request_id' => $requestId,
            'status' => FundingApplication::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('funding_requests')->where('id', $requestId)->update(['status' => FundingRequest::STATUS_ACCEPTED]);

        $this->remove($this->document($this->licenceType))->assertStatus(422);
    }

    #[Test]
    public function it_can_be_removed_once_a_replacement_is_uploaded(): void
    {
        $this->requestNeedingLicence();
        $old = $this->document($this->licenceType);
        $this->document($this->licenceType, BorrowerDocument::STATUS_UPLOADED);

        $this->remove($old)->assertOk();
        $this->assertSoftDeleted($old);
    }

    #[Test]
    public function a_document_nothing_open_needs_can_be_removed(): void
    {
        $this->requestNeedingLicence(FundingRequest::STATUS_REJECTED);
        $licence = $this->document($this->licenceType);
        $taxReturn = $this->document($this->otherType);

        $this->remove($licence)->assertOk();
        $this->remove($taxReturn)->assertOk();
    }

    #[Test]
    public function a_finished_application_does_not_keep_it(): void
    {
        $requestId = $this->requestNeedingLicence(FundingRequest::STATUS_ACCEPTED);
        DB::table('funding_applications')->insert([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $this->me->entity_id,
            'partner_id' => $this->me->partner_id,
            'funding_request_id' => $requestId,
            'status' => FundingApplication::CLOSED_COMPLETED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->remove($this->document($this->licenceType))->assertOk();
    }

    #[Test]
    public function a_document_not_yet_accepted_can_be_removed(): void
    {
        $this->requestNeedingLicence();

        $this->remove($this->document($this->licenceType, BorrowerDocument::STATUS_UNDER_REVIEW))->assertOk();
    }
}
