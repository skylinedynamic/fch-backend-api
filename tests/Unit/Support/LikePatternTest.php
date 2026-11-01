<?php

namespace Tests\Unit\Support;

use App\Support\Database\LikePattern;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BUG-002: a search term went into LIKE as typed, so % and _ acted as
 * wildcards. LikePattern makes them ordinary characters, using ! as the
 * escape character (declared with ESCAPE '!', the same on MySQL and SQLite).
 */
class LikePatternTest extends TestCase
{
    #[Test]
    public function wildcards_and_the_escape_character_itself_are_escaped(): void
    {
        $this->assertSame('!%', LikePattern::escape('%'));
        $this->assertSame('a!_b', LikePattern::escape('a_b'));
        $this->assertSame('hey!!', LikePattern::escape('hey!'));
        $this->assertSame('plain text', LikePattern::escape('plain text'));
    }

    #[Test]
    public function contains_wraps_the_escaped_text_in_wildcards(): void
    {
        $this->assertSame('%50!%%', LikePattern::contains('50%'));
    }
}
