<?php

namespace Tests\Unit\Branding;

use App\Services\FCH\Common\Setting\AppConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What a tenant sees when it has uploaded no logo of its own.
 *
 * Two different answers, and mixing them up is the bug these guard. On the
 * host's own surfaces the host's logo is the right fallback. On a tenant that is
 * branded as itself it is the worst possible one: a white-label portal exists so
 * that the host is invisible, and the host's mark above that tenant's colours
 * announces exactly what the portal is meant to hide. Those tenants get a plain
 * placeholder carrying their own company name instead.
 *
 * Resolution is called directly - no database, no partner record.
 */
#[CoversClass(AppConfigurationService::class)]
class TenantLogoFallbackTest extends TestCase
{
    private const HOST_LOGOS = [
        'logo_sidebar' => 'branding/host-sidebar.png',
        'logo_document' => 'branding/host-document.png',
        'logo_mail' => 'branding/host-mail.png',
        'logo_mobile' => 'branding/host-mobile.png',
        'favicon' => 'branding/host-favicon.png',
    ];

    private const NO_LOGOS = [
        'logo_sidebar' => null,
        'logo_document' => null,
        'logo_mail' => null,
        'logo_mobile' => null,
        'favicon' => null,
    ];

    /** The surfaces a borrower sees while using the portal. */
    private const PORTAL_KEYS = ['logo_sidebar', 'logo_document', 'logo_mail', 'logo_mobile'];

    private function resolve(
        array $ownLogos,
        bool $inheritLogosFromHost,
        ?string $tenantName = 'Northwind Capital'
    ): array {
        $method = new ReflectionMethod(AppConfigurationService::class, 'resolveSurfaceLogos');
        $method->setAccessible(true);

        return $method->invoke(
            app(AppConfigurationService::class),
            $ownLogos,
            self::HOST_LOGOS,
            $inheritLogosFromHost,
            $tenantName
        );
    }

    // ── A tenant branded as itself ────────────────────────────────────

    #[Test]
    public function a_tenant_with_no_logo_is_never_given_the_hosts(): void
    {
        $resolved = $this->resolve(self::NO_LOGOS, inheritLogosFromHost: false);

        foreach (self::PORTAL_KEYS as $key) {
            $this->assertNull($resolved[$key], "{$key} borrowed an upload from the host");
            $this->assertStringNotContainsString(
                'host-',
                (string) $resolved[$key . '_full_url'],
                "{$key}_full_url pointed at the host's file"
            );
        }
    }

    #[Test]
    public function the_placeholder_carries_the_tenants_own_company_name(): void
    {
        $resolved = $this->resolve(self::NO_LOGOS, inheritLogosFromHost: false);

        foreach (self::PORTAL_KEYS as $key) {
            $this->assertSame(
                'https://placehold.co/230x70?text=Northwind+Capital',
                $resolved[$key . '_full_url'],
                "{$key}_full_url was not the named placeholder"
            );
        }
    }

    #[Test]
    public function a_tenants_own_upload_still_wins_over_the_placeholder(): void
    {
        $resolved = $this->resolve(
            array_merge(self::NO_LOGOS, ['logo_sidebar' => 'branding/northwind.png']),
            inheritLogosFromHost: false
        );

        $this->assertSame('branding/northwind.png', $resolved['logo_sidebar']);
        $this->assertStringContainsString('northwind.png', $resolved['logo_sidebar_full_url']);
        $this->assertStringNotContainsString('placehold.co', $resolved['logo_sidebar_full_url']);
    }

    #[Test]
    public function the_placeholder_never_reaches_the_bare_key(): void
    {
        // Anything that writes branding back reads the bare key. A URL landing
        // there would be saved as though it were an uploaded file path.
        $resolved = $this->resolve(self::NO_LOGOS, inheritLogosFromHost: false);

        foreach (self::PORTAL_KEYS as $key) {
            $this->assertNull($resolved[$key], "{$key} held a generated URL");
        }
    }

    #[Test]
    public function a_nameless_tenant_falls_through_to_the_portals_own_default(): void
    {
        // Null rather than an empty grey box: the portal renders its packaged
        // logo when it is handed nothing.
        $resolved = $this->resolve(self::NO_LOGOS, inheritLogosFromHost: false, tenantName: null);

        foreach (self::PORTAL_KEYS as $key) {
            $this->assertNull($resolved[$key . '_full_url'], "{$key}_full_url was not left empty");
        }
    }

    #[Test]
    public function the_favicon_is_exempt_and_still_inherits(): void
    {
        // A 230x70 placeholder is unreadable at 16px, so the tab icon keeps the
        // host's rather than being blanked.
        $resolved = $this->resolve(self::NO_LOGOS, inheritLogosFromHost: false);

        $this->assertSame('branding/host-favicon.png', $resolved['favicon']);
        $this->assertStringContainsString('host-favicon.png', $resolved['favicon_full_url']);
    }

    // ── The host, and everything trading under its brand ──────────────

    #[Test]
    public function inheriting_tenants_still_get_the_hosts_logo(): void
    {
        // Invoices, mail and PDFs, plus every partner that deliberately trades
        // under the host's brand. Unchanged by the rule above.
        $resolved = $this->resolve(self::NO_LOGOS, inheritLogosFromHost: true);

        foreach (self::PORTAL_KEYS as $key) {
            $this->assertSame(self::HOST_LOGOS[$key], $resolved[$key], "{$key} lost its host fallback");
            $this->assertStringNotContainsString(
                'placehold.co',
                (string) $resolved[$key . '_full_url'],
                "{$key}_full_url was replaced by a placeholder"
            );
        }
    }

    #[Test]
    public function an_inheriting_tenant_with_nothing_anywhere_is_left_empty(): void
    {
        $method = new ReflectionMethod(AppConfigurationService::class, 'resolveSurfaceLogos');
        $method->setAccessible(true);

        $resolved = $method->invoke(
            app(AppConfigurationService::class),
            self::NO_LOGOS,
            self::NO_LOGOS,
            true,
            'First Capital Holdings'
        );

        foreach (array_keys(self::NO_LOGOS) as $key) {
            $this->assertNull($resolved[$key . '_full_url'], "{$key}_full_url invented a URL");
        }
    }
}
