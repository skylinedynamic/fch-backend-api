<?php

namespace Tests\Unit\Agreement;

use App\Services\FCH\Common\ManageAgreement\AgreementImageStore;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Agreement HTML/JSON carries its images (logo, signature) as base64 data URIs,
 * which made a template ~1.4 MB and a signed agreement up to ~6 MB in the
 * database. The store keeps each image once on disk, named by its content, and
 * leaves only a short reference in the text; reading puts the exact data URI back.
 */
#[CoversClass(AgreementImageStore::class)]
class AgreementImageStoreTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private AgreementImageStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['agreement.image_disk' => 'local', 'agreement.image_directory' => 'agreement-images']);
        $this->store = new AgreementImageStore();
    }

    private function hash(string $base64): string
    {
        return hash('sha256', base64_decode($base64));
    }

    #[Test]
    public function it_moves_a_base64_image_to_disk_and_leaves_a_short_reference(): void
    {
        $html = '<img src="data:image/png;base64,' . self::PNG . '"/>';

        $stored = $this->store->extract($html);

        $file = 'agreement-images/' . $this->hash(self::PNG) . '.png';
        $this->assertSame('<img src="agreement-image:' . $this->hash(self::PNG) . '.png"/>', $stored);
        Storage::disk('local')->assertExists($file);
        $this->assertSame(base64_decode(self::PNG), Storage::disk('local')->get($file));
    }

    #[Test]
    public function the_same_image_used_twice_is_kept_once(): void
    {
        $uri = 'data:image/png;base64,' . self::PNG;

        $stored = $this->store->extract("<img src=\"{$uri}\"/><img src=\"{$uri}\"/>");

        $this->assertSame(2, substr_count($stored, 'agreement-image:'));
        $this->assertCount(1, Storage::disk('local')->allFiles('agreement-images'));
    }

    #[Test]
    public function reading_puts_back_exactly_what_was_written(): void
    {
        $original = '{"blocks":[{"type":"logo","logoSrc":"data:image/png;base64,' . self::PNG . '"},'
            . '{"type":"signature","logoSrc":"data:image/jpeg;base64,' . self::PNG . '"}]}';

        $this->assertSame($original, $this->store->inline($this->store->extract($original)));
    }

    #[Test]
    public function it_moves_every_common_image_type_and_keeps_the_type_as_written(): void
    {
        // heic/heif: signatures uploaded as iPhone photos.
        foreach (['jpg', 'jpeg', 'gif', 'webp', 'svg+xml', 'heic', 'heif', 'avif', 'bmp'] as $type) {
            $original = "<img src=\"data:image/{$type};base64," . self::PNG . '"/>';

            $stored = $this->store->extract($original);

            $this->assertStringNotContainsString('base64', $stored, $type);
            $this->assertSame($original, $this->store->inline($stored), $type);
        }
    }

    #[Test]
    public function it_leaves_alone_what_it_could_not_put_back_byte_for_byte(): void
    {
        $unknownType = '<img src="data:image/x-unknown;base64,' . self::PNG . '"/>';
        $notBase64 = '<img src="data:image/png;charset=utf-8,hello"/>';
        $nonCanonical = '<img src="data:image/png;base64,' . rtrim(self::PNG, '=') . '"/>';

        foreach ([$unknownType, $notBase64, $nonCanonical] as $html) {
            $this->assertSame($html, $this->store->extract($html));
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function text_without_images_and_null_pass_through(): void
    {
        $this->assertSame('<p>Terms</p>', $this->store->extract('<p>Terms</p>'));
        $this->assertSame('<p>Terms</p>', $this->store->inline('<p>Terms</p>'));
        $this->assertNull($this->store->extract(null));
        $this->assertNull($this->store->inline(null));
    }

    #[Test]
    public function a_reference_whose_file_is_missing_is_left_as_it_is(): void
    {
        $missing = '<img src="agreement-image:' . str_repeat('a', 64) . '.png"/>';

        $this->assertSame($missing, $this->store->inline($missing));
    }

    #[Test]
    public function a_reference_can_only_name_a_file_in_the_image_directory(): void
    {
        Storage::disk('local')->put('secret.png', 'secret');
        $traversal = '<img src="agreement-image:../secret.png"/>';

        $this->assertSame($traversal, $this->store->inline($traversal));
    }
}
