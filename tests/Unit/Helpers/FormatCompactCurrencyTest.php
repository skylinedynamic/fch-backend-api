<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money for a dashboard card: whole dollars under a thousand, then thousands,
 * millions or billions with at most one decimal - $1.4K, $920K, $37.2M, never
 * "$37249K". Always cut, never rounded up (R3-BUG-034): $1,499 is $1.4K and
 * $999,999 is $999.9K, so a card never shows more than the client has.
 */
class FormatCompactCurrencyTest extends TestCase
{
    /**
     * @return array<string, array{0: int|float|string|null, 1: string}>
     */
    public static function amounts(): array
    {
        return [
            'nothing' => [null, '$0'],
            'zero' => [0, '$0'],
            'dollars' => [950, '$950'],
            'cents are cut, not rounded' => [950.6, '$950'],
            'just under a thousand stays in dollars' => [999.6, '$999'],
            'a thousand' => [1000, '$1K'],
            'a few thousand' => [1500, '$1.5K'],
            'cut, not rounded up (R3-BUG-034)' => [1499, '$1.4K'],
            'tens of thousands' => [12345, '$12.3K'],
            'thousands' => [45000, '$45K'],
            'hundreds of thousands' => [920000, '$920K'],
            'just under a million stays in thousands (R3-BUG-034)' => [999999, '$999.9K'],
            'a million' => [1000000, '$1M'],
            'a few million' => [4326000, '$4.3M'],
            'tens of millions' => [37249000, '$37.2M'],
            'hundreds of millions' => [372490000, '$372.4M'],
            'a round hundred millions keeps its zero' => [370000000, '$370M'],
            'just under a billion stays in millions' => [999999999, '$999.9M'],
            'billions' => [1250000000, '$1.2B'],
            'a numeric string, as sum() can return' => ['4326000.00', '$4.3M'],
            'negative' => [-4326000, '-$4.3M'],
            'negative thousands' => [-920000, '-$920K'],
            'negative is cut towards zero too' => [-1499, '-$1.4K'],
        ];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function it_formats_an_amount_for_a_card(int|float|string|null $amount, string $expected): void
    {
        $this->assertSame($expected, formatCompactCurrency($amount));
    }
}
