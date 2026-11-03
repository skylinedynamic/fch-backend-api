<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplicationDocument;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The documents an applicant uploaded on the embedded form, when the submission
 * is converted to a funding application.
 *
 * They are moved into the funding application's own folder - the one the
 * borrower and partner uploads use, funding-application/{borrower}/FA-{id} -
 * not copied, so each file is on disk once. The submission's attachment rows
 * follow the file, so it still opens from the submission.
 */
class EmbedFormConversionDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    private Borrower $borrower;

    private EmbedFormSubmission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();
        Notification::fake();

        $this->partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        // An existing borrower, so the conversion only creates the application.
        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);

        $this->submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);
    }

    private function attachment(string $originalName, string $contents = 'file'): EmbedFormAttachment
    {
        $path = "embed_forms/{$this->partner->id}/{$this->submission->id}/" . Str::uuid() . '.' . pathinfo($originalName, PATHINFO_EXTENSION);
        Storage::disk('public')->put($path, $contents);

        return EmbedFormAttachment::create([
            'submission_id' => $this->submission->id,
            'partner_id' => $this->partner->id,
            'field_name' => 'documents',
            'original_name' => $originalName,
            'file_path' => $path,
            'file_size' => strlen($contents),
            'mime_type' => 'application/pdf',
        ]);
    }

    private function convert(): int
    {
        $result = app(EmbedFormService::class)->convertToBorrower(
            $this->submission->fresh(),
            ['confirm_existing_borrower' => true]
        );

        return (int) $result['funding_application_id'];
    }

    #[Test]
    public function documents_land_in_the_funding_applications_folder(): void
    {
        $this->attachment('bank-statement.pdf', 'statement');

        $applicationId = $this->convert();

        $document = FundingApplicationDocument::where('funding_application_id', $applicationId)->sole();
        $this->assertStringStartsWith("funding-application/{$this->borrower->id}/FA-{$applicationId}/", $document->path);
        $this->assertStringEndsWith('.pdf', $document->path);
        $this->assertSame('statement', Storage::disk('public')->get($document->path));
        $this->assertSame('bank-statement.pdf', $document->original_name);
    }

    #[Test]
    public function the_embedded_form_copy_is_deleted_so_each_file_is_kept_once_per_place(): void
    {
        // Once on the funding application, once in the client's documents -
        // and no longer in the embedded form's folder.
        $original = $this->attachment('bank-statement.pdf')->file_path;

        $this->convert();

        Storage::disk('public')->assertMissing($original);
        $this->assertSame([], Storage::disk('public')->allFiles("embed_forms/{$this->partner->id}/{$this->submission->id}"));
        $this->assertCount(1, Storage::disk('public')->allFiles('funding-application'));
        $this->assertCount(1, Storage::disk('public')->allFiles('borrowers'));
        // Nowhere else: the audit trail records a document by its hash, without a copy.
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    // -- Clients > Details > Documents ------------------------------------

    #[Test]
    public function each_document_is_also_in_the_clients_documents(): void
    {
        $type = \App\Models\Borrower\BorrowerDocType::create(['name' => 'Bank Statements']);
        $attachment = $this->attachment('bank-statement.pdf', 'statement');
        $attachment->update(['borrower_doc_type_id' => $type->id]);

        $applicationId = $this->convert();

        $document = BorrowerDocument::where('borrower_id', $this->borrower->id)->sole();
        $stored = $document->getRawOriginal('file_path');
        // Stored as the file name, in borrowers/{id}/ - as an upload from the Documents tab is.
        $this->assertStringNotContainsString('/', $stored);
        $this->assertSame('statement', Storage::disk('public')->get("borrowers/{$this->borrower->id}/{$stored}"));
        $this->assertSame('bank-statement.pdf', $document->file_name);
        $this->assertSame($type->id, $document->document_type_id);
        $this->assertSame($applicationId, (int) $document->application_id);
        $this->assertSame(BorrowerDocument::STATUS_UPLOADED, $document->status);
    }

    #[Test]
    public function the_clients_documents_tab_lists_them(): void
    {
        $this->attachment('bank-statement.pdf');
        $this->attachment('tax-return.pdf');

        $this->convert();

        // The Documents tab is read by someone who can see the client: here the
        // host partner's staff (BorrowerService scopes it with Borrower::visibleTo).
        \Laravel\Passport\Passport::actingAs(\App\Models\User\User::create([
            'name' => 'Host Staff',
            'email' => 'staff@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => \App\Models\User\User::PARTNER,
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
        ]));

        $listed = collect(app(\App\Services\FCH\Common\Borrower\BorrowerService::class)->getBorrowerDocuments($this->borrower->id))
            ->pluck('document_name')->sort()->values()->all();
        $this->assertSame(['bank-statement.pdf', 'tax-return.pdf'], $listed);
    }

    #[Test]
    public function a_failure_on_the_clients_copy_rolls_back_both_copies(): void
    {
        $original = $this->attachment('bank-statement.pdf')->file_path;
        BorrowerDocument::creating(fn () => throw new \RuntimeException('database down'));

        try {
            $this->convert();
            $this->fail('the conversion should have failed');
        } catch (\Throwable) {
            // expected
        }

        Storage::disk('public')->assertExists($original);
        $this->assertSame([], Storage::disk('public')->allFiles('funding-application'));
        $this->assertSame([], Storage::disk('public')->allFiles('borrowers'));
        $this->assertSame(0, BorrowerDocument::count());
    }

    #[Test]
    public function the_submission_still_opens_its_attachment_from_the_new_place(): void
    {
        $attachment = $this->attachment('bank-statement.pdf', 'statement');

        $applicationId = $this->convert();

        $document = FundingApplicationDocument::where('funding_application_id', $applicationId)->sole();
        $this->assertSame($document->path, $attachment->fresh()->file_path);
        Storage::disk('public')->assertExists($attachment->fresh()->file_path);
    }

    #[Test]
    public function every_attachment_is_moved(): void
    {
        $this->attachment('one.pdf');
        $this->attachment('two.pdf');

        $applicationId = $this->convert();

        $this->assertSame(2, FundingApplicationDocument::where('funding_application_id', $applicationId)->count());
        $this->assertCount(2, Storage::disk('public')->allFiles("funding-application/{$this->borrower->id}/FA-{$applicationId}"));
        $this->assertSame([], Storage::disk('public')->allFiles('embed_forms'));
    }

    #[Test]
    public function a_file_missing_on_disk_is_skipped_without_failing_the_conversion(): void
    {
        $missing = $this->attachment('gone.pdf');
        Storage::disk('public')->delete($missing->file_path);
        $this->attachment('kept.pdf');

        $applicationId = $this->convert();

        $this->assertSame(['kept.pdf'], FundingApplicationDocument::where('funding_application_id', $applicationId)->pluck('original_name')->all());
        $this->assertSame(EmbedFormSubmission::STATUS_CONVERTED, $this->submission->fresh()->status);
    }

    #[Test]
    public function a_conversion_that_fails_keeps_the_embedded_files_and_leaves_no_copies(): void
    {
        // The originals are deleted only once the conversion has committed: a
        // rollback must not lose the applicant's uploads.
        $original = $this->attachment('bank-statement.pdf')->file_path;
        FundingApplicationDocument::creating(fn () => throw new \RuntimeException('database down'));

        try {
            $this->convert();
            $this->fail('the conversion should have failed');
        } catch (\Throwable) {
            // expected
        }

        Storage::disk('public')->assertExists($original);
        $this->assertSame($original, EmbedFormAttachment::sole()->file_path);
        $this->assertSame([], Storage::disk('public')->allFiles('funding-application'));
        $this->assertSame([], Storage::disk('public')->allFiles('borrowers'));
    }
}
