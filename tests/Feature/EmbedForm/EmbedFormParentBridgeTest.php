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
    public function the_framing_page_can_introduce_itself_when_there_is_no_referrer(): void
    {
        // A browser, extension or Referrer-Policy that drops the referrer left
        // the form not knowing whom to tell its height, so the iframe stayed at
        // the code's 800px. The framing page now says hello; the form takes the
        // origin from that message - only from its own parent window - and
        // reports its height in reply.
        $page = $this->page();

        $this->assertStringContainsString("data.type !== 'hello'", $page);
        $this->assertStringContainsString('event.source !== window.parent', $page);
        $this->assertStringContainsString('parentOrigin = event.origin', $page);
        // Framed at all - not only with a referrer - the form measures itself.
        $this->assertStringContainsString('if (window.parent !== window) {', $page);
    }

    #[Test]
    public function a_page_with_no_origin_still_gets_the_height(): void
    {
        // A page opened from a file (or sandboxed) has the origin 'null', which
        // postMessage refuses as a target - the form threw and the iframe stayed
        // at 800px. Such a page is answered with '*': only layout numbers go,
        // and only to this window's own parent.
        $page = $this->page();

        $this->assertStringContainsString("event.origin === 'null' ? '*' : event.origin", $page);
    }

    #[Test]
    public function the_embed_script_keeps_the_height_attribute_in_step(): void
    {
        // Only the style changed, so the element still read height="800" in
        // the browser's inspector and looked as if automatic height did nothing.
        $script = file_get_contents(public_path('form/assets/embed.js'));

        $this->assertStringContainsString("frame.style.height = height + 'px';", $script);
        $this->assertStringContainsString("frame.setAttribute('height', String(height));", $script);
    }

    #[Test]
    public function the_embed_script_says_hello_to_each_form(): void
    {
        $script = file_get_contents(public_path('form/assets/embed.js'));

        $this->assertStringContainsString("type: 'hello'", $script);
        $this->assertStringContainsString("addEventListener('load'", $script);
        // Only to the form's own origin.
        $this->assertStringContainsString('postMessage({ source: SOURCE, type: \'hello\' }, formOrigin)', $script);
    }

    #[Test]
    public function the_form_also_scrolls_itself_for_a_fixed_height_iframe(): void
    {
        // A partner's fixed-height iframe has no script listening, so asking the
        // page alone left an error out of view inside the frame.
        preg_match('/function scrollFormTo\(top\) \{(.*?)\n        \}/s', $this->page(), $m);

        $this->assertNotEmpty($m, 'scrollFormTo() not found');
        $this->assertStringContainsString("postToParent({ type: 'scroll'", $m[1]);
        $this->assertStringContainsString('window.scrollTo(', $m[1]);
        $this->assertStringNotContainsString('if (!postToParent', $m[1]);
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
