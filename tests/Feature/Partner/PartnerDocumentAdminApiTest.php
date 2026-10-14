<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerDocumentController;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerDocument;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Documents the host team files on a partner's profile
 * (/admin/partners/documents, fetch-all-documents, download, delete).
 */
#[CoversClass(PartnerDocumentController::class)]
class PartnerDocumentAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $admin;
    private Partner $partner;
    private string $publicRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Deleting a document deletes its file from the public disk.
        Storage::fake('public');
        $this->admin = $this->makeStaffUser($this->makeHostPartner());
        $this->partner = $this->makeWhiteLabelPartner();

        // download() serves from public/storage/partners/{id}/... - and the
        // real public/storage is a link into the app's storage, so the public
        // path is pointed at a throwaway folder for the test.
        $this->publicRoot = sys_get_temp_dir() . '/fch-public-' . uniqid();
        $this->app->usePublicPath($this->publicRoot);
        $dir = public_path("storage/partners/{$this->partner->id}");
        File::ensureDirectoryExists($dir);
        File::put($dir . '/w9.pdf', '%PDF-1.4 w9');
        // Something worth stealing just outside public/, where a climbing path lands.
        File::put($this->publicRoot . '/../fch-secret-' . basename($this->publicRoot), 'APP_KEY=secret');
    }

    protected function tearDown(): void
    {
        File::delete($this->publicRoot . '/../fch-secret-' . basename($this->publicRoot));
        File::deleteDirectory($this->publicRoot);

        parent::tearDown();
    }

    #[Test]
    public function documents_are_filed_listed_downloaded_and_deleted(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/partners/documents', ['partner_id' => $this->partner->id, 'file_path' => ['w9.pdf']])->assertCreated();
        $document = PartnerDocument::where('partner_id', $this->partner->id)->firstOrFail();

        $this->postJson('/api/v1/admin/partners/fetch-all-documents', ['partner_id' => $this->partner->id])
            ->assertOk()
            ->assertJsonPath('data.0.id', $document->id);
        $this->get("/api/v1/admin/partners/documents/download/{$document->id}")->assertOk()->assertDownload('w9.pdf');

        $this->postJson("/api/v1/admin/partners/documents/delete/{$document->id}")->assertOk();
        $this->assertNull(PartnerDocument::find($document->id));
    }

    #[Test]
    public function documents_are_host_team_only(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/admin/partners/fetch-all-documents', ['partner_id' => $this->partner->id])->assertForbidden();
    }

    #[Test]
    public function a_path_climbing_out_of_the_partners_folder_is_not_filed(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/partners/documents', ['partner_id' => $this->partner->id, 'file_path' => '../../../../../.env'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_path');

        $this->assertSame(0, PartnerDocument::count());
    }

    /**
     * Defect: a "../" that stays inside the public disk passed Flysystem's
     * normaliser, so "../2/w9.pdf" was stored as-is and filed another
     * partner's file on this partner's profile.
     */
    #[Test]
    public function a_path_stepping_into_another_partners_folder_is_refused(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/partners/documents', ['partner_id' => $this->partner->id, 'file_path' => '../2/w9.pdf'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_path');
        $this->postJson('/api/v1/admin/partners/documents', ['partner_id' => $this->partner->id, 'file_path' => ['w9.pdf', '..\\2\\w9.pdf']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_path.1');

        $this->assertSame(0, PartnerDocument::count());
    }

    #[Test]
    public function a_file_name_with_dots_in_it_is_still_filed(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/partners/documents', ['partner_id' => $this->partner->id, 'file_path' => ['w9..final.pdf']])->assertCreated();

        $this->assertSame('w9..final.pdf', PartnerDocument::firstOrFail()->getRawOriginal('file_path'));
    }

    /**
     * Defect: download built the server path from the stored file_path with
     * no check that it stays inside the public storage folder, so a row whose
     * path climbs out (written before the upload path refused it, or by any
     * other writer) served whatever file it named - the app's .env included.
     */
    #[Test]
    public function a_stored_path_outside_public_storage_is_never_served(): void
    {
        $id = DB::table('partner_documents')->insertGetId([
            'partner_id' => $this->partner->id,
            'document_name' => 'env.txt',
            // public/storage/partners/{id}/ + four levels up = just outside public/
            'file_path' => '../../../../fch-secret-' . basename($this->publicRoot),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->admin);

        $this->get("/api/v1/admin/partners/documents/download/{$id}")->assertNotFound();
    }
}
