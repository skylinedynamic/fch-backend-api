<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Api\V1\FCH\Admin\CMS\SeoController;
use App\Http\Controllers\Api\V1\FCH\Admin\CMS\SiteSettingsController;
use App\Models\CMS\SeoSetting;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host team's SEO entries and public-site settings (/admin/cms/seo-settings,
 * /admin/cms/site-settings) - what the public website serves.
 */
#[CoversClass(SeoController::class)]
#[CoversClass(SiteSettingsController::class)]
class CmsSeoAndSiteSettingsAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->editor = $this->makeStaffUser($this->host);
    }

    #[Test]
    public function an_seo_entry_is_created_listed_updated_and_deleted(): void
    {
        $this->actingAsUser($this->editor);

        $id = $this->postJson('/api/v1/admin/cms/seo-settings/store', ['page_name' => 'Home', 'path' => '/', 'meta_title' => 'Welcome'])
            ->assertCreated()
            ->json('data.id');
        $this->assertSame($this->host->id, (int) SeoSetting::find($id)->partner_id);

        $this->postJson('/api/v1/admin/cms/seo-settings')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/admin/cms/seo-settings')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/admin/cms/seo-settings/{$id}")->assertOk()->assertJsonPath('data.path', '/');

        // Keeping its own path is not a clash with itself.
        $this->postJson("/api/v1/admin/cms/seo-settings/{$id}", ['page_name' => 'Home', 'path' => '/', 'meta_title' => 'Hello'])->assertOk();
        $this->assertSame('Hello', SeoSetting::find($id)->meta_title);

        $this->postJson('/api/v1/admin/cms/seo-settings-multiple-delete', ['ids' => [$id]])->assertOk();
        $this->assertNull(SeoSetting::find($id));
        $this->getJson("/api/v1/admin/cms/seo-settings/{$id}")->assertNotFound();
    }

    #[Test]
    public function two_entries_cannot_share_a_path(): void
    {
        $this->actingAsUser($this->editor);
        $this->postJson('/api/v1/admin/cms/seo-settings/store', ['page_name' => 'Home', 'path' => '/'])->assertCreated();

        $this->postJson('/api/v1/admin/cms/seo-settings/store', ['page_name' => 'Home again', 'path' => '/'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('path');
    }

    #[Test]
    public function the_site_settings_are_saved_for_the_host_and_read_back(): void
    {
        $this->actingAsUser($this->editor);

        $this->postJson('/api/v1/admin/cms/site-settings', [
            'site_name' => 'Four Corner',
            'address' => '1 Main St',
            'phone_number' => '+1 (555) 210-0100',
            'email' => 'hello@example.org',
            'map_iframe' => '<iframe src="https://maps.example.org"></iframe>',
            'social_links' => [['platform' => 'X', 'url' => ' https://x.example.org/fc ']],
        ])->assertOk();

        $this->assertSame('Four Corner', AppConfiguration::get('site_name', null, AppConfiguration::MODULE_WEBSITE, $this->host->id));
        $this->getJson('/api/v1/admin/cms/site-settings')
            ->assertOk()
            ->assertJsonPath('data.site_name', 'Four Corner')
            ->assertJsonPath('data.social_links.0.url', 'https://x.example.org/fc');
    }

    private function validSiteSettings(): array
    {
        return [
            'site_name' => 'Four Corner',
            'address' => '1 Main St',
            'phone_number' => '+1 (555) 210-0100',
            'email' => 'hello@example.org',
            'map_iframe' => '<iframe src="https://maps.example.org"></iframe>',
        ];
    }

    /**
     * Defect (BUG-022, OP-CMS-03): the reCAPTCHA secret came back in full and
     * the form showed it as plain text. It is reported as set (masked) only.
     */
    #[Test]
    public function the_recaptcha_secret_is_never_sent_back(): void
    {
        AppConfiguration::set('recaptcha_site_key', 'site-key-public', AppConfiguration::MODULE_WEBSITE, $this->host->id);
        AppConfiguration::set('recaptcha_secret_key', 'secret-key-0123456789', AppConfiguration::MODULE_WEBSITE, $this->host->id);
        $this->actingAsUser($this->editor);

        $this->getJson('/api/v1/admin/cms/site-settings')
            ->assertOk()
            ->assertJsonPath('data.recaptcha_site_key', 'site-key-public')
            ->assertJsonPath('data.recaptcha_secret_key', AppConfiguration::SECRET_MASK)
            ->assertDontSee('secret-key-0123456789');

        $this->postJson('/api/v1/admin/cms/site-settings', $this->validSiteSettings())
            ->assertOk()
            ->assertDontSee('secret-key-0123456789');
    }

    #[Test]
    public function saving_without_retyping_the_secret_keeps_it_and_a_new_one_replaces_it(): void
    {
        AppConfiguration::set('recaptcha_secret_key', 'secret-key-0123456789', AppConfiguration::MODULE_WEBSITE, $this->host->id);
        $this->actingAsUser($this->editor);
        $stored = fn () => AppConfiguration::get('recaptcha_secret_key', null, AppConfiguration::MODULE_WEBSITE, $this->host->id);

        foreach (['', AppConfiguration::SECRET_MASK] as $sentBack) {
            $this->postJson('/api/v1/admin/cms/site-settings', [...$this->validSiteSettings(), 'recaptcha_secret_key' => $sentBack])->assertOk();
            $this->assertSame('secret-key-0123456789', $stored());
        }

        $this->postJson('/api/v1/admin/cms/site-settings', [...$this->validSiteSettings(), 'recaptcha_secret_key' => 'rotated-secret'])->assertOk();
        $this->assertSame('rotated-secret', $stored());
    }

    #[Test]
    public function the_site_settings_are_validated(): void
    {
        $this->actingAsUser($this->editor);

        $this->postJson('/api/v1/admin/cms/site-settings', [
            'site_name' => 'Four Corner',
            'address' => '<script>',
            'phone_number' => 'call me',
            'email' => 'nope',
            'map_iframe' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors(['address', 'phone_number', 'email']);
    }
}
