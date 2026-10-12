<?php

namespace Tests\Unit\Storage;

use App\Services\Global\SharePoint\SharePointService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Uploading to SharePoint through Microsoft Graph (dormant: only used with
 * FILESYSTEM_DISK=sharepoint, so it was never exercised).
 *
 * Defects (O54):
 *  - a small upload checked for 200, but Graph answers 201 Created for a new
 *    file, so every new upload threw "SharePoint upload failed";
 *  - the path was rawurlencode()d whole, so the folder slashes became %2F and
 *    the file landed as one oddly named item in the root.
 */
class SharePointServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft.tenant_id' => 'tenant',
            'services.microsoft.client_id' => 'client',
            'services.microsoft.client_secret' => 'secret',
            'services.microsoft.sharepoint_site_id' => 'site-1',
        ]);
        Cache::flush();
    }

    #[Test]
    public function a_new_small_file_answered_201_created_is_uploaded(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(['id' => 'item-1', 'name' => 'w9.pdf', 'size' => 10], 201),
        ]);

        $item = (new SharePointService())->uploadFile(UploadedFile::fake()->create('w9.pdf', 1), 'uploads/borrowers', 'w9 final.pdf');

        $this->assertSame('item-1', $item['id']);
    }

    #[Test]
    public function folder_slashes_stay_slashes_and_only_the_names_are_encoded(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(['id' => 'item-1'], 201),
        ]);

        (new SharePointService())->uploadFile(UploadedFile::fake()->create('w9.pdf', 1), 'uploads/borrowers', 'w9 final.pdf');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/drive/root:/uploads/borrowers/w9%20final.pdf:/content'));
    }

    #[Test]
    public function deleting_by_path_keeps_the_folders_too(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(null, 204),
        ]);

        $this->assertTrue((new SharePointService())->deleteItemByPath('/uploads/borrowers/w9 final.pdf'));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/drive/root:/uploads/borrowers/w9%20final.pdf:'));
    }
}
