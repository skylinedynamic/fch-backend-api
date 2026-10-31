<?php

namespace Tests\Unit\Rules;

use App\Rules\SafeUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-015 (QA round 2): an SVG with a <script> in it was accepted as a
 * borrower document and, opened from /storage, ran on the API's domain. The
 * server checked no file type at all - only the portals did. Documents and
 * attachments now take a fixed list of types and never SVG; logos and images
 * take SVG with every script stripped out. Either way the file's content is
 * checked, so HTML renamed to .jpg is refused too.
 */
#[CoversClass(SafeUpload::class)]
class SafeUploadTest extends TestCase
{
    private const SCRIPTED_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
        . '<script>console.log("svg-xss")</script>'
        . '<rect width="10" height="10" fill="red" onload="alert(1)"/></svg>';

    private function file(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function passes(SafeUpload $rule, mixed $value): bool
    {
        return Validator::make(['file' => $value], ['file' => [$rule]])->passes();
    }

    #[Test]
    public function a_document_may_not_be_an_svg_with_or_without_a_script(): void
    {
        $this->assertFalse($this->passes(SafeUpload::document(), $this->file('qa-r2-logo.svg', self::SCRIPTED_SVG)));
        $this->assertFalse($this->passes(SafeUpload::document(), $this->file('clean.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')));
    }

    #[Test]
    public function a_document_may_not_be_html_or_script_under_any_name(): void
    {
        $this->assertFalse($this->passes(SafeUpload::document(), $this->file('page.html', '<html><body>x</body></html>')));
        $this->assertFalse($this->passes(SafeUpload::document(), $this->file('statement.jpg', '<html><script>alert(1)</script></html>')));
        $this->assertFalse($this->passes(SafeUpload::document(), $this->file('notes.txt', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')));
        $this->assertFalse($this->passes(SafeUpload::document(), $this->file('run.php', '<?php echo 1;')));
    }

    #[Test]
    public function ordinary_documents_and_images_still_pass(): void
    {
        $this->assertTrue($this->passes(SafeUpload::document(), UploadedFile::fake()->create('bank statement.pdf', 20, 'application/pdf')));
        $this->assertTrue($this->passes(SafeUpload::document(), UploadedFile::fake()->image('license.jpg')));
        $this->assertTrue($this->passes(SafeUpload::document(), UploadedFile::fake()->create('taxes.xlsx', 20)));
        $this->assertTrue($this->passes(SafeUpload::document(), $this->file('notes.txt', 'Called the client at 3pm.')));
    }

    #[Test]
    public function a_value_that_is_not_an_upload_is_left_to_the_other_rules(): void
    {
        $this->assertTrue($this->passes(SafeUpload::document(), 'partners/7/logo.png'));
        $this->assertTrue($this->passes(SafeUpload::image(), null));
    }

    #[Test]
    public function a_logo_may_be_an_svg_and_is_stored_without_its_scripts(): void
    {
        $logo = $this->file('logo.svg', self::SCRIPTED_SVG);

        $this->assertTrue($this->passes(SafeUpload::image(), $logo));

        $stored = file_get_contents($logo->getRealPath());
        $this->assertStringContainsString('<rect', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
    }

    #[Test]
    public function a_logo_that_is_not_an_image_is_refused(): void
    {
        $this->assertFalse($this->passes(SafeUpload::image(), $this->file('logo.svg', 'not an svg at all')));
        $this->assertFalse($this->passes(SafeUpload::image(), $this->file('logo.png', '<html><script>alert(1)</script></html>')));
        $this->assertFalse($this->passes(SafeUpload::image(), UploadedFile::fake()->create('logo.pdf', 20, 'application/pdf')));
        $this->assertTrue($this->passes(SafeUpload::image(), UploadedFile::fake()->image('logo.png')));
    }

    /**
     * BUG-014 (QA round 2): a 12 MB file named qa-12mb.png - the PNG signature
     * and then zeros - was accepted by the file manager and could be picked as
     * a profile picture. Uploads now have a size limit (pictures 5 MB, anything
     * else 20 MB) and a picture has to be one.
     */
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    private function sized(string $name, int $kilobytes, string $head = ''): UploadedFile
    {
        return $this->file($name, $head . str_repeat("\0", $kilobytes * 1024 - strlen($head)));
    }

    #[Test]
    public function a_document_may_be_up_to_20_mb(): void
    {
        $this->assertTrue($this->passes(SafeUpload::document(), $this->sized('statement.pdf', 20 * 1024, '%PDF-1.7')));
        $this->assertSame(
            __('uploads.too_large', ['size' => 20]),
            $this->errorFor(SafeUpload::document(), $this->sized('statement.pdf', 20 * 1024 + 1, '%PDF-1.7'))
        );
    }

    #[Test]
    public function a_picture_may_be_up_to_5_mb_as_a_document_or_an_image(): void
    {
        $this->assertSame(__('uploads.too_large', ['size' => 5]), $this->errorFor(SafeUpload::document(), $this->sized('photo.png', 5 * 1024 + 1, self::PNG_MAGIC)));
        $this->assertSame(__('uploads.too_large', ['size' => 5]), $this->errorFor(SafeUpload::image(), $this->sized('logo.jpg', 5 * 1024 + 1, "\xFF\xD8\xFF")));
        $this->assertTrue($this->passes(SafeUpload::image(), UploadedFile::fake()->image('logo.png', 400, 400)));
    }

    #[Test]
    public function qa_12_mb_fake_png_is_refused(): void
    {
        $this->assertSame(
            __('uploads.too_large', ['size' => 5]),
            $this->errorFor(SafeUpload::document(), $this->sized('qa-12mb.png', 12 * 1024, self::PNG_MAGIC))
        );
    }

    #[Test]
    public function a_picture_that_does_not_open_as_one_is_refused(): void
    {
        // Small enough to pass the size check: only the content is wrong.
        $fake = fn (string $name, string $head) => $this->sized($name, 100, $head);

        $this->assertSame(__('uploads.invalid_image'), $this->errorFor(SafeUpload::document(), $fake('qa.png', self::PNG_MAGIC)));
        $this->assertSame(__('uploads.invalid_image'), $this->errorFor(SafeUpload::image(), $fake('logo.jpg', "\xFF\xD8\xFF")));
        $this->assertSame(__('uploads.invalid_image'), $this->errorFor(SafeUpload::document(), $this->file('scan.jpg', 'just text')));
    }

    private function errorFor(SafeUpload $rule, mixed $value): ?string
    {
        return Validator::make(['file' => $value], ['file' => [$rule]])->errors()->first('file') ?: null;
    }
}
