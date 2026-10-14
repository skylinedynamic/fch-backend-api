<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\Borrower\Document\DocumentController;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower portal's Documents page: /borrower/documents* - a borrower's
 * own uploads, grouped by document type, which only they can add or remove.
 */
#[CoversClass(DocumentController::class)]
class BorrowerPortalDocumentsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;
    private BorrowerDocType $statements;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
        $this->statements = BorrowerDocType::create(['name' => 'Bank Statement']);
    }

    private function documentFor(User $borrowerUser, ?int $typeId = null): BorrowerDocument
    {
        return BorrowerDocument::create([
            'borrower_id' => $borrowerUser->entity_id,
            'file_name' => 'statement.pdf',
            'file_path' => 'statement-' . uniqid() . '.pdf',
            'document_type_id' => $typeId,
            'status' => BorrowerDocument::STATUS_UPLOADED,
        ]);
    }

    #[Test]
    public function the_routes_are_for_signed_in_borrowers_only(): void
    {
        $this->getJson('/api/v1/borrower/documents')->assertUnauthorized();

        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->getJson('/api/v1/borrower/documents')->assertForbidden();
        $this->postJson('/api/v1/borrower/documents/upload', ['files' => ['x.pdf']])->assertForbidden();
    }

    #[Test]
    public function i_see_only_my_documents_grouped_by_type(): void
    {
        $mine = $this->documentFor($this->me, $this->statements->id);
        $this->documentFor($this->makeBorrowerUser($this->partner), $this->statements->id);
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/documents')
            ->assertOk()
            ->assertJsonCount(1, "data.{$this->statements->id}")
            ->assertJsonPath("data.{$this->statements->id}.0.id", $mine->id);
    }

    #[Test]
    public function uploading_files_records_them_as_mine(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/documents/upload', [
            'files' => ['borrowers/' . $this->me->entity_id . '/march.pdf'],
            'document_name' => ['March.pdf'],
            'document_type_id' => $this->statements->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('borrower_documents', [
            'borrower_id' => $this->me->entity_id,
            'document_type_id' => $this->statements->id,
            'created_by' => $this->me->id,
        ]);
    }

    #[Test]
    public function an_upload_needs_at_least_one_file_and_a_known_type(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/documents/upload', ['files' => []])->assertStatus(422);
        $this->postJson('/api/v1/borrower/documents/upload', ['files' => ['a.pdf'], 'document_type_id' => 999999])
            ->assertStatus(422);
    }

    #[Test]
    public function i_can_delete_my_document_but_not_someone_elses(): void
    {
        $mine = $this->documentFor($this->me);
        $theirs = $this->documentFor($this->makeBorrowerUser($this->partner));
        $this->actingAsUser($this->me);

        $this->deleteJson("/api/v1/borrower/documents/{$mine->id}")->assertOk();
        $this->assertSoftDeleted($mine);

        $this->deleteJson("/api/v1/borrower/documents/{$theirs->id}")->assertNotFound();
        $this->assertNotSoftDeleted($theirs);
    }

    #[Test]
    public function the_document_types_are_listed(): void
    {
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/documents/borrower-document-types')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bank Statement');
    }
}
