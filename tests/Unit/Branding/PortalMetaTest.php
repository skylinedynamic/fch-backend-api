<?php

namespace Tests\Unit\Branding;

use App\Support\Branding\PortalMeta;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The choices behind a portal's link preview, without the database.
 */
class PortalMetaTest extends TestCase
{
    #[Test]
    public function the_title_names_the_tenant_and_the_portal(): void
    {
        $this->assertSame('Acme | Borrower Portal', PortalMeta::build(['site_name' => 'Acme'], PortalMeta::PORTAL_BORROWER, null)['title']);
        $this->assertSame('Acme | Partner Portal', PortalMeta::build(['site_name' => 'Acme'], PortalMeta::PORTAL_PARTNER, null)['title']);
    }

    #[Test]
    public function a_tenant_with_no_name_is_named_after_the_app(): void
    {
        config(['app.name' => 'FCH']);

        $this->assertSame('FCH | Borrower Portal', PortalMeta::build(['site_name' => 'null'], 'borrower', null)['title']);
    }

    #[Test]
    public function the_sidebar_logo_is_the_preview_image(): void
    {
        $meta = PortalMeta::build([
            'logo_sidebar_full_url' => 'https://api.test/storage/sidebar.png',
            'logo_document_full_url' => 'https://api.test/storage/doc.png',
            'logo_mail_full_url' => 'https://api.test/storage/mail.png',
        ], 'borrower', null);

        $this->assertSame('https://api.test/storage/sidebar.png', $meta['image']);
    }

    #[Test]
    public function without_a_usable_sidebar_logo_the_document_logo_is_next(): void
    {
        $meta = PortalMeta::build([
            'logo_sidebar_full_url' => 'https://api.test/storage/sidebar.svg',
            'logo_document_full_url' => 'https://api.test/storage/doc.png',
            'logo_mail_full_url' => 'https://api.test/storage/mail.png',
        ], 'borrower', null);

        $this->assertSame('https://api.test/storage/doc.png', $meta['image']);
    }

    #[Test]
    public function an_svg_is_recognised_behind_a_query_string(): void
    {
        $meta = PortalMeta::build([
            'logo_document_full_url' => 'https://api.test/storage/doc.SVG?v=3',
            'logo_mail_full_url' => 'https://api.test/storage/mail.webp',
        ], 'borrower', null);

        $this->assertSame('https://api.test/storage/mail.webp', $meta['image']);
    }

    #[Test]
    public function only_svg_logos_means_no_preview_image(): void
    {
        $this->assertNull(PortalMeta::build(['logo_document_full_url' => 'https://api.test/storage/doc.svg'], 'borrower', null)['image']);
    }

    #[Test]
    public function the_name_placeholder_is_asked_for_as_png(): void
    {
        $meta = PortalMeta::build(['logo_document_full_url' => 'https://placehold.co/230x70?text=Acme+Co'], 'borrower', null);

        $this->assertSame('https://placehold.co/230x70/png?text=Acme+Co', $meta['image']);
    }

    #[Test]
    public function only_a_real_hostname_becomes_the_url(): void
    {
        $this->assertSame('https://apply.acme.test/', PortalMeta::build([], 'borrower', 'Apply.Acme.test')['url']);
        $this->assertNull(PortalMeta::build([], 'borrower', 'acme.test/path')['url']);
        $this->assertNull(PortalMeta::build([], 'borrower', 'acme.test:8080')['url']);
        $this->assertNull(PortalMeta::build([], 'borrower', '')['url']);
    }
}
