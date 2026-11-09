<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Requests\Api\V1\FCH\Common\EmbedForm\UpdateEmbedFormConfigRequest;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Support\EmbedForm\AllowedDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Round 2 BUG-035: Embedded Form > Allowed Domains kept every entry exactly as
 * typed (a path and all), refused a bare host name such as "x.com" with
 * "The allowed_domains.2 field must be a valid URL.", and let the same domain
 * in twice. An entry is now stored as its bare host - "http://host" when it was
 * typed with http://, so the site is allowed over http as well as https - and a
 * wildcard, localhost, a non-domain or a repeat is refused, naming the entry.
 */
#[CoversClass(UpdateEmbedFormConfigRequest::class)]
#[CoversClass(AllowedDomain::class)]
class AllowedDomainsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = $this->makeCertifiedPartner();
        // What PUT /common/embed-forms/config is gated on (routes/common.php).
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner, ['view configuration', 'update configuration']);
        EmbedFormConfig::create([
            'partner_id' => $this->partner->id,
            'public_key' => 'my-key',
            'form_title' => 'Mine',
            'is_active' => true,
            'allowed_domains' => ['skylinedynamic.dev'],
        ]);
        $this->actingAsUser($this->partnerAdmin);
    }

    private function save(array $domains): TestResponse
    {
        return $this->putJson('/api/v1/common/embed-forms/config', ['allowed_domains' => $domains]);
    }

    /** The refusal for one entry - its key has a dot, which assertJsonPath would read as nesting. */
    private function refusal(TestResponse $response, int $index): ?string
    {
        return $response->assertUnprocessable()->json('errors')["allowed_domains.{$index}"] ?? null;
    }

    private function stored(): array
    {
        return EmbedFormConfig::where('partner_id', $this->partner->id)->firstOrFail()->allowed_domains;
    }

    #[Test]
    public function each_entry_is_stored_as_its_bare_host(): void
    {
        $this->save(['https://X.com/path', 'www.partner.org', 'https://shop.example.co.uk:8443/apply?step=1', 'mysite.com.'])
            ->assertOk();

        $this->assertSame(['x.com', 'partner.org', 'shop.example.co.uk', 'mysite.com'], $this->stored());
    }

    #[Test]
    public function an_entry_typed_with_http_keeps_it_so_http_is_allowed_too(): void
    {
        $this->save(['http://Legacy-Site.com/page'])->assertOk();

        $this->assertSame(['http://legacy-site.com'], $this->stored());
    }

    #[Test]
    public function a_wildcard_is_refused_naming_the_entry(): void
    {
        $this->assertSame(__('embed_form.allowed_domain_wildcard', ['domain' => '*.x.com']), $this->refusal($this->save(['x.com', '*.x.com']), 1));

        $this->assertSame(['skylinedynamic.dev'], $this->stored());
    }

    #[Test]
    public function localhost_is_refused(): void
    {
        $this->assertSame(__('embed_form.allowed_domain_localhost', ['domain' => 'http://localhost:3000']), $this->refusal($this->save(['http://localhost:3000']), 0));
    }

    #[Test]
    public function an_entry_that_is_not_a_domain_is_refused_naming_it(): void
    {
        foreach (['not a domain', 'intranet', '192.168.0.1', 'ftp://x.com', 'x..com'] as $entry) {
            $this->assertSame(__('embed_form.allowed_domain_invalid', ['domain' => $entry]), $this->refusal($this->save([$entry]), 0));
        }

        $this->assertSame(['skylinedynamic.dev'], $this->stored());
    }

    #[Test]
    public function the_same_domain_twice_is_refused_however_it_was_typed(): void
    {
        foreach (['x.com', 'https://X.com/other', 'www.x.com', 'http://x.com'] as $repeat) {
            $this->assertSame(__('embed_form.allowed_domain_duplicate', ['domain' => $repeat]), $this->refusal($this->save(['x.com', $repeat]), 1));
        }

        $this->assertSame(['skylinedynamic.dev'], $this->stored());
    }
}
