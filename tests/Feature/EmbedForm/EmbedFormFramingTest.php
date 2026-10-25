<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Controllers\Web\EmbedFormPageController;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-092: Embedded Form > Allowed Domains did not stop the form showing in an
 * iframe on any other site - the page sent no framing rule, and the submit's
 * Origin check sees the form's own host, not the page around the iframe. The
 * page now tells the browser which sites may frame it (CSP frame-ancestors):
 * only the listed domains. An empty list stays unrestricted.
 */
#[CoversClass(EmbedFormPageController::class)]
class EmbedFormFramingTest extends TestCase
{
    use RefreshDatabase;

    private function page(?array $allowedDomains): TestResponse
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co ' . uniqid(),
            'full_name' => 'Host Co',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
            'allowed_domains' => $allowedDomains,
        ]);

        return $this->get('/form/' . $config->public_key)->assertOk();
    }

    private function frameAncestors(TestResponse $response): ?string
    {
        $csp = $response->headers->get('Content-Security-Policy');
        if (!$csp || !preg_match('/frame-ancestors ([^;]+)/', $csp, $m)) {
            return null;
        }

        return trim($m[1]);
    }

    #[Test]
    public function only_the_listed_domains_may_show_the_form_in_an_iframe(): void
    {
        $sources = $this->frameAncestors($this->page(['https://skylinedynamic.dev']));

        $this->assertSame('https://skylinedynamic.dev https://www.skylinedynamic.dev', $sources);
        // Not 'self', not the portal, not anyone else.
        $this->assertStringNotContainsString("'self'", $sources);
        $this->assertStringNotContainsString('admin.skylinedynamic.dev', $sources);
        $this->assertStringNotContainsString('example.com', $sources);
    }

    #[Test]
    public function every_listed_domain_is_allowed_however_it_was_typed(): void
    {
        $sources = $this->frameAncestors($this->page(['https://www.partner-one.com/apply', 'http://partner-two.com']));

        $this->assertSame(
            'https://partner-one.com https://www.partner-one.com http://partner-two.com http://www.partner-two.com',
            $sources
        );
    }

    #[Test]
    public function with_no_list_the_form_may_be_framed_anywhere(): void
    {
        $this->assertNull($this->frameAncestors($this->page([])));
        $this->assertNull($this->frameAncestors($this->page(null)));
    }
}
