<?php

namespace Tests\Unit\EmbeddedForm;

use App\Support\EmbedForm\FramingPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The address of the page a form was framed on comes from the browser and is
 * shown to staff as a link, so only a plain web address is ever kept.
 */
#[CoversClass(FramingPage::class)]
class FramingPageTest extends TestCase
{
    #[Test]
    #[DataProvider('webAddresses')]
    public function a_web_address_is_kept_without_its_query_or_fragment(string $value, string $kept): void
    {
        $this->assertSame($kept, FramingPage::clean($value));
    }

    public static function webAddresses(): array
    {
        return [
            'a page' => ['https://example.test/apply', 'https://example.test/apply'],
            'a site only' => ['https://partner.test/', 'https://partner.test/'],
            'no path' => ['https://partner.test', 'https://partner.test/'],
            'a port' => ['http://localhost:3100/apply', 'http://localhost:3100/apply'],
            'a query can hold personal data' => ['https://example.test/apply?email=a%40b.test&utm=x', 'https://example.test/apply'],
            'a fragment' => ['https://example.test/apply#application', 'https://example.test/apply'],
            'credentials' => ['https://user:secret@example.test/apply', 'https://example.test/apply'],
        ];
    }

    #[Test]
    #[DataProvider('notWebAddresses')]
    public function anything_else_is_dropped(mixed $value): void
    {
        $this->assertNull(FramingPage::clean($value));
    }

    public static function notWebAddresses(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'a script link' => ['javascript:alert(1)'],
            'a data link' => ['data:text/html,<script>alert(1)</script>'],
            'a file' => ['file:///etc/passwd'],
            'no host' => ['https://'],
            'plain text' => ['not a url'],
            'a page opened from a file' => ['null'],
            'an array' => [['https://example.test/']],
            'longer than the column' => ['https://example.test/' . str_repeat('a', 500)],
        ];
    }
}
