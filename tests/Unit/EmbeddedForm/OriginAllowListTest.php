<?php

namespace Tests\Unit\EmbeddedForm;

use App\Models\EmbedForm\EmbedFormConfig;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The "Allowed Domains" gate.
 *
 * The setting was collected on the configuration screen from the day the
 * feature shipped but was never checked anywhere, so any site could embed any
 * partner's form. These tests pin the behaviour that closed that.
 *
 * Models are constructed in memory, never saved - the check reads only
 * `allowed_domains` and the request headers.
 */
#[CoversClass(EmbedFormService::class)]
class OriginAllowListTest extends TestCase
{
    private EmbedFormService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EmbedFormService();
        config(['app.url' => 'https://api.fourcornerfunding.com']);
    }

    private function config(array $allowed): EmbedFormConfig
    {
        return new EmbedFormConfig(['allowed_domains' => $allowed]);
    }

    private function requestFrom(?string $origin, ?string $referer = null): Request
    {
        $headers = [];
        if ($origin !== null) {
            $headers['HTTP_ORIGIN'] = $origin;
        }
        if ($referer !== null) {
            $headers['HTTP_REFERER'] = $referer;
        }

        return Request::create('/api/v1/embed/submit/key', 'POST', [], [], [], $headers);
    }

    #[Test]
    public function an_empty_list_means_unrestricted(): void
    {
        // Which is exactly what the configuration screen promises the partner.
        $this->assertTrue($this->service->isOriginAllowed(
            $this->config([]),
            $this->requestFrom('https://anywhere.example')
        ));

        $this->assertTrue($this->service->isOriginAllowed(
            $this->config([]),
            $this->requestFrom(null)
        ));
    }

    public static function matchingOrigins(): array
    {
        return [
            'exact match' => ['https://willowcreek.example'],
            'www prefix on the request' => ['https://www.willowcreek.example'],
            'http where https was listed' => ['http://willowcreek.example'],
            'with a port' => ['https://willowcreek.example:8443'],
            'uppercase host' => ['https://WillowCreek.Example'],
        ];
    }

    #[Test]
    #[DataProvider('matchingOrigins')]
    public function it_allows_a_listed_domain_however_the_origin_is_written(string $origin): void
    {
        $this->assertTrue($this->service->isOriginAllowed(
            $this->config(['https://willowcreek.example']),
            $this->requestFrom($origin)
        ));
    }

    #[Test]
    public function a_bare_domain_in_the_list_matches_too(): void
    {
        // Partners type bare domains as often as full URLs.
        $this->assertTrue($this->service->isOriginAllowed(
            $this->config(['willowcreek.example']),
            $this->requestFrom('https://willowcreek.example')
        ));
    }

    #[Test]
    public function a_www_prefix_in_the_list_matches_the_bare_host(): void
    {
        $this->assertTrue($this->service->isOriginAllowed(
            $this->config(['https://www.willowcreek.example']),
            $this->requestFrom('https://willowcreek.example')
        ));
    }

    #[Test]
    public function it_blocks_a_domain_that_is_not_listed(): void
    {
        $this->assertFalse($this->service->isOriginAllowed(
            $this->config(['https://willowcreek.example']),
            $this->requestFrom('https://evil.example')
        ));
    }

    #[Test]
    public function a_subdomain_of_a_listed_host_is_not_the_listed_host(): void
    {
        $this->assertFalse($this->service->isOriginAllowed(
            $this->config(['https://willowcreek.example']),
            $this->requestFrom('https://anything.willowcreek.example')
        ));
    }

    #[Test]
    public function a_listed_host_appearing_only_as_a_suffix_does_not_match(): void
    {
        // notwillowcreek.example must not pass because it ends with the allowed
        // host - a substring check would have let it through.
        $this->assertFalse($this->service->isOriginAllowed(
            $this->config(['https://willowcreek.example']),
            $this->requestFrom('https://notwillowcreek.example')
        ));
    }

    #[Test]
    public function it_falls_back_to_the_referer_when_there_is_no_origin(): void
    {
        $this->assertTrue($this->service->isOriginAllowed(
            $this->config(['https://willowcreek.example']),
            $this->requestFrom(null, 'https://willowcreek.example/apply?utm=x')
        ));
    }

    #[Test]
    public function it_blocks_a_request_carrying_neither_origin_nor_referer(): void
    {
        // Not a browser posting from a page. With an allow-list configured that
        // is refused rather than waved through.
        $this->assertFalse($this->service->isOriginAllowed(
            $this->config(['https://willowcreek.example']),
            $this->requestFrom(null)
        ));
    }

    #[Test]
    public function the_apis_own_host_is_always_allowed(): void
    {
        // Otherwise a partner listing only their website would break their own
        // Direct Shareable Link, which is served from the API.
        $this->assertTrue($this->service->isOriginAllowed(
            $this->config(['https://willowcreek.example']),
            $this->requestFrom('https://api.fourcornerfunding.com')
        ));
    }

    #[Test]
    public function any_domain_on_a_multi_entry_list_is_allowed(): void
    {
        $config = $this->config([
            'https://willowcreek.example',
            'partner-two.example',
        ]);

        $this->assertTrue($this->service->isOriginAllowed($config, $this->requestFrom('https://willowcreek.example')));
        $this->assertTrue($this->service->isOriginAllowed($config, $this->requestFrom('https://partner-two.example')));
        $this->assertFalse($this->service->isOriginAllowed($config, $this->requestFrom('https://partner-three.example')));
    }
}
