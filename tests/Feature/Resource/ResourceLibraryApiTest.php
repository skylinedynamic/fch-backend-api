<?php

namespace Tests\Feature\Resource;

use App\Http\Controllers\Api\V1\Borrower\Resource\ResourceController as BorrowerResourceController;
use App\Http\Controllers\Api\V1\FCH\Common\Resource\ResourceController;
use App\Models\Partner\Partner;
use App\Models\Resource\Resource;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The resource library each portal reads: partners see the published
 * resources marked for partners, borrowers the published ones marked for
 * borrowers, and the host team everything. Downloading a resource is held to
 * the same rule as opening it.
 */
#[CoversClass(ResourceController::class)]
#[CoversClass(BorrowerResourceController::class)]
class ResourceLibraryApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
    }

    private function resource(array $attributes): Resource
    {
        $resource = Resource::create(array_merge([
            'resource_type' => Resource::TYPE_DOWNLOAD_ASSETS,
            'title' => 'Guide',
            'status' => true,
            'is_partner' => false,
            'is_borrower' => false,
            'file' => 'resources/guide-' . uniqid() . '.pdf',
        ], $attributes));
        Storage::disk('public')->put($resource->file, '%PDF-1.4 guide');

        return $resource;
    }

    /** @return array<int, int> */
    private function listedIds(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->flatten(1)->pluck('id')->all();
    }

    #[Test]
    public function each_portal_lists_the_published_resources_meant_for_it(): void
    {
        $forPartners = $this->resource(['is_partner' => true]);
        $forBorrowers = $this->resource(['is_borrower' => true]);
        $draft = $this->resource(['is_partner' => true, 'is_borrower' => true, 'status' => false]);

        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->assertSame([$forPartners->id], $this->listedIds('/api/v1/common/resources'));

        $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $this->assertSame([$forBorrowers->id], $this->listedIds('/api/v1/borrower/resources'));

        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $this->assertEqualsCanonicalizing([$forPartners->id, $forBorrowers->id, $draft->id], $this->listedIds('/api/v1/common/resources'));
    }

    #[Test]
    public function a_resource_meant_for_you_opens_and_downloads(): void
    {
        $forPartners = $this->resource(['is_partner' => true]);
        $forBorrowers = $this->resource(['is_borrower' => true]);

        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->getJson("/api/v1/common/resources/{$forPartners->id}")->assertOk()->assertJsonPath('data.id', $forPartners->id);
        $this->get("/api/v1/common/resources/download/{$forPartners->id}")->assertOk()->assertDownload('Guide.pdf');

        $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $this->getJson("/api/v1/borrower/resources/{$forBorrowers->id}")->assertOk();
        $this->get("/api/v1/borrower/resources/{$forBorrowers->id}/download")->assertOk()->assertDownload('Guide.pdf');
    }

    #[Test]
    public function a_resource_not_meant_for_you_does_not_open(): void
    {
        $forBorrowers = $this->resource(['is_borrower' => true]);
        $forPartners = $this->resource(['is_partner' => true]);

        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->getJson("/api/v1/common/resources/{$forBorrowers->id}")->assertNotFound();

        $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $this->getJson("/api/v1/borrower/resources/{$forPartners->id}")->assertNotFound();
    }

    /**
     * Defect: download looked the resource up by id alone, so an unpublished
     * resource, or one meant only for the other portal, downloaded by id
     * although it neither listed nor opened.
     */
    #[Test]
    public function a_resource_not_meant_for_you_does_not_download(): void
    {
        $forBorrowers = $this->resource(['is_borrower' => true]);
        $forPartners = $this->resource(['is_partner' => true]);
        $unpublished = $this->resource(['is_partner' => true, 'is_borrower' => true, 'status' => false]);

        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->get("/api/v1/common/resources/download/{$forBorrowers->id}")->assertNotFound();
        $this->get("/api/v1/common/resources/download/{$unpublished->id}")->assertNotFound();

        $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $this->get("/api/v1/borrower/resources/{$forPartners->id}/download")->assertNotFound();
        $this->get("/api/v1/borrower/resources/{$unpublished->id}/download")->assertNotFound();

        // The host team's library lists drafts, so they download.
        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $this->get("/api/v1/common/resources/download/{$unpublished->id}")->assertOk();
    }

    #[Test]
    public function a_resource_without_a_file_says_so(): void
    {
        $linkOnly = Resource::create([
            'resource_type' => Resource::TYPE_EDUCATION_HELP,
            'title' => 'Link',
            'status' => true,
            'is_partner' => true,
            'link' => 'https://example.org',
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->get("/api/v1/common/resources/download/{$linkOnly->id}")->assertStatus(400)->assertJsonPath('status', false);
    }
}
