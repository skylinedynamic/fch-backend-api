<?php

namespace Tests\Feature\CreditBuilder;

use App\Models\CreditBuilder\CreditBuilderVendor;
use App\Models\CreditBuilder\Tier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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

        Storage::fake('public');
        Storage::fake('local');
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

    /** A picture in the File Manager (the public vendors folder). */
    private function inFileManager(string $path): string
    {
        Storage::disk('uploads')->put($path, 'image');

        return $path;
    }

    #[Test]
    public function a_new_vendor_keeps_the_logo_path_from_the_file_manager(): void
    {
        $this->inFileManager('vendors/uline.png');
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
        $this->inFileManager('vendors/new.png');

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
        foreach (['vendors/notes.pdf', '../.env', 'https://evil.test/x.png', 'vendors/../../.env.png', '/etc/logo.png', 'vendors\logo.png'] as $logo) {
            $this->postJson('/api/v1/admin/credit-builder/vendors', $this->vendor(['logo' => $logo]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('logo');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function fileManagerNames(): iterable
    {
        yield 'a space and parentheses' => ['vendors/QA-TEST logo (1).png'];
        yield 'an extra dot' => ['vendors/QA-TEST-logo.v2.png'];
        yield 'an upper-case extension' => ['vendors/Brand Mark.JPEG'];
        yield 'a seeded folder' => ['logos/tier-1/grainger_logo.png'];
    }

    /**
     * R3-BUG-012: the logo had to be letters, digits, _ - / and spaces with one
     * dot, so a File Manager picture named "logo (1).png" or "logo.v2.png"
     * could never be chosen. Any picture the File Manager holds is taken.
     */
    #[Test]
    #[DataProvider('fileManagerNames')]
    public function any_picture_in_the_file_manager_is_taken_whatever_its_name(string $logo): void
    {
        $this->inFileManager($logo);

        $id = $this->postJson('/api/v1/admin/credit-builder/vendors', $this->vendor(['logo' => $logo]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($logo, CreditBuilderVendor::find($id)->getRawOriginal('logo'));
    }

    /** R3-BUG-012: a path no file is at was taken; the logo has to be in the File Manager. */
    #[Test]
    public function a_logo_that_is_not_in_the_file_manager_is_refused(): void
    {
        $this->postJson('/api/v1/admin/credit-builder/vendors', $this->vendor(['logo' => 'vendors/QA-TEST-plain.png']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo' => __('credit_builder.validation_vendor_logo_missing')]);
    }

    /** A vendor whose logo file is gone (seeded, or removed) still saves when the logo is not changed. */
    #[Test]
    public function an_edit_that_keeps_a_logo_whose_file_is_gone_still_saves(): void
    {
        $vendor = CreditBuilderVendor::create($this->vendor(['logo' => 'logos/tier-1/uline.png']));

        $this->putJson("/api/v1/admin/credit-builder/vendors/{$vendor->id}", $this->vendor(['name' => 'Uline Inc', 'logo' => 'logos/tier-1/uline.png']))
            ->assertOk();

        $this->assertSame('Uline Inc', $vendor->fresh()->name);
    }

    /** R3-BUG-012: the API answered the raw keys credit_builder.vendor_created / vendor_deleted. */
    #[Test]
    public function creating_and_deleting_a_vendor_say_so_in_words(): void
    {
        $response = $this->postJson('/api/v1/admin/credit-builder/vendors', $this->vendor())->assertCreated();
        $this->assertNotSame('credit_builder.vendor_created', $response->json('message'));
        $this->assertSame(__('credit_builder.vendor_created'), $response->json('message'));

        $deleted = $this->deleteJson('/api/v1/admin/credit-builder/vendors/' . $response->json('data.id'))->assertOk();
        $this->assertNotSame('credit_builder.vendor_deleted', $deleted->json('message'));
    }
}
