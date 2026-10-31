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
}
