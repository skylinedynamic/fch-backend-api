<?php

namespace Tests\Feature\Files;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplicationDocument;
use App\Support\Files\FileUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;
use App\Models\Partner\PartnerDocument;
use App\Models\Resource\ResourceProvider;
use App\Services\Audit\FundingApplicationAuditTrail;
use App\Services\FCH\Common\ManageAgreement\AgreementImageStore;

/**
 * BUG-016: which folders are private. A client's, an application's, an embed
 * form's, an activity's or a partner's documents, credit reports and the audit
 * trail are kept on the private disk and handed out only by a signed URL; the
 * branding, logos and pictures shown on login pages, emails and the website
 * stay public, at the URL they always had.
 */
class PrivateFoldersTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    public static function privateFolders(): array
    {
        return [
            ['borrowers/7/bank_3f9a2c1e.pdf'],
            ['credit-reports/7/4b1c-report.pdf'],
            ['funding-application/7/FA-3/bank.pdf'],
            ['partner-documents/4/w9_1a2b3c4d.pdf'],
            ['embed_forms/4/9/paystub.pdf'],
            ['attachments/note.pdf'],
            ['audit/files/abc.pdf'],
            ['agreement-images/3f9a2c1e.png'],
            ['resources/Resources/ein-overview.pdf'],
        ];
    }

    public static function publicFolders(): array
    {
        return [
            ['logos/host.png'],
            ['site-settings/favicon.png'],
            ['partners/4/settings/logo-wide.png'],
            ['partners/4/site/hero.png'],
            ['partners/4/avatar.png'],
            ['employees/3/me.png'],
            ['users/2/me.png'],
            ['credit-accounts/capital-one.png'],
            ['vendors/acme.png'],
            ['blogs/cover.png'],
            ['resource-providers/godaddy-logo_3f9a2c1e.png'],
        ];
    }

    #[Test]
    #[DataProvider('privateFolders')]
    public function a_private_folders_file_is_kept_privately_and_handed_out_by_a_signed_url(string $path): void
    {
        Storage::disk('uploads')->put($path, 'secret');

        $this->assertFalse(Storage::disk('public')->exists($path), "{$path} is on the public disk");
        $url = FileUrl::for($path);
        $this->assertStringContainsString("/private-files/{$path}", $url);
        $this->assertStringContainsString('signature=', $url);
        $this->get('/storage/' . $path)->assertNotFound();
        $this->get($url)->assertOk();
    }

    #[Test]
    #[DataProvider('publicFolders')]
    public function a_public_folders_file_keeps_its_storage_url(string $path): void
    {
        Storage::disk('uploads')->put($path, 'png');

        $this->assertTrue(Storage::disk('public')->exists($path));
        $this->assertSame(encodeUrl(asset('storage/' . $path)), FileUrl::for($path));
    }

    /**
     * A folder named in code is the same folder config/files.php lists as
     * private: one renamed in .env alone would be sent to the public disk.
     */
    #[Test]
    public function the_folders_named_in_code_are_the_ones_listed_as_private(): void
    {
        $private = array_keys(config('files.private_folders'));

        $this->assertContains(FundingApplicationAuditTrail::FOLDER, $private);
        $this->assertContains(AgreementImageStore::FOLDER, $private);
        $this->assertContains(BorrowerDocument::CREDIT_REPORT_FOLDER, $private);
        $this->assertContains(PartnerDocument::FOLDER, $private);
        $this->assertNotContains(ResourceProvider::LOGO_FOLDER, $private);
        $this->assertNull(config('audit.disk'));
        $this->assertNull(config('audit.folder'));
    }

    #[Test]
    public function a_clients_document_and_an_applications_document_give_signed_urls(): void
    {
        $borrower = $this->makeBorrower($this->makeHostPartner());
        $document = BorrowerDocument::create(['borrower_id' => $borrower->id, 'file_name' => 'Bank.pdf', 'file_path' => 'bank.pdf']);
        $appDocument = new FundingApplicationDocument(['path' => "funding-application/{$borrower->id}/FA-1/bank.pdf", 'disk' => 'uploads']);

        $this->assertStringContainsString("/private-files/borrowers/{$borrower->id}/bank.pdf", $document->file_path);
        $this->assertStringContainsString('/private-files/funding-application/', $appDocument->url);
    }
}
