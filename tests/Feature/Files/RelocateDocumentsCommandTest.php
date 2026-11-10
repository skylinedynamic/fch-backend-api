<?php

namespace Tests\Feature\Files;

use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Partner\PartnerDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;
use App\Models\Resource\Resource;

/**
 * files:relocate {kind} (BUG-016) - files that move to a folder of their own,
 * so their records change path:
 *  - partner-documents: a partner's documents, out of partners/{id}/ (which
 *    also holds its public branding and pictures) to partner-documents/{id}/.
 *  - credit-reports: iSoftPull reports, out of the client's folder to
 *    credit-reports/{borrower}/{uuid}.pdf, the credit profile following.
 * A record moves only once the new copy matches byte for byte; the old file is
 * deleted only with --delete-source; re-running changes nothing.
 */
class RelocateDocumentsCommandTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
    }

    private function partnerDocument(string $path, string $contents): PartnerDocument
    {
        $partner = $this->makeWhiteLabelPartner();
        Storage::disk('uploads')->put("partners/{$partner->id}/{$path}", $contents);

        return PartnerDocument::create(['partner_id' => $partner->id, 'document_name' => 'TMFS Articles.pdf', 'file_path' => $path]);
    }

    #[Test]
    public function a_partner_document_moves_to_its_own_private_folder_and_its_record_follows(): void
    {
        $document = $this->partnerDocument('1783870360_TMFS_Articles.pdf', '%PDF articles');
        $old = "partners/{$document->partner_id}/1783870360_TMFS_Articles.pdf";

        $this->artisan('files:relocate', ['kind' => 'partner-documents'])->assertSuccessful();

        $path = $document->fresh()->getRawOriginal('file_path');
        $this->assertMatchesRegularExpression("#^partner-documents/{$document->partner_id}/tmfs-articles_[0-9a-f]{8}\.pdf$#", $path);
        $this->assertSame('%PDF articles', Storage::disk('uploads')->get($path));
        $this->assertFalse(Storage::disk('public')->exists($path));
        // Kept until --delete-source.
        $this->assertTrue(Storage::disk('uploads')->exists($old));
        $this->assertStringContainsString('/private-files/partner-documents/', $document->fresh()->file_path);
    }

    #[Test]
    public function with_delete_source_the_old_file_goes(): void
    {
        $document = $this->partnerDocument('w9.pdf', '%PDF w9');

        $this->artisan('files:relocate', ['kind' => 'partner-documents', '--delete-source' => true])->assertSuccessful();

        $this->assertFalse(Storage::disk('uploads')->exists("partners/{$document->partner_id}/w9.pdf"));
        $this->assertSame('%PDF w9', Storage::disk('uploads')->get($document->fresh()->getRawOriginal('file_path')));
    }

    #[Test]
    public function a_dry_run_changes_nothing_and_a_rerun_changes_nothing(): void
    {
        $document = $this->partnerDocument('w9.pdf', '%PDF w9');

        $this->artisan('files:relocate', ['kind' => 'partner-documents', '--dry-run' => true])->assertSuccessful();
        $this->assertSame('w9.pdf', $document->fresh()->getRawOriginal('file_path'));
        $this->assertSame([], Storage::disk('uploads')->allFiles('partner-documents'));

        $this->artisan('files:relocate', ['kind' => 'partner-documents'])->assertSuccessful();
        $moved = $document->fresh()->getRawOriginal('file_path');
        $this->artisan('files:relocate', ['kind' => 'partner-documents'])->assertSuccessful();
        $this->assertSame($moved, $document->fresh()->getRawOriginal('file_path'));
        $this->assertCount(1, Storage::disk('uploads')->allFiles('partner-documents'));
    }

    #[Test]
    public function a_record_whose_file_is_missing_is_reported_and_left_alone(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $document = PartnerDocument::create(['partner_id' => $partner->id, 'document_name' => 'gone.pdf', 'file_path' => 'gone.pdf']);

        $this->artisan('files:relocate', ['kind' => 'partner-documents'])->assertSuccessful();

        $this->assertSame('gone.pdf', $document->fresh()->getRawOriginal('file_path'));
    }

    #[Test]
    public function an_old_credit_report_moves_to_its_own_folder_and_the_profile_follows(): void
    {
        $borrower = $this->makeBorrower($this->makeHostPartner());
        Storage::disk('uploads')->put("borrowers/{$borrower->id}/credit_report_1793258566.pdf", '%PDF report');
        $document = BorrowerDocument::create(['borrower_id' => $borrower->id, 'file_name' => 'isoftpull.pdf', 'file_path' => 'credit_report_1793258566.pdf']);
        $document->forceFill(['is_credit_report' => true])->save();
        $profile = BorrowerCreditProfile::create([
            'borrower_id' => $borrower->id,
            'fico_score' => 700,
            'document_id' => $document->id,
            'pdf_url' => "http://127.0.0.1:8000/storage/borrowers/{$borrower->id}/credit_report_1793258566.pdf",
        ]);

        $this->artisan('files:relocate', ['kind' => 'credit-reports', '--delete-source' => true])->assertSuccessful();

        $path = $document->fresh()->getRawOriginal('file_path');
        $this->assertMatchesRegularExpression("#^credit-reports/{$borrower->id}/[0-9a-f-]{36}\.pdf$#", $path);
        $this->assertSame('%PDF report', Storage::disk('uploads')->get($path));
        $this->assertSame($path, $profile->fresh()->getRawOriginal('pdf_url'));
        $this->assertFalse(Storage::disk('uploads')->exists("borrowers/{$borrower->id}/credit_report_1793258566.pdf"));
    }

    #[Test]
    public function a_profile_whose_document_row_is_gone_still_follows_its_report_by_url(): void
    {
        $borrower = $this->makeBorrower($this->makeHostPartner());
        Storage::disk('uploads')->put("borrowers/{$borrower->id}/credit_report_5.pdf", '%PDF orphan');
        $profile = BorrowerCreditProfile::create([
            'borrower_id' => $borrower->id,
            'fico_score' => 700,
            'document_id' => null,
            'pdf_url' => "http://127.0.0.1:8000/storage/borrowers/{$borrower->id}/credit_report_5.pdf",
        ]);

        $this->artisan('files:relocate', ['kind' => 'credit-reports'])->assertSuccessful();

        $path = $profile->fresh()->getRawOriginal('pdf_url');
        $this->assertStringStartsWith("credit-reports/{$borrower->id}/", $path);
        $this->assertSame('%PDF orphan', Storage::disk('uploads')->get($path));
    }

    #[Test]
    public function provider_logos_move_out_of_the_private_resources_folder_to_a_public_one(): void
    {
        $resource = Resource::create([
            'resource_type' => Resource::TYPE_DOWNLOAD_ASSETS, 'title' => 'Website', 'status' => true,
            'file' => 'resources/guide.pdf',
        ]);
        Storage::disk('uploads')->put('resources/1778076238_Godaddy-logo.png', 'godaddy');
        Storage::disk('uploads')->put('resources/guide.pdf', '%PDF guide');
        // Two providers sharing one logo get one copy.
        $first = $resource->providers()->create(['name' => 'GoDaddy', 'logo' => 'resources/1778076238_Godaddy-logo.png']);
        $second = $resource->providers()->create(['name' => 'GoDaddy Pro', 'logo' => 'resources/1778076238_Godaddy-logo.png']);

        $this->artisan('files:relocate', ['kind' => 'resource-providers', '--delete-source' => true])->assertSuccessful();

        $logo = $first->fresh()->logo;
        $this->assertMatchesRegularExpression('#^resource-providers/1778076238-godaddy-logo_[0-9a-f]{8}\.png$#', $logo);
        $this->assertSame($logo, $second->fresh()->logo);
        $this->assertSame('godaddy', Storage::disk('public')->get($logo));
        $this->assertCount(1, Storage::disk('public')->allFiles('resource-providers'));
        $this->assertFalse(Storage::disk('uploads')->exists('resources/1778076238_Godaddy-logo.png'));
        // A resource's own file is never touched.
        $this->assertTrue(Storage::disk('uploads')->exists('resources/guide.pdf'));
    }

    #[Test]
    public function a_logo_that_is_also_a_resources_file_is_copied_but_never_deleted(): void
    {
        Storage::disk('uploads')->put('resources/shared.png', 'png');
        $resource = Resource::create([
            'resource_type' => Resource::TYPE_DOWNLOAD_ASSETS, 'title' => 'Logo pack', 'status' => true,
            'file' => 'resources/shared.png',
        ]);
        $provider = $resource->providers()->create(['name' => 'Acme', 'logo' => 'resources/shared.png']);

        $this->artisan('files:relocate', ['kind' => 'resource-providers', '--delete-source' => true])->assertSuccessful();

        $this->assertStringStartsWith('resource-providers/', $provider->fresh()->logo);
        $this->assertTrue(Storage::disk('uploads')->exists('resources/shared.png'));
    }

    #[Test]
    public function an_unknown_kind_is_refused(): void
    {
        $this->artisan('files:relocate', ['kind' => 'everything'])->assertFailed();
    }

    #[Test]
    public function filing_a_new_partner_document_moves_it_out_of_the_partners_public_folder(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        Storage::disk('uploads')->put("partners/{$partner->id}/w9.pdf", '%PDF w9');
        $this->actingAsUser($this->makeStaffUser($this->makeHostPartner()));

        $this->postJson('/api/v1/admin/partners/documents', ['partner_id' => $partner->id, 'file_path' => ['w9.pdf']])->assertCreated();

        $path = PartnerDocument::where('partner_id', $partner->id)->firstOrFail()->getRawOriginal('file_path');
        $this->assertMatchesRegularExpression("#^partner-documents/{$partner->id}/w9_[0-9a-f]{8}\.pdf$#", $path);
        $this->assertSame('%PDF w9', Storage::disk('uploads')->get($path));
        $this->assertFalse(Storage::disk('public')->exists("partners/{$partner->id}/w9.pdf"));
    }
}
