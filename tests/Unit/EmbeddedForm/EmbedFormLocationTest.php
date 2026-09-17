<?php

namespace Tests\Unit\EmbeddedForm;

use App\Models\Partner\Partner;
use App\Support\EmbedForm\EmbedFormLocation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where a partner's public application form is published.
 *
 * The link used to be built on the API's own host, which showed a prospective
 * borrower the platform's address rather than the partner's. It is a public
 * lead-capture page, so it now sits on the partner's own public-facing domain -
 * and for a White-Label partner that means their domain and nothing else.
 * Falling back to the platform's address there would publish the very thing the
 * tier exists to hide, so these pin that there is no such fallback: no domain,
 * no form.
 *
 * Partners are built in memory - resolution reads only their own columns.
 */
#[CoversClass(EmbedFormLocation::class)]
class EmbedFormLocationTest extends TestCase
{
    private const BASE_WEBSITE = 'fourcornerfunding.com';
    private const API_HOST = 'https://api.fourcornerfunding.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::API_HOST,
            'app.base_website' => self::BASE_WEBSITE,
        ]);
    }

    /**
     * Resolution branches on environment, and the interesting branch is
     * production - a tenant domain resolves nowhere anywhere else.
     */
    private function inProduction(callable $assertions): void
    {
        $original = app()->environment();

        app()['env'] = 'production';

        try {
            $assertions();
        } finally {
            app()['env'] = $original;
        }
    }

    private function partner(string $type, array $attributes = []): Partner
    {
        return new Partner(array_merge([
            'partner_type' => $type,
            'is_host' => false,
            'active_custom_url' => true,
        ], $attributes));
    }

    // -- The platform's own site: host and Certified --------------------

    #[Test]
    public function the_host_publishes_on_the_base_website(): void
    {
        // Note the partner_type: the host is stored as White-Label in the live
        // data, so a type-first branch would send it down the partner-domain
        // path and find no columns set. is_host has to be tested first.
        $this->inProduction(function () {
            $location = EmbedFormLocation::forPartner($this->partner(
                Partner::PARTNER_TYPE_WHITE_LABEL,
                ['is_host' => true, 'partner_domain' => null, 'partner_subdomain' => null]
            ));

            $this->assertTrue($location->isAvailable());
            $this->assertSame('https://' . self::BASE_WEBSITE, $location->baseUrl());
        });
    }

    #[Test]
    public function a_certified_partner_publishes_on_the_base_website(): void
    {
        $this->inProduction(function () {
            $location = EmbedFormLocation::forPartner($this->partner(Partner::PARTNER_TYPE_CERTIFIED));

            $this->assertTrue($location->isAvailable());
            $this->assertSame('https://' . self::BASE_WEBSITE, $location->baseUrl());
        });
    }

    #[Test]
    public function a_certified_partners_own_domain_is_ignored(): void
    {
        // Only White-Label publishes on a domain of its own. A stray value on a
        // Certified row must not quietly become the published address.
        $this->inProduction(function () {
            $location = EmbedFormLocation::forPartner($this->partner(
                Partner::PARTNER_TYPE_CERTIFIED,
                ['partner_domain' => 'stray.example.com', 'partner_subdomain' => 'stray2.example.com']
            ));

            $this->assertSame('https://' . self::BASE_WEBSITE, $location->baseUrl());
        });
    }

    #[Test]
    public function the_link_never_points_at_the_api_host_in_production(): void
    {
        // The regression this whole change exists to prevent.
        $this->inProduction(function () {
            foreach ([Partner::PARTNER_TYPE_CERTIFIED, Partner::PARTNER_TYPE_WHITE_LABEL] as $type) {
                $location = EmbedFormLocation::forPartner($this->partner($type, [
                    'partner_domain' => 'admin.northwind.com',
                ]));

                $this->assertStringNotContainsString(
                    'api.fourcornerfunding.com',
                    (string) $location->formUrl('abc123'),
                    "{$type} was published on the API host"
                );
            }
        });
    }

    // -- White-Label: its own domain, or nothing -----------------------

    #[Test]
    public function a_white_label_partner_publishes_on_its_own_domain(): void
    {
        $this->inProduction(function () {
            $location = EmbedFormLocation::forPartner($this->partner(
                Partner::PARTNER_TYPE_WHITE_LABEL,
                ['partner_domain' => 'admin.northwind.com', 'partner_subdomain' => 'northwind.fourcornerfunding.com']
            ));

            $this->assertSame('https://admin.northwind.com', $location->baseUrl());
            $this->assertSame('https://admin.northwind.com/form/abc123', $location->formUrl('abc123'));
        });
    }

    #[Test]
    public function an_inactive_custom_url_falls_back_to_the_subdomain(): void
    {
        // The single most common case in the live data: 14 of 24 White-Label
        // partners have a partner_domain with active_custom_url off. Caddy
        // issues no certificate for that column while the flag is off, so the
        // domain resolves nowhere and a link there would be dead. The
        // platform-issued subdomain is approved unconditionally.
        $this->inProduction(function () {
            $location = EmbedFormLocation::forPartner($this->partner(
                Partner::PARTNER_TYPE_WHITE_LABEL,
                [
                    'active_custom_url' => false,
                    'partner_domain' => 'admin.northwind.com',
                    'partner_subdomain' => 'northwind.fourcornerfunding.com',
                ]
            ));

            $this->assertSame('https://northwind.fourcornerfunding.com', $location->baseUrl());
            $this->assertStringNotContainsString('admin.northwind.com', (string) $location->formUrl('abc123'));
        });
    }

    #[Test]
    public function a_white_label_partner_with_no_domain_at_all_cannot_publish(): void
    {
        $location = EmbedFormLocation::forPartner($this->partner(
            Partner::PARTNER_TYPE_WHITE_LABEL,
            ['partner_domain' => null, 'partner_subdomain' => null]
        ));

        $this->assertFalse($location->isAvailable());
        $this->assertNull($location->baseUrl());
        $this->assertSame(__('embed_form.partner_domain_required'), $location->warning());
    }

    #[Test]
    public function an_inactive_domain_with_no_subdomain_cannot_publish(): void
    {
        // Neither column is usable, so there is nowhere to publish - the
        // inactive domain must not be used as a last resort.
        $location = EmbedFormLocation::forPartner($this->partner(
            Partner::PARTNER_TYPE_WHITE_LABEL,
            ['active_custom_url' => false, 'partner_domain' => 'admin.northwind.com', 'partner_subdomain' => null]
        ));

        $this->assertFalse($location->isAvailable());
        $this->assertSame(__('embed_form.partner_domain_required'), $location->warning());
    }

    #[Test]
    public function a_blocked_partner_is_given_no_link_and_no_snippet(): void
    {
        // Null, never a fallback URL. A link built on the platform's address
        // would look valid, get pasted into a website, and serve the wrong
        // brand to this partner's applicants.
        $location = EmbedFormLocation::forPartner($this->partner(Partner::PARTNER_TYPE_WHITE_LABEL));

        $this->assertNull($location->formUrl('abc123'));
        $this->assertNull($location->embedSnippet('abc123'));
        $this->assertNull($location->host());
    }

    #[Test]
    public function the_block_applies_outside_production_too(): void
    {
        // Whether a domain is configured is a data rule, not a routing one, so
        // it does not relax on staging - otherwise the warning could only ever
        // be seen in production.
        $this->assertFalse(app()->environment('production'));

        $location = EmbedFormLocation::forPartner($this->partner(Partner::PARTNER_TYPE_WHITE_LABEL));

        $this->assertFalse($location->isAvailable());
        $this->assertSame(__('embed_form.partner_domain_required'), $location->warning());
    }

    // -- Shape of what gets published ----------------------------------

    #[Test]
    public function the_snippet_embeds_the_same_url_as_the_direct_link(): void
    {
        $this->inProduction(function () {
            $location = EmbedFormLocation::forPartner($this->partner(
                Partner::PARTNER_TYPE_WHITE_LABEL,
                ['partner_domain' => 'admin.northwind.com']
            ));

            $this->assertStringContainsString(
                'src="' . $location->formUrl('abc123') . '"',
                $location->embedSnippet('abc123')
            );
        });
    }

    #[Test]
    public function a_partner_with_no_key_yet_has_no_link(): void
    {
        $location = EmbedFormLocation::forPartner($this->partner(Partner::PARTNER_TYPE_CERTIFIED));

        $this->assertTrue($location->isAvailable());
        $this->assertNull($location->formUrl(null));
        $this->assertNull($location->embedSnippet(null));
    }

    #[Test]
    public function a_bare_domain_gains_a_scheme(): void
    {
        // BASE_WEBSITE and both partner columns are stored bare, and an iframe
        // src without a scheme resolves against the embedding page.
        $this->inProduction(function () {
            $location = EmbedFormLocation::forPartner($this->partner(
                Partner::PARTNER_TYPE_WHITE_LABEL,
                ['partner_domain' => 'admin.northwind.com']
            ));

            $this->assertStringStartsWith('https://', (string) $location->baseUrl());
            $this->assertSame('admin.northwind.com', $location->host());
        });
    }
}
