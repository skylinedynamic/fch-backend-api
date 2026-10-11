<?php

namespace Tests\Unit\Branding;

use App\Support\Branding\PdfLogo;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A stored logo, as something Dompdf can actually draw.
 *
 * Dompdf will not fetch a remote URL unless remote access is enabled, and
 * enabling that on a PDF built from tenant-supplied data is a request-forgery
 * hole we do not need: the file is on our own disk, so it goes in as a data URI.
 *
 * The invoice template already does this, inline, in the middle of the view.
 * This is that logic named and tested once rather than copied into a second
 * template - including the two details it is easy to get wrong: `is_file`
 * rather than `file_exists`, because the latter is true for a directory and
 * file_get_contents() then fails; and a mime type from the extension, because
 * Dompdf trusts the data URI's declared type rather than sniffing it.
 */
#[CoversClass(PdfLogo::class)]
class PdfLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function store(string $path, string $contents = 'not-really-an-image'): void
    {
        Storage::disk('public')->put($path, $contents);
    }

    #[Test]
    public function it_embeds_a_stored_logo_as_a_data_uri(): void
    {
        $this->store('branding/logo.png');

        $src = PdfLogo::dataUri('branding/logo.png');

        $this->assertNotNull($src);
        $this->assertStringStartsWith('data:image/png;base64,', $src);
        $this->assertSame(
            'not-really-an-image',
            base64_decode(substr($src, strlen('data:image/png;base64,')))
        );
    }

    #[DataProvider('mimeTypes')]
    #[Test]
    public function it_declares_the_type_from_the_extension(string $file, string $mime): void
    {
        // Dompdf trusts what the data URI says it is rather than sniffing, so a
        // JPEG announced as a PNG simply does not render.
        $this->store($file);

        $this->assertStringStartsWith("data:{$mime};base64,", (string) PdfLogo::dataUri($file));
    }

    public static function mimeTypes(): array
    {
        return [
            'png' => ['branding/a.png', 'image/png'],
            'jpg' => ['branding/a.jpg', 'image/jpeg'],
            'jpeg' => ['branding/a.jpeg', 'image/jpeg'],
            'gif' => ['branding/a.gif', 'image/gif'],
            'webp' => ['branding/a.webp', 'image/webp'],
            'svg' => ['branding/a.svg', 'image/svg+xml'],
            'upper case extension' => ['branding/a.PNG', 'image/png'],
        ];
    }

    #[Test]
    public function a_leading_slash_still_finds_the_file(): void
    {
        // Branding stores paths bare, but not every writer has been consistent.
        $this->store('branding/logo.png');

        $this->assertNotNull(PdfLogo::dataUri('/branding/logo.png'));
    }

    #[Test]
    public function a_full_storage_url_is_reduced_to_the_path(): void
    {
        // Callers reach for `logo_document_full_url` by habit; it names the same
        // file, so it resolves rather than silently producing no logo.
        $this->store('branding/logo.png');

        $this->assertNotNull(
            PdfLogo::dataUri('https://api.example.test/storage/branding/logo.png')
        );
    }

    // -- nothing to draw ---------------------------------------------------

    #[Test]
    public function a_missing_file_yields_nothing_rather_than_a_broken_image(): void
    {
        $this->assertNull(PdfLogo::dataUri('branding/absent.png'));
    }

    #[Test]
    public function a_directory_is_not_mistaken_for_a_file(): void
    {
        // file_exists() is true for a directory and file_get_contents() then
        // fails - which is why this uses is_file().
        $this->store('branding/logo.png');

        $this->assertNull(PdfLogo::dataUri('branding'));
    }

    #[DataProvider('nothings')]
    #[Test]
    public function it_returns_null_for(mixed $value): void
    {
        $this->assertNull(PdfLogo::dataUri($value));
    }

    public static function nothings(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace' => ['   '],
            'the string null' => ['null'],
        ];
    }

    // -- the surface fallback chain ----------------------------------------

    /**
     * Branding already chains these by DB value, but only by value: a key
     * pointing at a file that is no longer on disk still counts as set. This
     * chain is over what can actually be drawn, so a stale path falls through
     * to the next surface instead of leaving the document with no mark at all.
     */
    #[Test]
    public function it_takes_the_first_surface_that_has_a_file(): void
    {
        $this->store('branding/mail.png', 'the-mail-one');

        $src = PdfLogo::firstAvailable([
            'branding/document.png',
            'branding/mail.png',
            'branding/sidebar.png',
        ]);

        $this->assertSame('the-mail-one', base64_decode(explode(',', (string) $src)[1]));
    }

    #[Test]
    public function the_document_logo_wins_when_it_is_there(): void
    {
        $this->store('branding/document.png', 'the-document-one');
        $this->store('branding/mail.png', 'the-mail-one');

        $src = PdfLogo::firstAvailable(['branding/document.png', 'branding/mail.png']);

        $this->assertSame('the-document-one', base64_decode(explode(',', (string) $src)[1]));
    }

    #[Test]
    public function a_key_whose_file_is_gone_does_not_end_the_chain(): void
    {
        // The DB still names document.png; the file is not there. Branding
        // would stop at it, which is how a tenant with three logos uploaded
        // ended up with a blank masthead.
        $this->store('branding/sidebar.png', 'the-sidebar-one');

        $src = PdfLogo::firstAvailable([
            'branding/document.png',
            'branding/mail.png',
            'branding/sidebar.png',
        ]);

        $this->assertSame('the-sidebar-one', base64_decode(explode(',', (string) $src)[1]));
    }

    #[Test]
    public function nulls_and_blanks_in_the_chain_are_skipped(): void
    {
        $this->store('branding/sidebar.png', 'the-sidebar-one');

        $src = PdfLogo::firstAvailable([null, '', 'null', 'branding/sidebar.png']);

        $this->assertNotNull($src);
    }

    #[Test]
    public function an_empty_chain_draws_nothing(): void
    {
        $this->assertNull(PdfLogo::firstAvailable([]));
        $this->assertNull(PdfLogo::firstAvailable([null, '', 'branding/absent.png']));
    }
}
