<?php

namespace Tests\Feature\CreditBuilder;

use App\Models\CreditBuilder\CreditBuilderVendor;
use App\Models\CreditBuilder\Tier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A vendor's logo (shown on the client's Credit Guide) is picked in the File
 * Manager, which already holds the file: the vendor form sends its storage
 * path, as the credit account image does. The form used to upload a file
 * instead, which an edit could not send: it went as a POST the API has no
 * route for, so a changed logo was never saved.
 */
class VendorLogoFromFileManagerTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Tier $tier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));
        $this->tier = Tier::create(['name' => 'Tier 1', 'title' => 'Vendor Credit', 'code' => 'T1', 'has_vendor' => true]);
    }

    private function vendor(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Uline',
            'tier_id' => $this->tier->id,
            'status' => CreditBuilderVendor::STATUS_ACTIVE,
            'general_url' => 'https://www.uline.com',
        ], $overrides);
    }

    #[Test]
    public function a_new_vendor_keeps_the_logo_path_from_the_file_manager(): void
    {
        $id = $this->postJson('/api/v1/admin/credit-builder/vendors', $this->vendor(['logo' => 'vendors/uline.png']))
            ->assertSuccessful()
            ->json('data.id');

        $this->assertSame('vendors/uline.png', CreditBuilderVendor::find($id)->getRawOriginal('logo'));
        $this->getJson("/api/v1/admin/credit-builder/vendors/{$id}")
            ->assertJsonPath('data.logo', asset('storage/vendors/uline.png'));
    }

    #[Test]
    public function an_edit_changes_the_logo_and_one_without_it_keeps_it(): void
    {
        $vendor = CreditBuilderVendor::create($this->vendor(['logo' => 'vendors/old.png']));

        $this->putJson("/api/v1/admin/credit-builder/vendors/{$vendor->id}", $this->vendor(['logo' => 'vendors/new.png']))->assertOk();
        $this->assertSame('vendors/new.png', $vendor->fresh()->getRawOriginal('logo'));

        $this->putJson("/api/v1/admin/credit-builder/vendors/{$vendor->id}", $this->vendor(['name' => 'Uline Inc']))->assertOk();
        $this->assertSame('vendors/new.png', $vendor->fresh()->getRawOriginal('logo'));
    }

    #[Test]
    public function the_logo_can_be_removed(): void
    {
        $vendor = CreditBuilderVendor::create($this->vendor(['logo' => 'vendors/old.png']));

        $this->putJson("/api/v1/admin/credit-builder/vendors/{$vendor->id}", $this->vendor(['logo' => null]))->assertOk();

        $this->assertNull($vendor->fresh()->getRawOriginal('logo'));
    }

    #[Test]
    public function a_logo_that_is_not_an_image_path_is_refused(): void
    {
        foreach (['vendors/notes.pdf', '../.env', 'https://evil.test/x.png'] as $logo) {
            $this->postJson('/api/v1/admin/credit-builder/vendors', $this->vendor(['logo' => $logo]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('logo');
        }
    }
}
