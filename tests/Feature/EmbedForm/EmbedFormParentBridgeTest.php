<?php

namespace Tests\Feature\EmbedForm;

use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The website's /apply page sizes its iframe to the form and keeps the active
 * step in view. The form tells the page around it its height and where to
 * scroll, through postMessage, only to that page's own origin, and only
 * layout numbers - never anything the applicant typed.
 */
class EmbedFormParentBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
        ]);

        return $this->get('/form/' . $config->public_key)->assertOk()->getContent();
    }

    #[Test]
    public function the_form_reports_its_height_and_scroll_position_to_the_framing_page(): void
    {
        $page = $this->page();

        $this->assertStringContainsString("source: 'fch-embed-form'", $page);
        $this->assertStringContainsString("type: 'resize'", $page);
        $this->assertStringContainsString("type: 'scroll'", $page);
        $this->assertStringContainsString('new ResizeObserver(', $page);
    }

    #[Test]
    public function the_reported_height_can_shrink_as_well_as_grow(): void
    {
        $page = $this->page();

        // body has min-height: 100vh - inside an iframe that is the iframe's own
        // height, so watching body never sees the form get shorter (the success
        // screen, a step going back). The content wrapper is what is watched.
        $this->assertStringContainsString(".observe(document.querySelector('body > .container'))", $page);
        $this->assertStringNotContainsString('.observe(document.body)', $page);
    }

    #[Test]
    public function messages_go_only_to_the_framing_pages_origin_and_only_when_framed(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('window.parent === window', $page);
        $this->assertStringContainsString('new URL(document.referrer).origin', $page);
        $this->assertStringNotContainsString("postMessage(message, '*')", $page);
        $this->assertDoesNotMatchRegularExpression("/postMessage\\([^)]*['\"]\\*['\"]\\)/", $page);
    }

    #[Test]
    public function changing_step_and_finishing_ask_the_page_to_scroll_rather_than_the_frame(): void
    {
        $page = $this->page();

        // showStep() and the success screen both scroll to the top of the form.
        $this->assertSame(2, substr_count($page, 'scrollFormTo(0);'));
        // The first invalid field is brought into view on the page as well.
        $this->assertStringContainsString('scrollFormToElement(', $page);
    }

    #[Test]
    public function opening_the_form_does_not_pull_the_framing_page_down_to_it(): void
    {
        $page = $this->page();

        // The first step is shown on load without a scroll request, so a
        // visitor reading the top of /apply stays there.
        $this->assertStringContainsString('showStep(1, false);', $page);
        $this->assertStringNotContainsString('showStep(1);', $page);
        $this->assertStringContainsString('function showStep(step, scroll = true)', $page);
    }
}
