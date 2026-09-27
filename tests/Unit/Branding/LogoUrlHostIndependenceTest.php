<?php

namespace Tests\Unit\Branding;

use App\Services\FCH\Common\Setting\AppConfigurationService;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A stored logo's address must not depend on who asked for it.
 *
 * getBrandingSettings() caches its result for six hours, and the cache key
 * carries the tenant and the inherit flag - not the hostname. So whichever
 * hostname warms the cache has its own name baked into every logo URL every
 * other surface then reads: the admin portal, the borrower portal, invoices,
 * mail, and the public embed form. Only one of those hosts serves `/storage`,
 * so the rest render a broken image until the entry ages out.
 *
 * `asset()` is what caused that - it builds from the incoming request's root.
 * This application already declares where public files live, in the `public`
 * disk's own `url` (config/filesystems.php, from BASE_API_URL), and that answer
 * is the same for every caller. These tests hold the resolution to it.
 */
#[CoversClass(AppConfigurationService::class)]
class LogoUrlHostIndependenceTest extends TestCase
{
    private const DISK_URL = 'https://api.example.test/storage';

    private const OWN_LOGOS = [
        'logo_sidebar' => 'branding/northwind.png',
        'logo_document' => 'branding/northwind.png',
        'logo_mail' => 'branding/northwind.png',
        'logo_mobile' => 'branding/northwind.png',
        'favicon' => 'branding/northwind.png',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => self::DISK_URL]);
    }

    private function resolve(array $ownLogos): array
    {
        $method = new ReflectionMethod(AppConfigurationService::class, 'resolveSurfaceLogos');
        $method->setAccessible(true);

        return $method->invoke(
            app(AppConfigurationService::class),
            $ownLogos,
            $ownLogos,
            true,
            'Northwind Capital'
        );
    }

    #[Test]
    public function the_url_comes_from_the_public_disk_not_the_request(): void
    {
        // A request arriving on the admin portal, which does not serve /storage.
        URL::forceRootUrl('https://admin.example.test');
        URL::forceScheme('https');

        $resolved = $this->resolve(self::OWN_LOGOS);

        $this->assertSame(
            self::DISK_URL . '/branding/northwind.png',
            $resolved['logo_document_full_url']
        );
        $this->assertStringNotContainsString('admin.example.test', $resolved['logo_document_full_url']);
    }

    #[Test]
    public function two_different_hosts_resolve_to_the_same_address(): void
    {
        URL::forceRootUrl('https://admin.example.test');
        $fromAdmin = $this->resolve(self::OWN_LOGOS);

        URL::forceRootUrl('https://tenant.example.test');
        $fromTenant = $this->resolve(self::OWN_LOGOS);

        // The same value is safe to cache and hand to every other surface.
        $this->assertSame($fromAdmin['logo_document_full_url'], $fromTenant['logo_document_full_url']);
        $this->assertSame($fromAdmin['favicon_full_url'], $fromTenant['favicon_full_url']);
    }

    #[Test]
    public function a_filename_with_a_space_is_still_encoded(): void
    {
        $resolved = $this->resolve(['logo_document' => 'branding/my logo.png'] + self::OWN_LOGOS);

        $this->assertSame(
            self::DISK_URL . '/branding/my%20logo.png',
            $resolved['logo_document_full_url']
        );
    }

    #[Test]
    public function a_leading_slash_does_not_produce_a_double_slash(): void
    {
        $resolved = $this->resolve(['logo_document' => '/branding/logo.png'] + self::OWN_LOGOS);

        $this->assertSame(
            self::DISK_URL . '/branding/logo.png',
            $resolved['logo_document_full_url']
        );
        $this->assertStringNotContainsString('storage//', $resolved['logo_document_full_url']);
    }
}
