<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A borrower's uploaded documents (bank statements, IDs, tax returns) through
 * /common/borrowers-documents*: listing, downloading, uploading, reviewing and
 * deleting. Each document is reachable exactly as far as its borrower is - see
 * Borrower::scopeVisibleTo().
 *
 * Defect: download, status and delete looked a document up by its id alone,
 * and upload accepted any borrower_id, so any signed-in user - a borrower
 * included - could fetch, approve, delete or plant files on any borrower.
 */
#[CoversClass(BorrowerController::class)]
class BorrowerDocumentsApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private Partner $otherPartner;
    private Borrower $borrower;
    private User $partnerAdmin;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->borrower = $this->makeBorrower($this->partner);
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);

        // Downloads read storage_path() directly, and the real storage holds
        // the developer's own files under these same low ids - so it is
        // pointed at a throwaway folder for the test.
        $this->storage = sys_get_temp_dir() . '/fch-storage-' . uniqid();
        foreach (['app/public', 'framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $dir) {
            File::ensureDirectoryExists("{$this->storage}/{$dir}");
        }
        $this->copyPassportKeys(storage_path(), $this->storage);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    /** A document row plus its file on the public disk, where downloads read it from. */
    private function documentFor(Borrower $borrower, string $contents = 'statement'): BorrowerDocument
    {
        $name = 'doc-' . uniqid() . '.txt';
        Storage::disk('public')->put("borrowers/{$borrower->id}/{$name}", $contents);

        return BorrowerDocument::create([
            'borrower_id' => $borrower->id,
            'file_name' => 'Bank statement.txt',
            'file_path' => $name,
            'status' => BorrowerDocument::STATUS_UPLOADED,
        ]);
    }

    #[Test]
    public function the_document_routes_need_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/common/borrowers-documents/download/1')->assertUnauthorized();
        $this->postJson('/api/v1/common/borrowers-documents/upload')->assertUnauthorized();
        $this->postJson('/api/v1/common/borrowers-documents/update-status/1')->assertUnauthorized();
        $this->postJson('/api/v1/common/borrowers-documents-delete/1')->assertUnauthorized();
    }

    #[Test]
    public function the_partner_downloads_its_borrowers_document(): void
    {
        $document = $this->documentFor($this->borrower, 'statement body');
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->get("/api/v1/common/borrowers-documents/download/{$document->id}")->assertOk();

        $this->assertSame('statement body', $response->streamedContent());
        $this->assertStringContainsString('Bank statement.txt', $response->headers->get('Content-Disposition'));
    }

    /**
     * Defect (O16): uploads write through the `public` disk but the download
     * read storage_path('app/public/...') directly, so a file on a public disk
     * with any other root (moved, or faked as here) was "not found".
     */
    #[Test]
    public function the_download_reads_the_file_through_the_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put("borrowers/{$this->borrower->id}/on-disk.txt", 'from the disk');
        $document = BorrowerDocument::create([
            'borrower_id' => $this->borrower->id,
            'file_name' => 'On disk.txt',
            'file_path' => 'on-disk.txt',
            'status' => BorrowerDocument::STATUS_UPLOADED,
        ]);
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->get("/api/v1/common/borrowers-documents/download/{$document->id}")->assertOk();

        $this->assertSame('from the disk', $response->streamedContent());
    }

    #[Test]
    public function the_borrower_downloads_their_own_document(): void
    {
        $user = $this->makeBorrowerUser($this->partner);
        $document = $this->documentFor(Borrower::find($user->entity_id));
        $this->actingAsUser($user);

        $this->get("/api/v1/common/borrowers-documents/download/{$document->id}")->assertOk();
    }

    #[Test]
    public function another_partners_document_cannot_be_downloaded(): void
    {
        $document = $this->documentFor($this->makeBorrower($this->otherPartner));
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/borrowers-documents/download/{$document->id}")->assertNotFound();
    }

    #[Test]
    public function a_borrower_cannot_download_another_borrowers_document(): void
    {
        $document = $this->documentFor($this->borrower);
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->getJson("/api/v1/common/borrowers-documents/download/{$document->id}")->assertNotFound();
    }

    #[Test]
    public function a_missing_document_or_file_is_not_found(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $this->getJson('/api/v1/common/borrowers-documents/download/999999')->assertNotFound();

        $orphan = BorrowerDocument::create([
            'borrower_id' => $this->borrower->id,
            'file_name' => 'gone.pdf',
            'file_path' => 'gone-' . uniqid() . '.pdf',
            'status' => BorrowerDocument::STATUS_UPLOADED,
        ]);
        $this->getJson("/api/v1/common/borrowers-documents/download/{$orphan->id}")->assertNotFound();
    }

    #[Test]
    public function the_partner_lists_its_borrowers_documents_newest_first(): void
    {
        $first = $this->documentFor($this->borrower);
        $this->travel(1)->minutes();
        $second = $this->documentFor($this->borrower);
        $this->actingAsUser($this->partnerAdmin);

        $ids = collect($this->postJson("/api/v1/common/borrowers-documents/{$this->borrower->id}")->assertOk()->json('data'))
            ->pluck('id');

        $this->assertSame([$second->id, $first->id], $ids->all());
    }

    #[Test]
    public function the_partner_rejects_a_document_with_a_reason(): void
    {
        $document = $this->documentFor($this->borrower);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/borrowers-documents/update-status/{$document->id}", [
            'status' => BorrowerDocument::STATUS_REJECTED,
            'rejection_reason' => 'Blurry scan',
        ])->assertOk();

        $document->refresh();
        $this->assertSame(BorrowerDocument::STATUS_REJECTED, $document->status);
        $this->assertSame('Blurry scan', $document->rejection_reason);
        $this->assertSame($this->partnerAdmin->id, $document->updated_by);
    }

    #[Test]
    public function accepting_a_document_clears_an_earlier_rejection_reason(): void
    {
        $document = $this->documentFor($this->borrower);
        $document->update(['status' => BorrowerDocument::STATUS_REJECTED, 'rejection_reason' => 'Blurry']);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/borrowers-documents/update-status/{$document->id}", [
            'status' => BorrowerDocument::STATUS_ACCEPTED,
        ])->assertOk();

        $this->assertNull($document->fresh()->rejection_reason);
    }

    #[Test]
    public function a_rejection_needs_a_reason_and_the_status_must_be_known(): void
    {
        $document = $this->documentFor($this->borrower);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/borrowers-documents/update-status/{$document->id}", ['status' => BorrowerDocument::STATUS_REJECTED])
            ->assertStatus(422);
        $this->postJson("/api/v1/common/borrowers-documents/update-status/{$document->id}", ['status' => 'Shredded'])
            ->assertStatus(422);
    }

    #[Test]
    public function another_partners_document_cannot_be_reviewed(): void
    {
        $document = $this->documentFor($this->makeBorrower($this->otherPartner));
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/borrowers-documents/update-status/{$document->id}", [
            'status' => BorrowerDocument::STATUS_ACCEPTED,
        ])->assertNotFound();

        $this->assertSame(BorrowerDocument::STATUS_UPLOADED, $document->fresh()->status);
    }

    #[Test]
    public function the_partner_deletes_its_borrowers_document_softly_recording_who(): void
    {
        $document = $this->documentFor($this->borrower);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/borrowers-documents-delete/{$document->id}")->assertOk();

        $this->assertSoftDeleted($document);
        $this->assertSame($this->partnerAdmin->id, BorrowerDocument::withTrashed()->find($document->id)->deleted_by);
    }

    #[Test]
    public function another_partners_document_cannot_be_deleted(): void
    {
        $document = $this->documentFor($this->makeBorrower($this->otherPartner));
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/borrowers-documents-delete/{$document->id}")->assertNotFound();

        $this->assertNotSoftDeleted($document);
    }

    #[Test]
    public function the_partner_attaches_already_uploaded_files_to_its_borrower(): void
    {
        $type = BorrowerDocType::create(['name' => 'Bank Statement']);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-documents/upload', [
            'borrower_id' => $this->borrower->id,
            'document_name' => ['March statement.pdf'],
            'file_path' => ['borrowers/' . $this->borrower->id . '/march.pdf'],
            'document_type_id' => $type->id,
        ])->assertCreated();

        $this->assertDatabaseHas('borrower_documents', [
            'borrower_id' => $this->borrower->id,
            'file_name' => 'March statement.pdf',
            'document_type_id' => $type->id,
            'created_by' => $this->partnerAdmin->id,
        ]);
    }

    #[Test]
    public function files_cannot_be_attached_to_another_partners_borrower(): void
    {
        $theirs = $this->makeBorrower($this->otherPartner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-documents/upload', [
            'borrower_id' => $theirs->id,
            'document_name' => ['planted.pdf'],
            'file_path' => ['planted.pdf'],
        ])->assertNotFound();

        $this->assertDatabaseMissing('borrower_documents', ['borrower_id' => $theirs->id]);
    }

    #[Test]
    public function an_upload_needs_a_borrower_and_at_least_one_file(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/borrowers-documents/upload', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['borrower_id', 'document_name', 'file_path']);
    }

    #[Test]
    public function both_document_type_urls_list_the_types(): void
    {
        BorrowerDocType::create(['name' => 'Bank Statement']);
        BorrowerDocType::create(['name' => 'Driver License']);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson('/api/v1/common/doc-types')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/common/borrower-document-types')->assertOk()->assertJsonCount(2, 'data');
    }
}
