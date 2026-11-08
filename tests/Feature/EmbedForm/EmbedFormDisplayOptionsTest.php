<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Controllers\Web\EmbedFormPageController;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use App\Support\EmbedForm\EmbedFormDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A partner fits the iframe to their own site with the options in the embed
 * code's link (header, footer, bg, frame). Only listed values are read - any
 * other value falls back to the default and never reaches the page.
 */
#[CoversClass(EmbedFormPageController::class)]
#[CoversClass(EmbedFormDisplay::class)]
class EmbedFormDisplayOptionsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'id="embedBrandHeader"';

    private const FOOTER = 'All rights reserved';

    private function page(array $query = []): TestResponse
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co '.uniqid(),
            'full_name' => 'Host Co',
            'email' => 'host-'.uniqid().'@example.test',
        ]);

        $config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
            'allowed_domains' => null,
        ]);

        $url = '/form/'.$config->public_key.($query ? '?'.http_build_query($query) : '');

        return $this->get($url)->assertOk();
    }

    /** The class list on <body>. */
    private function bodyClasses(TestResponse $response): array
    {
        preg_match('/<body[^>]*class="([^"]*)"/', $response->getContent(), $m);

        return preg_split('/\s+/', trim($m[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    }

    #[Test]
    public function with_no_options_the_page_is_unchanged(): void
    {
        $response = $this->page();

        $response->assertSee(self::HEADER, false);
        $response->assertSee(self::FOOTER);
        $this->assertSame(['form-bg-grey'], $this->bodyClasses($response));
    }

    #[Test]
    public function header_0_hides_only_the_brand_header(): void
    {
        $response = $this->page([EmbedFormDisplay::PARAM_HEADER => '0']);

        $response->assertDontSee(self::HEADER, false);
        $response->assertSee(self::FOOTER);
    }

    #[Test]
    public function footer_0_hides_only_the_copyright(): void
    {
        $response = $this->page([EmbedFormDisplay::PARAM_FOOTER => '0']);

        $response->assertSee(self::HEADER, false);
        $response->assertDontSee(self::FOOTER);
    }

    #[Test]
    public function each_background_is_applied(): void
    {
        foreach (EmbedFormDisplay::BACKGROUNDS as $background) {
            $response = $this->page([EmbedFormDisplay::PARAM_BACKGROUND => $background]);

            $this->assertContains('form-bg-'.$background, $this->bodyClasses($response));
        }
    }

    #[Test]
    public function a_colour_code_sets_the_background(): void
    {
        $response = $this->page([EmbedFormDisplay::PARAM_BACKGROUND => '1A2B3C']);

        $this->assertSame(['form-bg-custom'], $this->bodyClasses($response));
        $response->assertSee('style="--form-bg: #1a2b3c"', false);
    }

    #[Test]
    public function only_a_six_digit_colour_code_is_read(): void
    {
        foreach (['#1a2b3c', 'abc', '1a2b3cff', 'zzzzzz', 'red', '1a2b3c;color:red'] as $value) {
            $response = $this->page([EmbedFormDisplay::PARAM_BACKGROUND => $value]);

            $this->assertSame(['form-bg-grey'], $this->bodyClasses($response), $value);
            $response->assertDontSee('style="--form-bg', false);
        }
    }

    #[Test]
    public function a_primary_colour_replaces_the_brand_colour(): void
    {
        $response = $this->page([EmbedFormDisplay::PARAM_PRIMARY => '2D9A96']);

        $css = $this->rootCss($response);
        $this->assertMatchesRegularExpression('/--brand-primary:\s*#2d9a96\s*;/', $css);
        // Bootstrap's own primary follows it: 2d 9a 96 = 45, 154, 150.
        $this->assertMatchesRegularExpression('/--bs-primary-rgb:\s*45\s*,\s*154\s*,\s*150\s*;/', $css);
    }

    #[Test]
    public function an_unreadable_primary_colour_keeps_the_brand_colour(): void
    {
        $brand = $this->rootCss($this->page());

        foreach (['red', '#2d9a96', '2d9a9', 'red;}body{x'] as $value) {
            $response = $this->page([EmbedFormDisplay::PARAM_PRIMARY => $value]);

            $this->assertSame($brand, $this->rootCss($response), $value);
            $response->assertDontSee('red;}', false);
        }
    }

    /** The :root custom properties - where the brand colour is set. */
    private function rootCss(TestResponse $response): string
    {
        preg_match('/:root\s*\{(.*?)\}/s', $response->getContent(), $m);

        return preg_replace('/\s+/', ' ', $m[1] ?? '');
    }

    #[Test]
    public function the_step_line_runs_from_the_first_step_to_the_last(): void
    {
        // The line was drawn from 10% to 90% of the width while the steps sit at
        // the edges, so a wide form showed a gap after step 1 and before step 8.
        // Every step now has one width and the line joins their centres.
        $css = preg_replace('/\s+/', ' ', $this->page()->getContent());

        $this->assertStringContainsString('.stepper-item { position: relative; z-index: 3; display: flex; flex: 0 0 var(--stepper-node);', $css);
        $this->assertStringContainsString('left: calc(var(--stepper-pad) + var(--stepper-node) / 2);', $css);
        $this->assertStringContainsString('right: calc(var(--stepper-pad) + var(--stepper-node) / 2);', $css);
        $this->assertStringNotContainsString('left: 10%;', $css);
    }

    #[Test]
    public function frame_0_removes_the_card_frame(): void
    {
        $this->assertContains('is-frameless', $this->bodyClasses($this->page([EmbedFormDisplay::PARAM_FRAME => '0'])));
        $this->assertNotContains('is-frameless', $this->bodyClasses($this->page([EmbedFormDisplay::PARAM_FRAME => '1'])));
    }

    #[Test]
    public function embed_1_is_the_flush_preset(): void
    {
        $response = $this->page([EmbedFormDisplay::PARAM_EMBED => '1']);

        $response->assertDontSee(self::HEADER, false);
        $response->assertDontSee(self::FOOTER);
        $this->assertEqualsCanonicalizing(['form-bg-transparent', 'is-frameless'], $this->bodyClasses($response));
    }

    #[Test]
    public function an_explicit_option_overrides_the_preset(): void
    {
        $response = $this->page([
            EmbedFormDisplay::PARAM_EMBED => '1',
            EmbedFormDisplay::PARAM_HEADER => '1',
            EmbedFormDisplay::PARAM_BACKGROUND => 'white',
        ]);

        $response->assertSee(self::HEADER, false);
        $response->assertDontSee(self::FOOTER);
        $this->assertEqualsCanonicalizing(['form-bg-white', 'is-frameless'], $this->bodyClasses($response));
    }

    #[Test]
    public function an_unlisted_value_falls_back_to_the_default_and_is_never_printed(): void
    {
        $response = $this->page([
            EmbedFormDisplay::PARAM_BACKGROUND => 'red;}</style><script>x</script>',
            EmbedFormDisplay::PARAM_HEADER => 'yes',
            EmbedFormDisplay::PARAM_FRAME => 'nope',
        ]);

        $response->assertSee(self::HEADER, false);
        $this->assertSame(['form-bg-grey'], $this->bodyClasses($response));
        $response->assertDontSee('red;}', false);
        $response->assertDontSee('<script>x</script>', false);
    }

    #[Test]
    public function the_script_path_is_not_taken_for_a_form_key(): void
    {
        // /form/{key} is one segment, so the embed script beside it is never
        // looked up as a form.
        $this->get('/form/assets')->assertNotFound();
        $this->assertFileExists(public_path('form/assets/embed.js'));
    }
}
