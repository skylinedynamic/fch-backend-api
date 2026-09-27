<?php

namespace Tests\Unit\EmbeddedForm;

use App\Http\Controllers\Api\V1\Global\Website\EmbedForm\PublicEmbedFormController;
use App\Http\Controllers\Web\EmbedFormPageController;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Which logo the public embed form puts in its header, and where it points.
 *
 * Branding hands out every logo twice: the bare key holds the stored path, and
 * `<key>_full_url` holds the address already built from it - run through
 * encodeUrl() and with any leading slash stripped. Every other surface in the
 * application reads the second one. This page used to read the first and rebuild
 * the address itself with `asset('storage/'.$path)`, which dropped both of those
 * steps: a logo uploaded as "my logo.png" produced an unescaped src that the
 * browser could not load, and a path stored with a leading slash produced
 * `storage//my-logo.png`. The header showed a broken image in both cases.
 *
 * Resolution is called directly - no database, no HTTP request.
 */
#[CoversClass(EmbedFormPageController::class)]
#[CoversClass(PublicEmbedFormController::class)]
class EmbedFormBrandingTest extends TestCase
{
    private const ROOT = 'https://api.example.test';

    /** A logo whose filename contains a space, already encoded by branding. */
    private const ENCODED_LOGO = self::ROOT . '/storage/branding/my%20logo.png';

    protected function setUp(): void
    {
        parent::setUp();

        // asset() reads the request root and scheme, which in a unit test are
        // localhost over http. Forcing both makes the legacy-column assertion
        // exact; the scheme needs its own call, as forceRootUrl leaves it alone.
        URL::forceRootUrl(self::ROOT);
        URL::forceScheme('https');
    }

    private function logoUrl(array $branding, ?Partner $partner = null): ?string
    {
        $method = new ReflectionMethod(EmbedFormPageController::class, 'resolveLogoUrl');
        $method->setAccessible(true);

        return $method->invoke(new EmbedFormPageController(), $branding, $partner);
    }

    private function faviconUrl(array $branding): ?string
    {
        $method = new ReflectionMethod(EmbedFormPageController::class, 'resolveFaviconUrl');
        $method->setAccessible(true);

        return $method->invoke(new EmbedFormPageController(), $branding);
    }

    // -- the address branding built is the address that is used -------------

    #[Test]
    public function a_filename_with_a_space_keeps_the_encoding_branding_applied(): void
    {
        $url = $this->logoUrl([
            'logo_document' => 'branding/my logo.png',
            'logo_document_full_url' => self::ENCODED_LOGO,
        ]);

        $this->assertSame(self::ENCODED_LOGO, $url);
        $this->assertStringNotContainsString(' ', (string) $url);
    }

    #[Test]
    public function a_stored_path_with_a_leading_slash_does_not_produce_a_double_slash(): void
    {
        $url = $this->logoUrl([
            'logo_document' => '/branding/logo.png',
            'logo_document_full_url' => self::ROOT . '/storage/branding/logo.png',
        ]);

        $this->assertSame(self::ROOT . '/storage/branding/logo.png', $url);
        $this->assertStringNotContainsString('storage//', (string) $url);
    }

    // -- the surface fallback chain -----------------------------------------

    #[Test]
    public function the_mail_logo_stands_in_when_the_document_logo_is_unset(): void
    {
        $url = $this->logoUrl([
            'logo_document' => null,
            'logo_document_full_url' => null,
            'logo_mail' => 'branding/mail.png',
            'logo_mail_full_url' => self::ROOT . '/storage/branding/mail.png',
        ]);

        $this->assertSame(self::ROOT . '/storage/branding/mail.png', $url);
    }

    #[Test]
    public function a_tenants_placeholder_logo_is_shown_rather_than_discarded(): void
    {
        // Branding returns a placeholder in _full_url with nothing in the bare
        // key, because the placeholder is not a stored file. Reading the bare
        // key threw it away and the header fell back to a single letter.
        $placeholder = 'https://placehold.co/230x70?text=Northwind+Capital';

        $url = $this->logoUrl([
            'logo_document' => null,
            'logo_document_full_url' => $placeholder,
        ]);

        $this->assertSame($placeholder, $url);
    }

    #[Test]
    public function the_legacy_partner_column_is_encoded_when_it_is_all_that_is_left(): void
    {
        $partner = new Partner(['company_logo' => '/legacy/old logo.png']);

        $url = $this->logoUrl([
            'logo_document' => null,
            'logo_document_full_url' => null,
            'logo_mail_full_url' => null,
        ], $partner);

        $this->assertSame(self::ROOT . '/storage/legacy/old%20logo.png', $url);
    }

    #[Test]
    public function a_partner_with_no_logo_anywhere_gets_nothing_to_render(): void
    {
        // null, not an empty string: the view tests !empty($logoUrl) and draws
        // the initial-letter avatar instead.
        $this->assertNull($this->logoUrl([
            'logo_document' => null,
            'logo_document_full_url' => null,
            'logo_mail_full_url' => null,
        ]));
    }

    #[Test]
    public function branding_keys_missing_altogether_are_not_an_error(): void
    {
        $this->assertNull($this->logoUrl([]));
    }

    // -- favicon ------------------------------------------------------------

    #[Test]
    public function the_favicon_uses_the_address_branding_built(): void
    {
        $encoded = self::ROOT . '/storage/branding/fav%20icon.png';

        $url = $this->faviconUrl([
            'favicon' => 'branding/fav icon.png',
            'favicon_full_url' => $encoded,
        ]);

        $this->assertSame($encoded, $url);
    }

    #[Test]
    public function no_favicon_leaves_the_tag_out(): void
    {
        $this->assertNull($this->faviconUrl([]));
    }

    // -- the JSON config endpoint answers the same way ----------------------

    /**
     * `v1/embed/config/{key}` is the same form's other face: an outside site
     * that renders its own markup reads the logo from there instead of from the
     * page. It carried its own copy of the rebuild-from-the-bare-path bug, so it
     * is held to the same answers rather than left to drift.
     */
    private function publicLogoUrl(array $branding, ?Partner $partner = null): ?string
    {
        $method = new ReflectionMethod(PublicEmbedFormController::class, 'resolveLogoUrl');
        $method->setAccessible(true);

        return $method->invoke(app(PublicEmbedFormController::class), $branding, $partner);
    }

    private function publicFaviconUrl(array $branding): ?string
    {
        $method = new ReflectionMethod(PublicEmbedFormController::class, 'resolveFaviconUrl');
        $method->setAccessible(true);

        return $method->invoke(app(PublicEmbedFormController::class), $branding);
    }

    #[Test]
    public function the_config_endpoint_keeps_the_encoding_branding_applied(): void
    {
        $url = $this->publicLogoUrl([
            'logo_document' => 'branding/my logo.png',
            'logo_document_full_url' => self::ENCODED_LOGO,
        ]);

        $this->assertSame(self::ENCODED_LOGO, $url);
    }

    #[Test]
    public function the_config_endpoint_shows_the_tenants_placeholder(): void
    {
        $placeholder = 'https://placehold.co/230x70?text=Northwind+Capital';

        $this->assertSame($placeholder, $this->publicLogoUrl([
            'logo_document' => null,
            'logo_document_full_url' => $placeholder,
        ]));
    }

    #[Test]
    public function the_config_endpoint_returns_null_with_no_logo_anywhere(): void
    {
        $this->assertNull($this->publicLogoUrl([]));
        $this->assertNull($this->publicFaviconUrl([]));
    }

    #[Test]
    public function the_config_endpoint_favicon_uses_the_built_address(): void
    {
        $encoded = self::ROOT . '/storage/branding/fav%20icon.png';

        $this->assertSame($encoded, $this->publicFaviconUrl([
            'favicon' => 'branding/fav icon.png',
            'favicon_full_url' => $encoded,
        ]));
    }
}
