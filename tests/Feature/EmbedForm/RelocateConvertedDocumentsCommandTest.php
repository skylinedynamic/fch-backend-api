<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplicationDocument;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * embed-form:relocate-converted-documents - the one-off tidy-up for submissions
 * converted before the conversion moved its documents.
 *
 * Those conversions copied each upload to funding_applications/{borrower}/{id}/
 * and left the original in embed_forms/, so every file was on disk twice and in
 * a folder nothing else uses. The command moves the copy into the application's
 * own folder, funding-application/{borrower}/FA-{id}/, points the submission's
 * attachment at it and deletes the embed form original.
 */
class RelocateConvertedDocumentsCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $partnerId;

    private int $borrowerId;

    private int $applicationId;

    private EmbedFormSubmission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->partnerId = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ])->id;

        $this->borrowerId = Borrower::create([
            'partner_id' => $this->partnerId,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ])->id;

        $this->applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-TEST-' . uniqid(),
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->partnerId,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
            'status' => EmbedFormSubmission::STATUS_CONVERTED,
            'converted_borrower_id' => $this->borrowerId,
            'converted_funding_application_id' => $this->applicationId,
        ]);
    }

    /**
     * An upload as an old conversion left it: the original on the submission,
     * and a copy on the application in funding_applications/.
     *
     * @return array{0: EmbedFormAttachment, 1: FundingApplicationDocument}
     */
    private function convertedTheOldWay(string $name, string $contents = 'file'): array
    {
        $disk = Storage::disk('public');
        $original = "embed_forms/{$this->partnerId}/{$this->submission->id}/" . Str::uuid() . '.pdf';
        $copy = "funding_applications/{$this->borrowerId}/{$this->applicationId}/" . Str::uuid() . '.pdf';
        $disk->put($original, $contents);
        $disk->put($copy, $contents);

        $attachment = EmbedFormAttachment::create([
            'submission_id' => $this->submission->id,
            'partner_id' => $this->partnerId,
            'field_name' => 'documents',
            'original_name' => $name,
            'file_path' => $original,
            'file_size' => strlen($contents),
            'mime_type' => 'application/pdf',
        ]);

        $document = FundingApplicationDocument::create([
            'funding_application_id' => $this->applicationId,
            'borrower_id' => $this->borrowerId,
            'name' => $name,
            'path' => $copy,
            'disk' => 'public',
            'original_name' => $name,
            'extension' => 'pdf',
            'mime' => 'application/pdf',
            'size' => strlen($contents),
        ]);

        return [$attachment, $document];
    }

    private function newFolder(): string
    {
        return "funding-application/{$this->borrowerId}/FA-{$this->applicationId}";
    }

    #[Test]
    public function the_copy_is_moved_into_the_applications_folder(): void
    {
        [, $document] = $this->convertedTheOldWay('statement.pdf', 'statement');
        $old = $document->path;

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $path = $document->fresh()->path;
        $this->assertSame($this->newFolder() . '/' . basename($old), $path);
        $this->assertSame('statement', Storage::disk('public')->get($path));
        Storage::disk('public')->assertMissing($old);
        $this->assertSame([], Storage::disk('public')->allFiles('funding_applications'));
    }

    #[Test]
    public function the_embed_form_duplicate_is_deleted_and_the_submission_points_at_the_moved_file(): void
    {
        [$attachment, $document] = $this->convertedTheOldWay('statement.pdf');
        $original = $attachment->file_path;

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        Storage::disk('public')->assertMissing($original);
        $this->assertSame($document->fresh()->path, $attachment->fresh()->file_path);
        $this->assertSame([], Storage::disk('public')->allFiles('embed_forms'));
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function an_upload_whose_application_copy_is_gone_keeps_its_only_file(): void
    {
        // Deleted from the application since: the embed form file is the only
        // one left, so it stays where it is.
        [$attachment, $document] = $this->convertedTheOldWay('statement.pdf');
        Storage::disk('public')->delete($document->path);
        $document->delete();

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        Storage::disk('public')->assertExists($attachment->file_path);
        $this->assertSame($attachment->file_path, $attachment->fresh()->file_path);
    }

    #[Test]
    public function uploads_with_the_same_name_are_each_matched_once(): void
    {
        [$first] = $this->convertedTheOldWay('scan.pdf', 'aaa');
        [$second] = $this->convertedTheOldWay('scan.pdf', 'bbbb');

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $this->assertNotSame($first->fresh()->file_path, $second->fresh()->file_path);
        $this->assertSame('aaa', Storage::disk('public')->get($first->fresh()->file_path));
        $this->assertSame('bbbb', Storage::disk('public')->get($second->fresh()->file_path));
        $this->assertSame([], Storage::disk('public')->allFiles('embed_forms'));
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        [$attachment, $document] = $this->convertedTheOldWay('statement.pdf');
        $files = Storage::disk('public')->allFiles();

        $this->artisan('embed-form:relocate-converted-documents', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($files, Storage::disk('public')->allFiles());
        $this->assertSame($document->path, $document->fresh()->path);
        $this->assertSame($attachment->file_path, $attachment->fresh()->file_path);
    }

    #[Test]
    public function running_it_again_changes_nothing_more(): void
    {
        $this->convertedTheOldWay('statement.pdf');
        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();
        $files = Storage::disk('public')->allFiles();

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $this->assertSame($files, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_copy_missing_on_disk_is_reported_and_its_row_left_alone(): void
    {
        [$attachment, $document] = $this->convertedTheOldWay('statement.pdf');
        Storage::disk('public')->delete($document->path);

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $this->assertSame($document->path, $document->fresh()->path);
        // Its copy is gone, so the embed form file is the only one: kept.
        Storage::disk('public')->assertExists($attachment->file_path);
    }

    #[Test]
    public function submissions_that_were_not_converted_are_not_touched(): void
    {
        $this->submission->update(['status' => 'new', 'converted_funding_application_id' => null]);
        $path = "embed_forms/{$this->partnerId}/{$this->submission->id}/x.pdf";
        Storage::disk('public')->put($path, 'x');
        EmbedFormAttachment::create([
            'submission_id' => $this->submission->id,
            'partner_id' => $this->partnerId,
            'field_name' => 'documents',
            'original_name' => 'x.pdf',
            'file_path' => $path,
        ]);

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        Storage::disk('public')->assertExists($path);
    }
}
