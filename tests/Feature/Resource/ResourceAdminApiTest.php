<?php

namespace Tests\Feature\Resource;

use App\Http\Controllers\Api\V1\FCH\Admin\Resource\ResourceController;
use App\Models\Partner\Partner;
use App\Models\Resource\Resource;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;
use App\Models\Resource\ResourceProvider;
use Illuminate\Support\Facades\Storage;

/**
 * The host team's resource library editor (/admin/resources/*): what the
 * partner and borrower libraries list (see ResourceLibraryApiTest).
 */
#[CoversClass(ResourceController::class)]
class ResourceAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeStaffUser($this->makeHostPartner());
    }

    private function resource(array $overrides = []): array
    {
        return array_merge([
            'resource_type' => Resource::TYPE_DOWNLOAD_ASSETS,
            'title' => 'Funding guide',
            'status' => true,
            'is_partner' => true,
            'file' => 'resources/guide.pdf',
            'providers' => [['name' => 'Acme', 'link' => 'https://acme.example.org', 'is_recommended' => true]],
        ], $overrides);
    }

    /**
     * BUG-016: resources/ is private, but a provider's logo is shown to
     * everyone. A logo picked from resources/ is kept as a public copy in
     * resource-providers/, so it keeps showing.
     */
    #[Test]
    public function a_providers_logo_picked_from_the_private_resources_folder_is_kept_publicly(): void
    {
        Storage::disk('uploads')->put('resources/godaddy.png', 'png');
        $this->actingAsUser($this->admin);

        $id = $this->postJson('/api/v1/admin/resources', $this->resource([
            'providers' => [['name' => 'GoDaddy', 'link' => 'https://godaddy.example.org', 'logo' => 'resources/godaddy.png']],
        ]))->assertCreated()->json('data.id');

        $logo = ResourceProvider::where('resource_id', $id)->value('logo');
        $this->assertMatchesRegularExpression('#^resource-providers/godaddy_[0-9a-f]{8}\.png$#', $logo);
        $this->assertSame('png', Storage::disk('public')->get($logo));
        $this->assertSame(encodeUrl(asset('storage/' . $logo)), ResourceProvider::where('resource_id', $id)->first()->full_logo_url);

        // Saving again keeps the same public copy.
        $this->postJson("/api/v1/admin/resources/{$id}", ['providers' => [['name' => 'GoDaddy', 'logo' => $logo]]])->assertOk();
        $this->assertSame($logo, ResourceProvider::where('resource_id', $id)->value('logo'));
    }

    #[Test]
    public function the_editor_is_host_team_only(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner()));

        $this->postJson('/api/v1/admin/resources', $this->resource())->assertForbidden();
        $this->getJson('/api/v1/admin/resources/type/' . Resource::TYPE_DOWNLOAD_ASSETS)->assertForbidden();
    }

    #[Test]
    public function a_resource_is_created_listed_updated_and_deleted(): void
    {
        $this->actingAsUser($this->admin);

        $this->getJson('/api/v1/admin/resources/next-id')->assertOk()->assertJsonPath('next_id', 1);
        $id = $this->postJson('/api/v1/admin/resources', $this->resource())->assertCreated()->json('data.id');
        $this->assertSame(1, Resource::find($id)->providers()->count());

        $this->getJson('/api/v1/admin/resources/type/' . Resource::TYPE_DOWNLOAD_ASSETS)->assertOk()->assertJsonPath('data.0.id', $id);

        $this->postJson("/api/v1/admin/resources/{$id}", ['title' => 'Funding guide 2026', 'providers' => []])->assertOk();
        $this->assertSame('Funding guide 2026', Resource::find($id)->title);
        $this->assertSame(0, Resource::find($id)->providers()->count());

        $this->deleteJson("/api/v1/admin/resources/{$id}")->assertOk();
        $this->assertSoftDeleted('resources', ['id' => $id]);
    }

    #[Test]
    public function only_a_pdf_and_a_known_type_are_accepted(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/resources', $this->resource(['file' => 'resources/script.php', 'resource_type' => 'secret']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file', 'resource_type']);
    }

    /**
     * Defect: the admin show reused the portals' lookup, which only finds
     * published resources - so the host team, whose own library lists their
     * drafts, could not open one.
     */
    #[Test]
    public function the_host_team_opens_an_unpublished_resource(): void
    {
        $this->actingAsUser($this->admin);
        $id = $this->postJson('/api/v1/admin/resources', $this->resource(['status' => false]))->json('data.id');

        $this->getJson("/api/v1/admin/resources/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/admin/resources/999999')->assertNotFound();
    }
}
