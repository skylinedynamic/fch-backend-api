<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\Borrower\FundingApplicationDocument;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormCoBorrower;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
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
        // Once on the application, once in the client's documents.
        $this->assertCount(1, Storage::disk('public')->allFiles('funding-application'));
        $this->assertCount(1, Storage::disk('public')->allFiles('borrowers'));
        // Nowhere else: the audit trail records a document by its hash, without a copy.
        $this->assertCount(2, Storage::disk('public')->allFiles());
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
        $this->assertSame(0, BorrowerDocument::withTrashed()->count());
    }

    #[Test]
    public function running_it_again_changes_nothing_more(): void
    {
        $this->convertedTheOldWay('statement.pdf');
        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();
        $files = Storage::disk('public')->allFiles();

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $this->assertSame($files, Storage::disk('public')->allFiles());
        $this->assertSame(1, BorrowerDocument::count());
    }

    // -- Clients > Details > Documents ------------------------------------

    #[Test]
    public function each_converted_upload_is_added_to_the_clients_documents(): void
    {
        $type = BorrowerDocType::create(['name' => 'Bank Statements']);
        [$attachment] = $this->convertedTheOldWay('statement.pdf', 'statement');
        $attachment->update(['borrower_doc_type_id' => $type->id]);

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $document = BorrowerDocument::where('borrower_id', $this->borrowerId)->sole();
        $stored = $document->getRawOriginal('file_path');
        $this->assertStringNotContainsString('/', $stored);
        $this->assertSame('statement', Storage::disk('public')->get("borrowers/{$this->borrowerId}/{$stored}"));
        $this->assertSame('statement.pdf', $document->file_name);
        $this->assertSame($type->id, $document->document_type_id);
        $this->assertSame($this->applicationId, (int) $document->application_id);
        $this->assertSame(BorrowerDocument::STATUS_UPLOADED, $document->status);
    }

    #[Test]
    public function an_application_document_that_did_not_come_from_the_form_is_not_added(): void
    {
        // Uploaded to the application by the borrower or partner later.
        Storage::disk('public')->put($this->newFolder() . '/later.pdf', 'later');
        FundingApplicationDocument::create([
            'funding_application_id' => $this->applicationId,
            'borrower_id' => $this->borrowerId,
            'name' => 'later.pdf',
            'path' => $this->newFolder() . '/later.pdf',
            'disk' => 'public',
            'original_name' => 'later.pdf',
            'size' => 5,
        ]);

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $this->assertSame(0, BorrowerDocument::count());
        $this->assertSame([], Storage::disk('public')->allFiles('borrowers'));
    }

    #[Test]
    public function a_client_document_deleted_since_is_not_added_back(): void
    {
        $this->convertedTheOldWay('statement.pdf');
        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();
        BorrowerDocument::sole()->delete();

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $this->assertSame(0, BorrowerDocument::count());
    }

    // -- Borrower profile -------------------------------------------------

    /** The answers an old conversion did not carry onto the profile. */
    private function answered(array $overrides = []): void
    {
        $this->submission->update(array_merge([
            'phone' => '+15122345678',
            'business_phone_no' => '+15129876543',
            'monthly_revenue' => '50000.00',
            'annual_revenue' => '600000.00',
            'self_reported_personal_credit_score' => '712',
            'entity_type' => 'C-Corporation',
            'business_city' => 'Dallas',
            'ssn_encrypted' => Crypt::encryptString('123456789'),
        ], $overrides));
    }

    #[Test]
    public function the_profile_blanks_are_filled_from_the_submission(): void
    {
        $this->answered();

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $borrower = Borrower::findOrFail($this->borrowerId);
        $this->assertEquals(50000, (float) $borrower->monthly_revenue);
        $this->assertEquals(600000, (float) $borrower->annual_revenue);
        $this->assertSame(712, (int) $borrower->fico_score);
        $this->assertSame('C-Corporation', $borrower->business_entity_type);
        $this->assertSame('Dallas', $borrower->business_city);
        $this->assertSame('123456789', $borrower->ssn);
    }

    #[Test]
    public function a_borrower_left_with_only_the_last_four_of_the_ssn_gets_the_full_ssn(): void
    {
        // What conversions before this change did.
        Borrower::findOrFail($this->borrowerId)->forceFill(['last_4_ssn' => '6789'])->save();
        $this->answered();

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $this->assertSame('123456789', Borrower::findOrFail($this->borrowerId)->ssn);
    }

    #[Test]
    public function what_is_already_on_the_profile_is_kept(): void
    {
        Borrower::whereKey($this->borrowerId)->update(['monthly_revenue' => 10000, 'fico_score' => 650]);
        $this->answered();

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $borrower = Borrower::findOrFail($this->borrowerId);
        $this->assertEquals(10000, (float) $borrower->monthly_revenue);
        $this->assertSame(650, (int) $borrower->fico_score);
        $this->assertEquals(600000, (float) $borrower->annual_revenue);
    }

    // -- Phones -----------------------------------------------------------

    #[Test]
    public function phones_are_put_in_the_portals_format(): void
    {
        $this->answered();
        Borrower::whereKey($this->borrowerId)->update([
            'phone_number' => '+15122345678',
            'business_phone' => '+15129876543',
            'country_code' => 'US',
        ]);
        DB::table('funding_applications')->where('id', $this->applicationId)->update([
            'phone_number' => '+15122345678',
            'business_phone_no' => '+15129876543',
        ]);
        $applicationCoBorrower = FundingApplicationCoBorrower::create([
            'funding_application_id' => $this->applicationId,
            'first_name' => 'Sam',
            'mobile_phone' => '+15125550199',
            'home_phone' => '+15125550188',
        ]);
        $formCoBorrower = EmbedFormCoBorrower::create([
            'submission_id' => $this->submission->id,
            'first_name' => 'Sam',
            'mobile_phone' => '+15125550199',
        ]);

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $borrower = Borrower::findOrFail($this->borrowerId);
        $this->assertSame('(512) 234-5678', $borrower->phone_number);
        $this->assertSame('(512) 987-6543', $borrower->business_phone);
        $this->assertSame('+1', $borrower->country_code);
        $application = DB::table('funding_applications')->find($this->applicationId);
        $this->assertSame('(512) 234-5678', $application->phone_number);
        $this->assertSame('(512) 987-6543', $application->business_phone_no);
        $this->assertSame('(512) 555-0199', $applicationCoBorrower->fresh()->mobile_phone);
        $this->assertSame('(512) 555-0188', $applicationCoBorrower->fresh()->home_phone);
        $this->assertSame('(512) 234-5678', $this->submission->fresh()->phone);
        $this->assertSame('(512) 987-6543', $this->submission->fresh()->business_phone_no);
        $this->assertSame('(512) 555-0199', $formCoBorrower->fresh()->mobile_phone);
    }

    #[Test]
    public function a_borrower_who_did_not_come_from_the_form_is_not_touched(): void
    {
        $other = Borrower::create([
            'partner_id' => $this->partnerId,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Other',
            'last_name' => 'Person',
            'email' => 'other@example.test',
            'phone_number' => '+15120000000',
            'country_code' => 'US',
        ]);

        $this->artisan('embed-form:relocate-converted-documents')->assertSuccessful();

        $this->assertSame('+15120000000', $other->fresh()->phone_number);
        $this->assertSame('US', $other->fresh()->country_code);
    }

    #[Test]
    public function a_dry_run_leaves_the_profile_and_phones_alone(): void
    {
        $this->answered();
        Borrower::whereKey($this->borrowerId)->update(['phone_number' => '+15122345678', 'country_code' => 'US']);

        $this->artisan('embed-form:relocate-converted-documents', ['--dry-run' => true])->assertSuccessful();

        $borrower = Borrower::findOrFail($this->borrowerId);
        $this->assertNull($borrower->monthly_revenue);
        $this->assertSame('+15122345678', $borrower->phone_number);
        $this->assertSame('US', $borrower->country_code);
        $this->assertSame('+15122345678', $this->submission->fresh()->phone);
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
