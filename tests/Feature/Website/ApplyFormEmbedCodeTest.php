<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Api\V1\FCH\Admin\CMS\SiteSettingsController;
use App\Models\Common\AppConfiguration;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Rules\EmbedFormSnippet;
use App\Services\Global\Website\CMS\WebsiteSiteSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The website's /apply page shows the host's form the way a partner would:
 * the admin copies the embed code from Embed Form Settings and pastes it into
 * CMS > Site Settings, and the public site settings serve it for /apply to
 * render. Only the builder's own code for the host's current form is accepted
 * on save, and only such a code is ever served.
 */
#[CoversClass(SiteSettingsController::class)]
#[CoversClass(WebsiteSiteSettingsService::class)]
#[CoversClass(EmbedFormSnippet::class)]
class ApplyFormEmbedCodeTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private const SETTINGS = '/api/v1/admin/cms/site-settings';

    private const FIELD = 'apply_form_embed_code';

    private const SITE = 'https://fourcornerfunding.com';

    private Partner $host;

    private User $editor;

    private EmbedFormConfig $form;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.base_website' => 'fourcornerfunding.com']);
        $this->host = $this->makeHostPartner();
        $this->editor = $this->makeStaffUser($this->host);
        $this->form = EmbedFormConfig::create([
            'partner_id' => $this->host->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Business Loan Application',
            'is_active' => true,
        ]);
    }

    private function formUrl(?string $key = null): string
    {
        return self::SITE.'/form/'.($key ?? $this->form->public_key);
    }

    private function save(?string $code)
    {
        $this->actingAsUser($this->editor);

        return $this->postJson(self::SETTINGS, [
            'site_name' => 'Four Corner',
            'address' => '1 Main St',
            'phone_number' => '+1 (555) 210-0100',
            'email' => 'hello@example.org',
            'map_iframe' => '<iframe src="https://maps.example.org"></iframe>',
            self::FIELD => $code,
        ]);
    }

    #[Test]
    public function the_public_site_settings_carry_the_pasted_code_as_saved(): void
    {
        $code = '<iframe src="'.$this->formUrl().'?header=0&amp;bg=white" data-fch-embed height="800" style="border-radius:12px"></iframe>'
            ."\n".'<script src="'.self::SITE.'/form/assets/embed.js" async></script>';
        $this->save($code)->assertOk();

        // The website's /apply renders this code itself.
        $data = $this->getJson('/api/v1/website/settings')->assertOk()->json('data');

        $this->assertSame($code, $data[self::FIELD]);
        // The admin sees it as saved too.
        $this->getJson(self::SETTINGS)->assertOk()->assertJsonPath('data.'.self::FIELD, $code);
    }

    #[Test]
    public function with_no_code_saved_the_settings_send_null(): void
    {
        $data = $this->getJson('/api/v1/website/settings')->assertOk()->json('data');

        $this->assertArrayHasKey(self::FIELD, $data);
        $this->assertNull($data[self::FIELD]);
    }

    #[Test]
    public function a_stored_code_that_fails_the_check_is_never_served(): void
    {
        // Written straight to the table, past the save rule.
        AppConfiguration::set(
            self::FIELD,
            '<iframe src="'.$this->formUrl().'" onload="alert(1)"></iframe>',
            AppConfiguration::MODULE_WEBSITE,
            $this->host->id
        );

        $this->getJson('/api/v1/website/settings')
            ->assertOk()
            ->assertJsonPath('data.'.self::FIELD, null)
            ->assertDontSee('alert(1)', false);
    }

    #[Test]
    public function a_code_left_with_an_old_key_is_not_served(): void
    {
        $this->save('<iframe src="'.$this->formUrl().'" height="900"></iframe>')->assertOk();

        $this->form->update(['public_key' => EmbedFormConfig::generatePublicKey()]);

        // Its form no longer exists; /apply shows its notice until the new code is pasted.
        $this->getJson('/api/v1/website/settings')->assertOk()->assertJsonPath('data.'.self::FIELD, null);
    }

    #[Test]
    public function only_the_hosts_own_embed_code_is_accepted(): void
    {
        $refused = [
            'another site' => '<iframe src="https://evil.example/form/'.$this->form->public_key.'"></iframe>',
            'another form' => '<iframe src="'.$this->formUrl('share_pub_someone_else').'"></iframe>',
            'a foreign script' => '<iframe src="'.$this->formUrl().'" data-fch-embed></iframe><script src="https://evil.example/x.js"></script>',
            'an event handler' => '<iframe src="'.$this->formUrl().'" onload="alert(1)"></iframe>',
            'not embed code' => '<p>hello</p>',
        ];

        foreach ($refused as $code) {
            $this->save($code)->assertUnprocessable()->assertJsonValidationErrors([self::FIELD]);
        }

        $this->assertNull(AppConfiguration::get(self::FIELD, null, AppConfiguration::MODULE_WEBSITE, $this->host->id));
    }

    #[Test]
    public function the_code_can_be_cleared(): void
    {
        $this->save('<iframe src="'.$this->formUrl().'" height="900"></iframe>')->assertOk();
        $this->save(null)->assertOk();

        $this->assertEmpty(AppConfiguration::get(self::FIELD, null, AppConfiguration::MODULE_WEBSITE, $this->host->id));
        $this->getJson('/api/v1/website/settings')->assertJsonPath('data.'.self::FIELD, null);
    }
}
