<?php

namespace Tests\Unit\Rules;

use App\Rules\MoneyAmount;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R3-BUG-022: an amount of money is a plain number - digits with up to two
 * decimals - not anything PHP's is_numeric() takes ("5e4", ".5", " 50000").
 * A sign is left to min:0.
 */
#[CoversClass(MoneyAmount::class)]
class MoneyAmountTest extends TestCase
{
    private function errorFor(mixed $value): ?string
    {
        return Validator::make(['amount' => $value], ['amount' => [new MoneyAmount]])->errors()->first('amount') ?: null;
    }

    /** @return iterable<string, array{mixed}> */
    public static function amounts(): iterable
    {
        yield 'whole' => ['50000'];
        yield 'one decimal' => ['50000.5'];
        yield 'two decimals' => ['50000.50'];
        yield 'zero' => ['0'];
        yield 'a JSON integer' => [50000];
        yield 'a JSON decimal' => [50000.5];
        yield 'negative (min:0 decides)' => ['-100'];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function a_plain_amount_passes(mixed $value): void
    {
        $this->assertNull($this->errorFor($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function notAmounts(): iterable
    {
        yield 'QA: scientific notation' => ['5e4'];
        yield 'upper-case exponent' => ['1E6'];
        yield 'no leading digit' => ['.5'];
        yield 'trailing dot' => ['50000.'];
        yield 'three decimals' => ['50000.125'];
        yield 'leading space' => [' 50000'];
        yield 'thousands comma' => ['50,000'];
        yield 'a plus sign' => ['+50000'];
        yield 'hex' => ['0x1A'];
        yield 'a huge JSON float' => [1.0E+25];
    }

    #[Test]
    #[DataProvider('notAmounts')]
    public function anything_else_is_refused(mixed $value): void
    {
        $this->assertSame('Enter the amount as a plain number, e.g. 50000 or 50000.50.', $this->errorFor($value));
    }
}
