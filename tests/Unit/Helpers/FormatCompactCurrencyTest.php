<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money for a dashboard card: whole dollars under a thousand, then three
 * significant figures in thousands, millions or billions - $920K, $37.2M,
 * never "$37249K" or "$1000K".
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
            'cents are rounded' => [950.6, '$951'],
            'rounds up into thousands' => [999.6, '$1K'],
            'a thousand' => [1000, '$1K'],
            'a few thousand' => [1500, '$1.5K'],
            'tens of thousands' => [12345, '$12.3K'],
            'thousands' => [45000, '$45K'],
            'hundreds of thousands' => [920000, '$920K'],
            'rounds up into millions' => [999999, '$1M'],
            'a million' => [1000000, '$1M'],
            'a few million' => [4326000, '$4.33M'],
            'tens of millions' => [37249000, '$37.2M'],
            'hundreds of millions' => [372490000, '$372M'],
            'a round hundred millions keeps its zero' => [370000000, '$370M'],
            'rounds up into billions' => [999999999, '$1B'],
            'billions' => [1250000000, '$1.25B'],
            'a numeric string, as sum() can return' => ['4326000.00', '$4.33M'],
            'negative' => [-4326000, '-$4.33M'],
            'negative thousands' => [-920000, '-$920K'],
        ];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function it_formats_an_amount_for_a_card(int|float|string|null $amount, string $expected): void
    {
        $this->assertSame($expected, formatCompactCurrency($amount));
    }
}
