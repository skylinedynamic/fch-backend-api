<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Controllers\Web\EmbedFormPageController;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R3-BUG-014: switching an embed form off ("Disabled — Public form will show an
 * inactive message") made its public link answer a bare "404 NOT FOUND", the
 * same as a link that never existed - a visitor, or the partner's site around
 * the iframe, could not tell the form was paused. An inactive form now shows a
 * short page in the partner's name saying it is not taking applications, with
 * nothing to fill in; a key no form has is still a 404.
 */
#[CoversClass(EmbedFormPageController::class)]
class EmbedFormInactivePageTest extends TestCase
{
    use RefreshDatabase;

    private function form(bool $active, array $config = []): EmbedFormConfig
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Four Corner Funding',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        return EmbedFormConfig::create($config + [
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Business Loan Application',
            'is_active' => $active,
        ]);
    }

    #[Test]
    public function an_inactive_form_says_it_is_not_taking_applications_in_the_partners_name(): void
    {
        $form = $this->form(false);

        $this->get('/form/' . $form->public_key)
            ->assertOk()
            ->assertSee(__('embed_form.inactive_title'))
            ->assertSee('Four Corner Funding')
            ->assertDontSee('404')
            // Nothing to fill in or send.
            ->assertDontSee('<form', false)
            ->assertDontSee('type="submit"', false);
    }

    /** The partner's site may still frame it, so the message shows inside the iframe too. */
    #[Test]
    public function an_inactive_form_keeps_its_framing_rule(): void
    {
        $form = $this->form(false, ['allowed_domains' => ['https://partner-site.example.com']]);

        $csp = (string) $this->get('/form/' . $form->public_key)->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString('https://partner-site.example.com', $csp);
    }

    #[Test]
    public function a_key_no_form_has_is_still_not_found(): void
    {
        $this->get('/form/share_pub_no_such_form')->assertNotFound();
    }

    #[Test]
    public function an_active_form_still_loads(): void
    {
        $form = $this->form(true);

        $this->get('/form/' . $form->public_key)
            ->assertOk()
            ->assertSee('Business Loan Application')
            ->assertDontSee(__('embed_form.inactive_title'));
    }
}
