<?php

namespace Tests\Unit\EmbeddedForm;

use App\Support\EmbedForm\EmbedSnippet;
use App\Support\EmbedForm\InvalidEmbedSnippet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The embed code an admin pastes for the website's /apply page. Only the code
 * the builder produces is accepted: one iframe on
 * /form/{key} and, for automatic height, the embed script - nothing else.
 */
#[CoversClass(EmbedSnippet::class)]
class EmbedSnippetTest extends TestCase
{
    private const FORM = 'https://fourcornerfunding.com/form/share_pub_abc123';

    private const SCRIPT = 'https://fourcornerfunding.com/form/assets/embed.js';

    #[Test]
    public function the_builders_code_gives_its_form_address_key_and_script(): void
    {
        $snippet = EmbedSnippet::parse(
            '<iframe src="'.self::FORM.'?header=0&amp;bg=white&amp;color=2d9a96" data-fch-embed width="100%" height="800" frameborder="0" style="border:none;border-radius:8px;" title="Apply"></iframe>'
            .'
'.'<script src="'.self::SCRIPT.'" async></script>'
        );

        $this->assertSame('fourcornerfunding.com', $snippet->host());
        $this->assertSame('share_pub_abc123', $snippet->key());
        $this->assertSame(self::SCRIPT, $snippet->scriptUrl());
    }

    #[Test]
    public function a_fixed_height_code_has_no_script(): void
    {
        $snippet = EmbedSnippet::parse('<iframe src="'.self::FORM.'" height="900"></iframe>');

        $this->assertSame('share_pub_abc123', $snippet->key());
        $this->assertNull($snippet->scriptUrl());
    }

    public static function refused(): array
    {
        return [
            'empty' => ['   '],
            'no iframe' => ['<div>hello</div>'],
            'two iframes' => ['<iframe src="'.self::FORM.'"></iframe><iframe src="'.self::FORM.'"></iframe>'],
            'another tag' => ['<iframe src="'.self::FORM.'"></iframe><img src="x">'],
            'an event handler' => ['<iframe src="'.self::FORM.'" onload="alert(1)"></iframe>'],
            'a javascript link' => ['<iframe src="javascript:alert(1)"></iframe>'],
            'not a form path' => ['<iframe src="https://fourcornerfunding.com/login"></iframe>'],
            'an inline script' => ['<iframe src="'.self::FORM.'"></iframe><script>alert(1)</script>'],
            'two scripts' => ['<iframe src="'.self::FORM.'"></iframe><script src="'.self::SCRIPT.'"></script><script src="'.self::SCRIPT.'"></script>'],
            'text around it' => ['hello <iframe src="'.self::FORM.'"></iframe>'],
        ];
    }

    #[Test]
    #[DataProvider('refused')]
    public function anything_else_is_refused(string $code): void
    {
        $this->expectException(InvalidEmbedSnippet::class);

        EmbedSnippet::parse($code);
    }
}
